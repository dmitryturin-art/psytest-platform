<?php

declare(strict_types=1);

namespace PsyTest\Core;

use Ramsey\Uuid\Uuid;

/**
 * Client cards of the single owner: a label, an optional note, an optional
 * email and the assignments (invitations) made for that person.
 *
 * The only contact the card may hold is an email, and only because the
 * specialist typed it in there: it exists for exactly one short letter —
 * "your report is ready" — which is never sent automatically (D-054,
 * PRODUCT_RULES §4, §11). No phone and no messenger account are stored.
 *
 * The label, the note and the email stay inside the dashboard: none of them
 * reaches the respondent page, an invitation URL, an AI context or the
 * activity log, and all three are deleted together with the card.
 */
final class TherapistClientService
{
    public const LABEL_MAX_LENGTH = 120;
    public const NOTE_MAX_LENGTH = 1000;
    /** Предел адреса по RFC 5321; та же длина стоит в колонке. */
    public const EMAIL_MAX_LENGTH = 254;

    public function __construct(
        private readonly Database $db,
        private readonly SessionLifecycleService $lifecycle,
    ) {
    }

    /**
     * Проверка полей карточки до записи: те же пределы, что в create()/update().
     *
     * Пустой email допустим и означает «уведомлять некуда»: контакт клиента
     * остаётся необязательным (D-054).
     */
    public static function isValidInput(mixed $label, mixed $note, mixed $email = ''): bool
    {
        if (!is_string($label) || !is_string($note) || !is_string($email)) {
            return false;
        }
        $email = trim($email);
        $emailIsValid = $email === ''
            || (mb_strlen($email) <= self::EMAIL_MAX_LENGTH && Security::isValidEmail($email));

        return trim($label) !== ''
            && mb_strlen(trim($label)) <= self::LABEL_MAX_LENGTH
            && mb_strlen(trim($note)) <= self::NOTE_MAX_LENGTH
            && $emailIsValid;
    }

    public function create(string $label, string $note, string $email = ''): string
    {
        $label = $this->normaliseLabel($label);
        $email = $this->normaliseEmail($email);
        $id = Uuid::uuid4()->toString();
        $this->db->insert('therapist_clients', [
            'id' => $id,
            'label' => $label,
            'note' => $this->normaliseNote($note),
            'email' => $email,
        ]);

        return $id;
    }

    public function update(string $id, string $label, string $note, string $email = ''): bool
    {
        $label = $this->normaliseLabel($label);
        $note = $this->normaliseNote($note);
        $email = $this->normaliseEmail($email);
        // Карточка в корзине доступна только для чтения (07.K11).
        if (!$this->isActive($id)) {
            return false;
        }

        $this->db->update(
            'therapist_clients',
            ['label' => $label, 'note' => $note, 'email' => $email],
            'id = ? AND trashed_at IS NULL',
            [$id],
        );

        return true;
    }

    /**
     * Список карточек. Корзина в счётчики не входит: кейс в корзине уже
     * «удалён» с точки зрения владельца (07.K8).
     *
     * @param string $search Часть подписи карточки (без учёта регистра).
     * @param string|null $testSlug Только клиенты, у которых есть назначение этой методики.
     * @param bool $trashed true — список корзины; по умолчанию карточки из корзины скрыты (07.K11).
     * @return list<array<string, mixed>>
     */
    public function listForOwner(int $limit = 100, string $search = '', ?string $testSlug = null, bool $trashed = false): array
    {
        $where = [$trashed ? 'clients.trashed_at IS NOT NULL' : 'clients.trashed_at IS NULL'];
        $params = [];
        $search = trim($search);
        if ($search !== '') {
            $where[] = "clients.label LIKE :search ESCAPE '!'";
            $params['search'] = '%' . InviteFilter::escapeLike($search) . '%';
        }
        if ($testSlug !== null && $testSlug !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM test_invites AS own
                                INNER JOIN tests AS own_tests ON own_tests.id = own.test_id
                                WHERE own.client_id = clients.id
                                  AND (own.trashed_at IS NULL OR clients.trashed_at IS NOT NULL)
                                  AND own_tests.slug = :test_slug)';
            $params['test_slug'] = $testSlug;
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $rows = $this->db->select(
            "SELECT clients.id, clients.label, clients.note, clients.created_at, clients.updated_at, clients.trashed_at,
                    COUNT(invites.id) AS assignment_count,
                    SUM(CASE WHEN sessions.status = 'completed' THEN 1 ELSE 0 END) AS completed_count
             FROM therapist_clients AS clients
             LEFT JOIN test_invites AS invites ON invites.client_id = clients.id
                 AND (invites.trashed_at IS NULL OR clients.trashed_at IS NOT NULL)
             LEFT JOIN test_sessions AS sessions ON sessions.id = invites.claimed_session_id
             {$whereSql}
             GROUP BY clients.id, clients.label, clients.note, clients.created_at, clients.updated_at, clients.trashed_at
             ORDER BY " . ($trashed ? 'clients.trashed_at DESC, ' : '') . "clients.created_at DESC
             LIMIT " . max(1, min($limit, 200)),
            $params,
        );

        return array_map(static function (array $row): array {
            $row['purge_at'] = self::purgeAt($row['trashed_at'] ?? null);

            return $row;
        }, $rows);
    }

