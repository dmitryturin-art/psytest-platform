<?php

declare(strict_types=1);

namespace PsyTest\Core;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;

/**
 * Owner-created, single-use invitations to any active supported test.
 *
 * The browser receives a random bearer token once. The database stores only
 * its SHA-256 hash; claim and session binding are one transaction so a second
 * open cannot create a second clinical case.
 */
final class TestInviteService
{
    public const TTL_DAYS = 14;

    public function __construct(
        private readonly Database $db,
        private readonly SessionManager $sessions,
    ) {
    }

    /** @return array{id: string, token: string, expires_at: string} */
    public function create(int $testId, string $ownerNote, ?string $clientId = null): array
    {
        $test = $this->db->selectOne(
            'SELECT id FROM tests WHERE id = :id AND is_active = 1',
            ['id' => $testId],
        );
        if ($test === null) {
            throw new \InvalidArgumentException('Test is unavailable for invitations');
        }
        if ($clientId !== null) {
            $client = $this->db->selectOne('SELECT id FROM therapist_clients WHERE id = :id AND trashed_at IS NULL', ['id' => $clientId]);
            if ($client === null) {
                throw new \InvalidArgumentException('Client card does not exist');
            }
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = new DateTimeImmutable('+' . self::TTL_DAYS . ' days');
        $id = Uuid::uuid4()->toString();
        $this->db->insert('test_invites', [
            'id' => $id,
            'test_id' => $testId,
            'client_id' => $clientId,
            'token_hash' => hash('sha256', $token),
            'owner_note' => $ownerNote === '' ? null : $ownerNote,
            'status' => 'pending',
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);

        return ['id' => $id, 'token' => $token, 'expires_at' => $expiresAt->format('Y-m-d H:i:s')];
    }

    /**
     * Записывает уже пройденную сессию как погашенное приглашение клиента.
     *
     * Ссылки здесь нет и быть не может: сессия состоялась без приглашения, а
     * строка нужна только чтобы кейс появился в карточке клиента и открывался
     * по `/admin/invited-case/`. Поэтому `token_hash` берётся от случайных
     * байтов, сам токен никуда не возвращается, а `expires_at` ставится в
     * прошлое — такое приглашение нельзя ни открыть, ни погасить повторно.
     *
     * Транзакцию открывает вызывающий код: привязка идёт вместе со сменой
     * режима хранения сессии и, при необходимости, созданием карточки.
     */
    public function bindExistingSession(string $sessionId, int $testId, ?string $clientId, string $note): string
    {
        $id = Uuid::uuid4()->toString();
        $this->db->insert('test_invites', [
            'id' => $id,
            'test_id' => $testId,
            'client_id' => $clientId,
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'owner_note' => $note === '' ? null : $note,
            'status' => 'claimed',
            'claimed_session_id' => $sessionId,
            'claimed_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function preview(string $token): ?array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/i', $token) !== 1) {
            return null;
        }

        return $this->db->selectOne(
            "SELECT tests.name AS test_name, tests.slug AS test_slug
             FROM test_invites AS invites
             INNER JOIN tests ON tests.id = invites.test_id
             WHERE invites.token_hash = :token_hash
               AND invites.status = 'pending'
               AND invites.expires_at > NOW()
               AND tests.is_active = 1",
            ['token_hash' => hash('sha256', $token)],
        );
    }

    /**
     * Binds an invitation to exactly one newly created therapist case.
     *
     * @return array{session: array<string, mixed>, test: array<string, mixed>}|null
     */
    public function claim(string $token): ?array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/i', $token) !== 1) {
            return null;
        }

        $tokenHash = hash('sha256', $token);
        $this->db->beginTransaction();
        try {
            $claimed = $this->db->execute(
                "UPDATE test_invites AS invites
                 INNER JOIN tests AS tests ON tests.id = invites.test_id
                 SET invites.status = 'claimed', invites.claimed_at = NOW()
                 WHERE invites.token_hash = :token_hash
                   AND invites.status = 'pending'
                   AND invites.claimed_session_id IS NULL
                   AND invites.expires_at > NOW()
                   AND tests.is_active = 1",
                ['token_hash' => $tokenHash],
            )->rowCount();

            if ($claimed !== 1) {
                $this->db->rollback();

                return null;
            }

            $invite = $this->db->selectOne(
                'SELECT invites.id, tests.id AS test_id, tests.slug, tests.name
                 FROM test_invites AS invites
                 INNER JOIN tests AS tests ON tests.id = invites.test_id
                 WHERE invites.token_hash = :token_hash AND invites.status = :status',
                ['token_hash' => $tokenHash, 'status' => 'claimed'],
            );
            if ($invite === null) {
                throw new \LogicException('Claimed invite disappeared');
            }

            $session = $this->sessions->createSession((int) $invite['test_id'], [
                'retention_class' => RetentionPolicy::THERAPIST_CASE,
            ]);
            $bound = $this->db->update(
                'test_invites',
                ['claimed_session_id' => $session['id']],
                'id = ? AND status = ? AND claimed_session_id IS NULL',
                [$invite['id'], 'claimed'],
            );
            if ($bound !== 1) {
                throw new \LogicException('Could not bind claimed invite');
            }

            $this->db->commit();

            return ['session' => $session, 'test' => $invite];
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }

            throw $exception;
        }
    }

    /** Сколько дней после открытия (и после перевыпуска) ссылка продолжает работать (07.K12). */
    public const RESUME_DAYS = 14;

    /** Общее условие «ссылка ещё ведёт к своему незавершённому прохождению» (07.K12). */
    private const RESUMABLE_WHERE = "invites.status = 'claimed'
               AND invites.revoked_at IS NULL
               AND invites.trashed_at IS NULL
               AND tests.is_active = 1
               AND sessions.status = 'partial'
               AND sessions.expires_at > NOW()
               AND NOW() < GREATEST(DATE_ADD(invites.claimed_at, INTERVAL " . self::RESUME_DAYS . " DAY), invites.expires_at)";

    /**
     * Продолжение прохождения по той же ссылке (07.K12).
     *
     * Ссылка открывает ровно свою сессию: поиск идёт по хэшу токена, а сессия
     * берётся из привязки приглашения. Правило срока: `expires_at` управляет
     * только первым открытием; уже открытое приглашение продолжается, пока не
     * прошло 14 дней от `claimed_at` либо от перевыпуска ссылки (перевыпуск
     * сдвигает `expires_at`), но не дольше срока самой сессии. Завершённая,
     * удалённая и находящаяся в корзине сессия, а также отозванное приглашение
     * ссылку не оживляют. Ничего в базе не меняется.
     *
     * @return array{session: array<string, mixed>, test: array<string, mixed>}|null
     */
    public function resumable(string $token): ?array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/i', $token) !== 1) {
            return null;
        }

        $row = $this->db->selectOne(
            'SELECT invites.claimed_session_id, tests.id AS test_id, tests.slug, tests.name
             FROM test_invites AS invites
             INNER JOIN tests ON tests.id = invites.test_id
             INNER JOIN test_sessions AS sessions ON sessions.id = invites.claimed_session_id
             WHERE invites.token_hash = :token_hash AND ' . self::RESUMABLE_WHERE,
            ['token_hash' => hash('sha256', $token)],
        );
        if ($row === null) {
            return null;
        }
        $session = $this->sessions->getSessionById((string) $row['claimed_session_id']);
        if ($session === null || $session['status'] !== 'partial') {
            return null;
        }

        return ['session' => $session, 'test' => $row];
    }

    /**
     * Сколько вопросов уже отвечено: ответы парных шкал `12_self`/`12_partner` считаются одним вопросом.
     *
     * @param array<int|string, mixed> $answers
     */
    public static function answeredCount(array $answers): int
    {
        $ids = [];
        foreach (array_keys($answers) as $key) {
            if (preg_match('/\A(\d+)/', (string) $key, $m) === 1) {
                $ids[$m[1]] = true;
            }
        }

        return count($ids);
    }

    /**
     * Перевыпуск ссылки для продолжения (07.K12): новый токен вместо потерянного.
     *
     * Сырой токен нигде не хранится, поэтому показать прежнюю ссылку нельзя.
     * Вместо этого токен приглашения заменяется: старая ссылка перестаёт
     * работать, сессия и ответы остаются, окно продолжения отсчитывается
     * заново. Только для незавершённого, не удалённого и не лежащего в корзине
     * кейса. Токен возвращается один раз и в журнал не пишется.
     */
    public function reissueResumeLink(string $sessionId): ?string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->beginTransaction();
        try {
            $changed = $this->db->execute(
                'UPDATE test_invites AS invites
                 INNER JOIN tests ON tests.id = invites.test_id
                 INNER JOIN test_sessions AS sessions ON sessions.id = invites.claimed_session_id
                 SET invites.token_hash = :token_hash,
                     invites.expires_at = DATE_ADD(NOW(), INTERVAL ' . self::RESUME_DAYS . ' DAY)
                 WHERE invites.claimed_session_id = :session_id
                   AND invites.status = \'claimed\'
                   AND invites.revoked_at IS NULL
                   AND invites.trashed_at IS NULL
                   AND tests.is_active = 1
                   AND sessions.status = \'partial\'
                   AND sessions.expires_at > NOW()',
                ['token_hash' => hash('sha256', $token), 'session_id' => $sessionId],
            )->rowCount();
            if ($changed !== 1) {
                $this->db->rollback();

                return null;
            }
            $this->db->insert('activity_log', [
                'session_id' => null,
                'test_id' => null,
                'action' => 'invite_resume_link_issued',
                'details' => json_encode(['actor' => 'owner'], JSON_THROW_ON_ERROR),
            ]);
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }

            throw $exception;
        }

        return $token;
    }

    public function revoke(string $inviteId): bool
    {
        return $this->db->update(
            'test_invites',
            ['status' => 'revoked', 'revoked_at' => date('Y-m-d H:i:s')],
            'id = ? AND status = ?',
            [$inviteId, 'pending'],
        ) === 1;
    }

    public const DELETE_DONE = 'deleted';
    public const DELETE_MISSING = 'missing';
    public const DELETE_REFUSED = 'refused';

    /**
     * Физически удаляет отозванное и никогда не открытое приглашение (07.K6c).
     *
     * Отозванные строки только засоряют список владельца: клиентских данных за
     * ними нет. Условие удаления проверяется в самом `DELETE`, поэтому
     * ожидающее, открытое или завершённое приглашение удалить нельзя даже
     * гонкой. Карточка клиента и сессии не затрагиваются. Повторный вызов для
     * уже удалённой строки — не ошибка, а `DELETE_MISSING`.
     *
     * @return self::DELETE_*
     */
    public function deleteRevoked(string $inviteId): string
    {
        $deleted = $this->db->delete(
            'test_invites',
            'id = ? AND status = ? AND claimed_session_id IS NULL',
            [$inviteId, 'revoked'],
        );
        if ($deleted === 1) {
            return self::DELETE_DONE;
        }

        return $this->db->selectOne('SELECT id FROM test_invites WHERE id = ?', [$inviteId]) === null
            ? self::DELETE_MISSING
            : self::DELETE_REFUSED;
    }

    /**
     * Приглашение и его текущий клиент для проверки перед привязкой (07.K9).
     *
     * @return array{id: string, client_id: ?string, client_label: ?string, claimed_session_id: ?string}|null
     */
    public function clientOfInvite(string $inviteId): ?array
    {
        $row = $this->db->selectOne(
            'SELECT invites.id, invites.client_id, invites.claimed_session_id, clients.label AS client_label
             FROM test_invites AS invites
             LEFT JOIN therapist_clients AS clients ON clients.id = invites.client_id
             WHERE invites.id = :id',
            ['id' => $inviteId],
        );
        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'client_id' => $row['client_id'] === null ? null : (string) $row['client_id'],
            'client_label' => $row['client_label'] === null ? null : (string) $row['client_label'],
            'claimed_session_id' => $row['claimed_session_id'] === null ? null : (string) $row['claimed_session_id'],
        ];
    }

    public const ATTACH_DONE = 'attached';
    public const ATTACH_CHANGED = 'changed';
    public const ATTACH_UNCHANGED = 'unchanged';
    public const ATTACH_MISSING = 'missing';
    public const ATTACH_REFUSED = 'refused';

    /**
     * Привязывает приглашение (а с ним кейс) к карточке клиента или меняет клиента (07.K9).
     *
     * Работает для любой строки: ожидающей, открытой, завершённой, просроченной,
     * отозванной, из архива и из корзины. Токен, сессия, результат, разборы и
     * срок хранения не затрагиваются: меняется только `client_id`.
     *
     * Клиент — существующая карточка (`$clientId`) либо, при `$clientId === null`
     * и непустой подписи, новая карточка с теми же проверками, что на странице
     * «Клиенты»; карточка и привязка создаются одной транзакцией. Повторная
     * привязка к тому же клиенту ничего не пишет. Первая привязка и смена клиента
     * попадают в журнал владельца без имён и идентификаторов.
     *
     * @return self::ATTACH_*
     */
    public function attachClient(string $inviteId, ?string $clientId, string $newClientLabel = ''): string
    {
        $invite = $this->db->selectOne('SELECT id, client_id FROM test_invites WHERE id = :id', ['id' => $inviteId]);
        if ($invite === null) {
            return self::ATTACH_MISSING;
        }
        if ($clientId !== null) {
            if ($this->db->selectOne('SELECT id FROM therapist_clients WHERE id = :id AND trashed_at IS NULL', ['id' => $clientId]) === null) {
                return self::ATTACH_REFUSED;
            }
        } elseif (!TherapistClientService::isValidInput($newClientLabel, '')) {
            return self::ATTACH_REFUSED;
        }
        $previous = $invite['client_id'] === null ? null : (string) $invite['client_id'];
        if ($clientId !== null && $previous === $clientId) {
            return self::ATTACH_UNCHANGED;
        }

        $this->db->beginTransaction();
        try {
            if ($clientId === null) {
                $clientId = Uuid::uuid4()->toString();
                $this->db->insert('therapist_clients', [
                    'id' => $clientId,
                    'label' => trim($newClientLabel),
                    'note' => null,
                    'email' => null,
                ]);
            }
            $this->db->update('test_invites', ['client_id' => $clientId], 'id = ?', [$inviteId]);
            $this->db->insert('activity_log', [
                'session_id' => null,
                'test_id' => null,
                'action' => $previous === null ? 'invite_client_attached' : 'invite_client_changed',
                'details' => json_encode(['actor' => 'owner'], JSON_THROW_ON_ERROR),
            ]);
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }

            throw $exception;
        }

        return $previous === null ? self::ATTACH_DONE : self::ATTACH_CHANGED;
    }

    public const NOTE_SAVED = 'saved';
    public const NOTE_UNCHANGED = 'unchanged';
    public const NOTE_MISSING = 'missing';

    /**
     * Заметка специалиста к кейсу: правка на месте в карточке кейса (04.D3).
     *
     * Меняется только `owner_note` приглашения, через которое открыт кейс.
     * Кейс в корзине только для чтения: для него, как и для несуществующего
     * кейса, ответ — `NOTE_MISSING`. Пустая заметка хранится как NULL, как при
     * создании приглашения. Длину проверяет вызывающий код.
     *
     * @return self::NOTE_*
     */
    public function updateNote(string $sessionId, string $note): string
    {
        $row = $this->db->selectOne(
            'SELECT id, owner_note FROM test_invites WHERE claimed_session_id = :session_id AND trashed_at IS NULL',
            ['session_id' => $sessionId],
        );
        if ($row === null) {
            return self::NOTE_MISSING;
        }

        $value = trim($note) === '' ? null : trim($note);
        if ($row['owner_note'] === $value) {
            return self::NOTE_UNCHANGED;
        }

        $this->db->update('test_invites', ['owner_note' => $value], 'id = ?', [$row['id']]);

        return self::NOTE_SAVED;
    }

    /** Через сколько дней корзина стирается окончательно (07.K8). */
    public const TRASH_RETENTION_DAYS = 30;

    /** @return list<array<string, mixed>> */
    public function recentForOwner(int $limit = 20): array
    {
        return $this->listForOwner(InviteFilter::none(), $limit);
    }

    /**
     * Список приглашений владельца по фильтру (07.K8).
     *
     * Без фильтра по статусу архив и корзина скрыты: они открываются только
     * явным выбором. Строки корзины получают `purge_at` — дату окончательного
     * удаления.
     *
     * @return list<array<string, mixed>>
     */
    public function listForOwner(InviteFilter $filter, int $limit = 100): array
    {
        [$where, $params] = $filter->toSql();
        $invites = $this->db->select(
            "SELECT invites.id, invites.owner_note, invites.status, invites.created_at, invites.expires_at,
                    invites.claimed_at, invites.claimed_session_id, invites.client_id,
                    invites.archived_at, invites.trashed_at,
                    tests.name AS test_name, tests.slug AS test_slug,
                    sessions.status AS session_status, sessions.completed_at,
                    clients.label AS client_label
             FROM test_invites AS invites
             INNER JOIN tests ON tests.id = invites.test_id
             LEFT JOIN test_sessions AS sessions ON sessions.id = invites.claimed_session_id
             LEFT JOIN therapist_clients AS clients ON clients.id = invites.client_id
             WHERE {$where}
             ORDER BY invites.created_at DESC
             LIMIT " . max(1, min($limit, 200)),
            $params,
        );

        return self::withDisplayStatus(self::withPurgeDate($invites));
    }

    /**
     * Добавляет `purge_at` — дату окончательного удаления строки из корзины.
     *
     * @param list<array<string, mixed>> $invites
     * @return list<array<string, mixed>>
     */
    public static function withPurgeDate(array $invites): array
    {
        foreach ($invites as &$invite) {
            $invite['purge_at'] = ($invite['trashed_at'] ?? null) === null
                ? null
                : (new DateTimeImmutable((string) $invite['trashed_at']))
                    ->modify('+' . self::TRASH_RETENTION_DAYS . ' days')
                    ->format('Y-m-d H:i:s');
        }
        unset($invite);

        return $invites;
    }

    /**
     * Сколько приглашений в рабочем списке, архиве и корзине.
     *
     * @return array{active: int, archived: int, trash: int}
     */
    public function countsForOwner(): array
    {
        $row = $this->db->selectOne(
            'SELECT SUM(trashed_at IS NULL AND archived_at IS NULL) AS active,
                    SUM(trashed_at IS NULL AND archived_at IS NOT NULL) AS archived,
                    SUM(trashed_at IS NOT NULL) AS trash
             FROM test_invites',
        ) ?? [];

        return [
            'active' => (int) ($row['active'] ?? 0),
            'archived' => (int) ($row['archived'] ?? 0),
            'trash' => (int) ($row['trash'] ?? 0),
        ];
    }

    /**
     * В архив: только то, что не ждёт открытия по живой ссылке. Ожидающее
     * приглашение сначала отзывается. Повтор по уже заархивированному — 0.
     *
     * @param list<string> $ids
     */
    public function archive(array $ids): int
    {
        return $this->mark($ids, "archived_at = NOW()", 'trashed_at IS NULL AND archived_at IS NULL AND ' . self::NOT_LIVE_PENDING);
    }

    /** @param list<string> $ids */
    public function unarchive(array $ids): int
    {
        return $this->mark($ids, 'archived_at = NULL', 'trashed_at IS NULL AND archived_at IS NOT NULL');
    }

    /**
     * В корзину: мягкое удаление, данные остаются до очистки. Отметка архива
     * снимается, чтобы восстановление возвращало строку в рабочий список.
     *
     * @param list<string> $ids
     */
    public function trash(array $ids): int
    {
        return $this->mark($ids, 'trashed_at = NOW(), archived_at = NULL', 'trashed_at IS NULL AND ' . self::NOT_LIVE_PENDING);
    }

    /** @param list<string> $ids */
    public function restore(array $ids): int
    {
        // Назначение карточки из корзины возвращается только вместе с карточкой (07.K11).
        return $this->mark($ids, 'trashed_at = NULL', 'trashed_at IS NOT NULL AND (client_id IS NULL OR client_id NOT IN (SELECT id FROM therapist_clients WHERE trashed_at IS NOT NULL))');
    }

    /** Ожидающее приглашение с живой ссылкой нельзя ни архивировать, ни убрать в корзину. */
    private const NOT_LIVE_PENDING = "NOT (status = 'pending' AND expires_at > NOW())";

    /**
     * @param list<string> $ids
     * @param string $set Фиксированный фрагмент `SET`, не из пользовательского ввода.
     * @param string $guard Фиксированное условие допустимого исходного состояния.
     */
    private function mark(array $ids, string $set, string $guard): int
    {
        $ids = array_values(array_unique(array_filter($ids, Security::isValidUuid(...))));
        if ($ids === []) {
            return 0;
        }
        $marks = implode(', ', array_fill(0, count($ids), '?'));

        return $this->db->execute(
            "UPDATE test_invites SET {$set} WHERE id IN ({$marks}) AND {$guard}",
            $ids,
        )->rowCount();
    }

    /**
     * Adds the owner-facing state of each invitation row.
     *
     * A claimed invitation without a bound session is legacy data left by an
     * earlier deletion path: its case no longer exists, so it must never be
     * offered as a link to `/admin/invited-case/`.
     *
     * @param list<array<string, mixed>> $invites
     * @return list<array<string, mixed>>
     */
    public static function withDisplayStatus(array $invites): array
    {
        $now = new DateTimeImmutable();
        foreach ($invites as &$invite) {
            $invite['display_status'] = match ($invite['status']) {
                'claimed' => match (true) {
                    $invite['claimed_session_id'] === null, $invite['session_status'] === 'deleted' => 'result_deleted',
                    $invite['session_status'] === 'completed' => 'completed',
                    default => 'opened',
                },
                'revoked' => 'revoked',
                default => new DateTimeImmutable((string) $invite['expires_at']) <= $now ? 'expired' : 'pending',
            };
        }
        unset($invite);

        return $invites;
    }

    /** @return array<string, mixed>|null */
    public function claimedCaseForOwner(string $sessionId): ?array
    {
        $case = $this->db->selectOne(
            "SELECT sessions.id, sessions.status, sessions.created_at, sessions.completed_at,
                    sessions.answers, sessions.calculated_results, sessions.expires_at AS session_expires_at, tests.name AS test_name, tests.slug AS test_slug,
                    invites.owner_note, invites.claimed_at, invites.client_id, clients.label AS client_label,
                    invites.id AS invite_id, invites.archived_at, invites.trashed_at
             FROM test_invites AS invites
             INNER JOIN test_sessions AS sessions ON sessions.id = invites.claimed_session_id
             INNER JOIN tests ON tests.id = sessions.test_id
             LEFT JOIN therapist_clients AS clients ON clients.id = invites.client_id
             WHERE invites.claimed_session_id = :session_id
               AND sessions.status <> 'deleted'",
            ['session_id' => $sessionId],
        );
        if ($case === null) {
            return null;
        }

        $case['purge_at'] = $case['trashed_at'] === null
            ? null
            : (new DateTimeImmutable((string) $case['trashed_at']))
                ->modify('+' . self::TRASH_RETENTION_DAYS . ' days')
                ->format('Y-m-d H:i:s');
        $case['answers'] = json_decode((string) $case['answers'], true, 512, JSON_THROW_ON_ERROR);
        $case['calculated_results'] = json_decode((string) $case['calculated_results'], true, 512, JSON_THROW_ON_ERROR);

        return $case;
    }
}
