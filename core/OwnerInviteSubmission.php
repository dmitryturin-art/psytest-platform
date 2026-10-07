<?php

declare(strict_types=1);

namespace PsyTest\Core;

/**
 * Форма «Новое приглашение» в кабинете (07.K6a).
 *
 * Отдельный класс, а не код контроллера, чтобы повтор отправки и создание
 * карточки вместе с приглашением проверялись тестом без HTTP и `exit`.
 *
 * - Повтор: одноразовый ключ формы (`FormOnce`). Второй POST двойного клика
 *   не создаёт второе приглашение, а возвращает итог первого — ту же ссылку.
 * - Новый клиент: пункт «Новый клиент…» (`__new__`) создаёт карточку с теми
 *   же проверками, что страница «Клиенты», и приглашение к ней в одной
 *   транзакции. При любой ошибке не остаётся ни карточки, ни приглашения.
 */
final class OwnerInviteSubmission
{
    public const FORM = 'owner_invite';
    public const NEW_CLIENT = '__new__';
    public const NOTE_MAX_LENGTH = 1000;

    public function __construct(
        private readonly Database $db,
        private readonly TherapistClientService $clients,
        private readonly TestInviteService $invites,
        private readonly FormOnce $once,
        private readonly string $appUrl,
    ) {
    }

    /** Ключ для очередной отрисовки формы. */
    public function issueKey(): string
    {
        return $this->once->issue(self::FORM);
    }

    /**
     * Обработать POST формы и вернуть сообщение для кабинета.
     *
     * `$fixedClientId` — форма «Новое назначение» в карточке клиента (07.K6b):
     * клиент задан адресом страницы, а не полем формы. Ключи у обеих форм
     * общие: это одна и та же операция «создать приглашение».
     *
     * @param array<string, mixed> $post
     * @param list<int> $availableTestIds Активные методики, доступные для приглашения.
     * @return array{type: string, message: string, invite_url?: string}
     */
    public function submit(array $post, array $availableTestIds, ?string $fixedClientId = null): array
    {
        if ($fixedClientId !== null) {
            $post['client_id'] = $fixedClientId;
            $post['new_client_label'] = '';
        }

        $outcome = $this->once->run(
            self::FORM,
            $post['form_key'] ?? null,
            fn (): array => $this->create($post, $availableTestIds, $fixedClientId !== null),
            'Форма устарела или уже была обработана. Приглашение не создано: обновите страницу и отправьте форму ещё раз.',
            'Эта форма уже отправлена. Второе приглашение не создано: обновите страницу и проверьте список приглашений.',
        );

        return self::flash($outcome['result']);
    }

    /**
     * @param array<string, mixed> $post
     * @param list<int> $availableTestIds
     * @return array<string, string>
     */
    private function create(array $post, array $availableTestIds, bool $fromClientCard): array
    {
        $error = $this->validate($post, $availableTestIds);
        if ($error !== null) {
            return ['type' => 'error', 'message' => $fromClientCard
                ? 'Не удалось создать назначение: выберите поддерживаемую методику и сократите заметку до 1000 символов.'
                : $error];
        }

        $testId = (int) $post['test_id'];
        $note = trim((string) $post['owner_note']);
        $clientChoice = (string) ($post['client_id'] ?? '');

        $this->db->beginTransaction();
        try {
            $clientId = match ($clientChoice) {
                '' => null,
                self::NEW_CLIENT => $this->clients->create((string) $post['new_client_label'], ''),
                default => $clientChoice,
            };
            $invite = $this->invites->create($testId, $note, $clientId);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }

            throw $e;
        }

        return [
            'type' => 'success',
            'message' => match (true) {
                $fromClientCard => 'Назначение создано. Скопируйте ссылку сейчас: повторно она в кабинете не показывается.',
                $clientChoice === self::NEW_CLIENT => 'Карточка клиента и одноразовое приглашение созданы. Скопируйте ссылку сейчас: повторно она в кабинете не показывается.',
                default => 'Одноразовое приглашение создано. Скопируйте ссылку сейчас: повторно она в кабинете не показывается.',
            },
            'invite_url' => $this->appUrl . '/invite/' . $invite['token'],
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @param list<int> $availableTestIds
     */
    private function validate(array $post, array $availableTestIds): ?string
    {
        $testId = filter_var($post['test_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $note = $post['owner_note'] ?? '';
        $client = $post['client_id'] ?? '';
        $clientIsValid = $client === ''
            || $client === self::NEW_CLIENT
            || (is_string($client) && Security::isValidUuid($client) && $this->clients->isActive($client));

        if (!is_int($testId) || !in_array($testId, $availableTestIds, true)
            || !$clientIsValid
            || !is_string($note) || mb_strlen(trim($note)) > self::NOTE_MAX_LENGTH) {
            return 'Не удалось создать приглашение: выберите поддерживаемую методику, существующую карточку клиента и сократите заметку до 1000 символов.';
        }
        if ($client === self::NEW_CLIENT
            && !TherapistClientService::isValidInput($post['new_client_label'] ?? null, '')) {
            return 'Не удалось создать приглашение: для нового клиента укажите имя (до '
                . TherapistClientService::LABEL_MAX_LENGTH . ' символов). Карточка не создана.';
        }

        return null;
    }

    /**
     * @param array<string, string> $result
     * @return array{type: string, message: string, invite_url?: string}
     */
    private static function flash(array $result): array
    {
        $flash = ['type' => $result['type'] ?? 'error', 'message' => $result['message'] ?? ''];
        if (isset($result['invite_url'])) {
            $flash['invite_url'] = $result['invite_url'];
        }

        return $flash;
    }
}