    /**
     * Сколько карточек в рабочем списке и в корзине (вкладки «Рабочие» и «Корзина»).
     *
     * @return array{active: int, trash: int}
     */
    public function countsForOwner(): array
    {
        $row = $this->db->selectOne(
            'SELECT SUM(trashed_at IS NULL) AS active, SUM(trashed_at IS NOT NULL) AS trash FROM therapist_clients',
        ) ?? [];

        return ['active' => (int) ($row['active'] ?? 0), 'trash' => (int) ($row['trash'] ?? 0)];
    }

    /** Дата окончательного удаления карточки из корзины или NULL, если карточка не в корзине. */
    public static function purgeAt(mixed $trashedAt): ?string
    {
        return $trashedAt === null || $trashedAt === ''
            ? null
            : (new \DateTimeImmutable((string) $trashedAt))
                ->modify('+' . TestInviteService::TRASH_RETENTION_DAYS . ' days')
                ->format('Y-m-d H:i:s');
    }

    /**
     * Full client card: the person, their assignments and completed history.
     *
     * @return array{client: array<string, mixed>, assignments: list<array<string, mixed>>, history: list<array<string, mixed>>}|null
     */
    public function findForOwner(string $id): ?array
    {
        $client = $this->db->selectOne(
            'SELECT id, label, note, email, created_at, updated_at, trashed_at FROM therapist_clients WHERE id = :id',
            ['id' => $id],
        );
        if ($client === null) {
            return null;
        }
        $client['purge_at'] = self::purgeAt($client['trashed_at']);

        $assignments = TestInviteService::withDisplayStatus(TestInviteService::withPurgeDate($this->db->select(
            'SELECT invites.id, invites.owner_note, invites.status, invites.created_at, invites.expires_at,
                    invites.claimed_at, invites.claimed_session_id, invites.client_id,
                    invites.archived_at, invites.trashed_at,
                    tests.name AS test_name, tests.slug AS test_slug, sessions.status AS session_status, sessions.completed_at
             FROM test_invites AS invites
             INNER JOIN tests ON tests.id = invites.test_id
             LEFT JOIN test_sessions AS sessions ON sessions.id = invites.claimed_session_id
             WHERE invites.client_id = :client_id
             ORDER BY invites.created_at DESC',
            ['client_id' => $id],
        )));

        $history = array_values(array_filter(
            $assignments,
            static fn (array $assignment): bool => $assignment['display_status'] === 'completed'
                && $assignment['trashed_at'] === null,
        ));
        usort(
            $history,
            static fn (array $a, array $b): int => strcmp((string) $b['completed_at'], (string) $a['completed_at']),
        );

        return ['client' => $client, 'assignments' => $assignments, 'history' => $history];
    }

    public const TRASH_DONE = 'trashed';
    public const TRASH_UNCHANGED = 'unchanged';
    public const TRASH_MISSING = 'missing';

    public const RESTORE_DONE = 'restored';
    public const RESTORE_UNCHANGED = 'unchanged';
    public const RESTORE_MISSING = 'missing';

    public const PURGE_DONE = 'purged';
    public const PURGE_REFUSED = 'refused';
    public const PURGE_MISSING = 'missing';

