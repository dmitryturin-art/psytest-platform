<?php

declare(strict_types=1);

namespace PsyTest\Core;

/**
 * Фильтр списка приглашений в кабинете (07.K8): неизменяемое значение,
 * собранное из `$_GET` со строгой проверкой каждого поля.
 *
 * Неизвестное или повреждённое значение не приводит к ошибке: поле молча
 * сбрасывается к «все». Так ссылка со старым или подставленным параметром
 * открывает обычный список, а не страницу ошибки. В SQL значения попадают
 * только параметрами; из всего фильтра сюда подставляются лишь фиксированные
 * фрагменты условий.
 */
final class InviteFilter
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_TRASH = 'trash';

    /** Значение поля «клиент»: приглашения без карточки клиента. */
    public const CLIENT_NONE = 'none';

    public const QUERY_MAX_LENGTH = 80;

    /** Допустимые значения поля «статус», по порядку в списке выбора. */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        'pending',
        'opened',
        'completed',
        'revoked',
        'expired',
        self::STATUS_ARCHIVED,
        self::STATUS_TRASH,
    ];

    private function __construct(
        public readonly ?string $clientId,
        public readonly bool $withoutClient,
        public readonly ?string $testSlug,
        public readonly string $status,
        public readonly string $query,
    ) {
    }

    /** Пустой фильтр: все рабочие приглашения. */
    public static function none(): self
    {
        return new self(null, false, null, self::STATUS_ACTIVE, '');
    }

    /**
     * @param array<string, mixed> $query Обычно `$_GET`.
     * @param list<string> $knownTestSlugs Методики, которые можно выбрать в фильтре.
     */
    public static function fromQuery(array $query, array $knownTestSlugs): self
    {
        $client = $query['client'] ?? '';
        $clientId = null;
        $withoutClient = false;
        if ($client === self::CLIENT_NONE) {
            $withoutClient = true;
        } elseif (is_string($client) && Security::isValidUuid($client)) {
            $clientId = strtolower($client);
        }

        $test = $query['test'] ?? '';
        $testSlug = is_string($test) && in_array($test, $knownTestSlugs, true) ? $test : null;

        $status = $query['status'] ?? self::STATUS_ACTIVE;
        if (!is_string($status) || !in_array($status, self::STATUSES, true)) {
            $status = self::STATUS_ACTIVE;
        }

        $text = $query['q'] ?? '';
        $text = is_string($text) ? trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '') : '';
        $text = trim(mb_substr($text, 0, self::QUERY_MAX_LENGTH));

        return new self($clientId, $withoutClient, $testSlug, $status, $text);
    }

    public function isDefault(): bool
    {
        return $this->clientId === null
            && !$this->withoutClient
            && $this->testSlug === null
            && $this->status === self::STATUS_ACTIVE
            && $this->query === '';
    }

    /**
     * Параметры адреса: только отличающиеся от значений по умолчанию.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $query = [];
        if ($this->withoutClient) {
            $query['client'] = self::CLIENT_NONE;
        } elseif ($this->clientId !== null) {
            $query['client'] = $this->clientId;
        }
        if ($this->testSlug !== null) {
            $query['test'] = $this->testSlug;
        }
        if ($this->status !== self::STATUS_ACTIVE) {
            $query['status'] = $this->status;
        }
        if ($this->query !== '') {
            $query['q'] = $this->query;
        }

        return $query;
    }

    /** Адрес списка с этим фильтром, например `/admin?status=trash`. */
    public function toUrl(string $path = '/admin'): string
    {
        $query = $this->toQuery();

        return $query === [] ? $path : $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Условие `WHERE` для запроса с таблицами `test_invites AS invites`,
     * `tests`, `test_sessions AS sessions` и `therapist_clients AS clients`.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public function toSql(): array
    {
        $where = [];
        $params = [];

        switch ($this->status) {
            case self::STATUS_TRASH:
                $where[] = 'invites.trashed_at IS NOT NULL';
                break;
            case self::STATUS_ARCHIVED:
                $where[] = 'invites.trashed_at IS NULL AND invites.archived_at IS NOT NULL';
                break;
            default:
                $where[] = 'invites.trashed_at IS NULL AND invites.archived_at IS NULL';
        }

        // Отражает TestInviteService::withDisplayStatus(): статус строки и срок.
        $where[] = match ($this->status) {
            'pending' => "invites.status = 'pending' AND invites.expires_at > NOW()",
            'expired' => "invites.status = 'pending' AND invites.expires_at <= NOW()",
            'revoked' => "invites.status = 'revoked'",
            'completed' => "invites.status = 'claimed' AND sessions.status = 'completed'",
            'opened' => "invites.status = 'claimed' AND invites.claimed_session_id IS NOT NULL"
                . " AND COALESCE(sessions.status, '') NOT IN ('completed', 'deleted')",
            default => '1 = 1',
        };

        if ($this->withoutClient) {
            $where[] = 'invites.client_id IS NULL';
        } elseif ($this->clientId !== null) {
            $where[] = 'invites.client_id = :client_id';
            $params['client_id'] = $this->clientId;
        }
        if ($this->testSlug !== null) {
            $where[] = 'tests.slug = :test_slug';
            $params['test_slug'] = $this->testSlug;
        }
        if ($this->query !== '') {
            $where[] = "(invites.owner_note LIKE :q_note ESCAPE '!' OR clients.label LIKE :q_label ESCAPE '!')";
            $like = '%' . self::escapeLike($this->query) . '%';
            $params['q_note'] = $like;
            $params['q_label'] = $like;
        }

        return [implode(' AND ', $where), $params];
    }

    /** Экранирует `%`, `_` и сам символ экранирования (`!`) для LIKE. */
    public static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