    /**
     * Отправляет карточку в корзину вместе с её назначениями (07.K11).
     *
     * Метка карточки и метка назначений — одна и та же секунда: по ней
     * восстановление отличает назначения, которые ушли с карточкой, от тех,
     * что владелец убрал в корзину раньше вручную (их метка другая, и они
     * остаются в корзине). Ожидающая ссылка, которая ещё может открыться,
     * сначала отзывается: из корзины клиент не должен начать тест. Отзыв
     * восстановлением не отменяется, ссылку нужно будет выдать заново.
     * Данные клиента не трогаются — ни ответы, ни разборы, ни файлы.
     *
     * @return self::TRASH_*
     */
    public function trash(string $id): string
    {
        $stamp = date('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $changed = $this->db->execute(
                'UPDATE therapist_clients SET trashed_at = :stamp WHERE id = :id AND trashed_at IS NULL',
                ['stamp' => $stamp, 'id' => $id],
            )->rowCount();
            if ($changed !== 1) {
                $this->db->rollback();

                return $this->exists($id) ? self::TRASH_UNCHANGED : self::TRASH_MISSING;
            }

            $this->db->execute(
                "UPDATE test_invites SET status = 'revoked', revoked_at = :stamp
                 WHERE client_id = :id AND status = 'pending' AND expires_at > NOW()",
                ['stamp' => $stamp, 'id' => $id],
            );
            $this->db->execute(
                'UPDATE test_invites SET trashed_at = :stamp WHERE client_id = :id AND trashed_at IS NULL',
                ['stamp' => $stamp, 'id' => $id],
            );
            $this->writeOwnerAuditEvent('client_trashed');
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }

            throw $exception;
        }

        return self::TRASH_DONE;
    }

    /**
     * Возвращает карточку из корзины и назначения, которые ушли вместе с ней (07.K11).
     *
     * Назначения, убранные в корзину вручную до этого, остаются в корзине.
     *
     * @return self::RESTORE_*
     */
    public function restore(string $id): string
    {
        $this->db->beginTransaction();
        try {
            $card = $this->db->selectOne(
                'SELECT trashed_at FROM therapist_clients WHERE id = :id FOR UPDATE',
                ['id' => $id],
            );
            if ($card === null) {
                $this->db->rollback();

                return self::RESTORE_MISSING;
            }
            if ($card['trashed_at'] === null) {
                $this->db->rollback();

                return self::RESTORE_UNCHANGED;
            }

            $this->db->execute(
                'UPDATE test_invites SET trashed_at = NULL WHERE client_id = :id AND trashed_at = :stamp',
                ['id' => $id, 'stamp' => $card['trashed_at']],
            );
            $this->db->execute('UPDATE therapist_clients SET trashed_at = NULL WHERE id = :id', ['id' => $id]);
            $this->writeOwnerAuditEvent('client_restored');
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }

            throw $exception;
        }

        return self::RESTORE_DONE;
    }

    /**
     * Сколько назначений ушло в корзину вместе с карточкой (для сообщений кабинета).
     *
     * Для карточки вне корзины — 0.
     */
    public function assignmentsWithCard(string $id): int
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(invites.id) AS total
             FROM therapist_clients AS clients
             INNER JOIN test_invites AS invites ON invites.client_id = clients.id AND invites.trashed_at = clients.trashed_at
             WHERE clients.id = :id',
            ['id' => $id],
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Окончательно удаляет карточку из корзины — «Удалить сейчас» (07.K11).
     *
     * Только из корзины: рабочую карточку отказывается удалить сама строка
     * `DELETE`, так что ни гонка, ни подделанная форма её не заденут.
     *
     * @return self::PURGE_*
     */
    public function purgeTrashed(string $id): string
    {
        $card = $this->db->selectOne('SELECT trashed_at FROM therapist_clients WHERE id = :id', ['id' => $id]);
        if ($card === null) {
            return self::PURGE_MISSING;
        }
        if ($card['trashed_at'] === null) {
            return self::PURGE_REFUSED;
        }

        return $this->purge($id) ? self::PURGE_DONE : self::PURGE_MISSING;
    }

    /**
     * Стирает карточки, пролежавшие в корзине дольше порога (ежедневная очистка).
     *
     * Сбой на одной карточке не останавливает остальные: она остаётся в
     * корзине до следующего запуска.
     *
     * @return array{clients: int, failed: int}
     */
    public function purgeTrash(\DateTimeImmutable $olderThan): array
    {
        $rows = $this->db->select(
            'SELECT id FROM therapist_clients WHERE trashed_at IS NOT NULL AND trashed_at < :older_than',
            ['older_than' => $olderThan->format('Y-m-d H:i:s')],
        );

        $result = ['clients' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            try {
                if ($this->purge((string) $row['id'])) {
                    ++$result['clients'];
                }
            } catch (\Throwable) {
                ++$result['failed'];
            }
        }

        return $result;
    }

    /**
     * Removes a trashed card with every clinical artifact made under it.
     *
     * One transaction covers the sessions and the card itself, so a partially
     * deleted client cannot survive a failure; the invitations (and their
     * owner notes) go away with the card through the foreign key.
     */
    private function purge(string $id): bool
    {
        $sessions = $this->db->select(
            'SELECT claimed_session_id FROM test_invites WHERE client_id = :client_id AND claimed_session_id IS NOT NULL',
            ['client_id' => $id],
        );

        $this->db->beginTransaction();
        try {
            foreach ($sessions as $session) {
                $this->lifecycle->deleteSessionAndArtifacts((string) $session['claimed_session_id']);
            }
            $deleted = $this->db->delete('therapist_clients', 'id = ? AND trashed_at IS NOT NULL', [$id]);
            if ($deleted === 0) {
                $this->db->rollback();
                // The rows are back; their documents must be too.
                $this->lifecycle->discardPendingArtifacts();

                return false;
            }

            // Like the case audit event, this proves that an owner action
            // happened without keeping the label, the card ID or any answer.
            $this->writeOwnerAuditEvent('client_purged');
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->lifecycle->discardPendingArtifacts();

            throw $exception;
        }

        // Only now — after the commit that removed every session of the card —
        // may the generated PDFs go. A failure on the N-th session used to
        // roll the database back with the earlier files already erased (K2).
        $this->lifecycle->flushPendingArtifacts();

        return true;
    }

    /**
     * Есть ли в карточке адрес для уведомления.
     *
     * Возвращается именно факт, а не сам адрес: карточке кейса он не нужен, а
     * лишний раз показывать контакт на соседней странице незачем.
     */
    public function hasEmail(?string $id): bool
    {
        if ($id === null || !Security::isValidUuid($id)) {
            return false;
        }

        return $this->db->selectOne(
            'SELECT id FROM therapist_clients WHERE id = :id AND email IS NOT NULL',
            ['id' => $id],
        ) !== null;
    }

    /** Подпись карточки для сообщения кабинета; NULL, если карточки нет. */
    public function labelOf(string $id): ?string
    {
        $row = $this->db->selectOne('SELECT label FROM therapist_clients WHERE id = :id', ['id' => $id]);

        return $row === null ? null : (string) $row['label'];
    }

    /** Карточка есть и не в корзине: только с такой можно работать (07.K11). */
    public function isActive(string $id): bool
    {
        return $this->db->selectOne(
            'SELECT id FROM therapist_clients WHERE id = :id AND trashed_at IS NULL',
            ['id' => $id],
        ) !== null;
    }

    public function exists(string $id): bool
    {
        return $this->db->selectOne('SELECT id FROM therapist_clients WHERE id = :id', ['id' => $id]) !== null;
    }

    private function normaliseLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > self::LABEL_MAX_LENGTH) {
            throw new \InvalidArgumentException('Client label must be between 1 and 120 characters');
        }

        return $label;
    }

    /**
     * Адрес уведомления или NULL.
     *
     * Пустое поле — осознанный выбор специалиста «письмо не нужно», а не
     * ошибка ввода: оно очищает адрес, и кнопка уведомления гаснет. Регистр
     * приводится к нижнему, чтобы один и тот же ящик не хранился дважды.
     *
     * @throws \InvalidArgumentException адрес непохож на адрес или длиннее колонки.
     */
    private function normaliseEmail(string $email): ?string
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return null;
        }
        if (mb_strlen($email) > self::EMAIL_MAX_LENGTH || !Security::isValidEmail($email)) {
            throw new \InvalidArgumentException('Client email must be a valid address of at most 254 characters');
        }

        return $email;
    }

    private function normaliseNote(string $note): ?string
    {
        $note = trim($note);
        if (mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            throw new \InvalidArgumentException('Client note must not exceed 1000 characters');
        }

        return $note === '' ? null : $note;
    }

    private function writeOwnerAuditEvent(string $action): void
    {
        $this->db->insert('activity_log', [
            'session_id' => null,
            'test_id' => null,
            'action' => $action,
            'details' => json_encode(['actor' => 'owner'], JSON_THROW_ON_ERROR),
        ]);
    }
}
