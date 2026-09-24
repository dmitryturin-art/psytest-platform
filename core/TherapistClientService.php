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
        if (!$this->exists($id)) {
            return false;
        }

        $this->db->update(
            'therapist_clients',
            ['label' => $label, 'note' => $note, 'email' => $email],
            'id = ?',
            [$id],
        );

        return true;
    }

    /** @return list<array<string, mixed>> */
    public function listForOwner(int $limit = 100): array
    {
        return $this->db->select(
            "SELECT clients.id, clients.label, clients.note, clients.created_at, clients.updated_at,
                    COUNT(invites.id) AS assignment_count,
                    SUM(CASE WHEN sessions.status = 'completed' THEN 1 ELSE 0 END) AS completed_count
             FROM therapist_clients AS clients
             LEFT JOIN test_invites AS invites ON invites.client_id = clients.id
             LEFT JOIN test_sessions AS sessions ON sessions.id = invites.claimed_session_id
             GROUP BY clients.id, clients.label, clients.note, clients.created_at, clients.updated_at
             ORDER BY clients.created_at DESC
             LIMIT " . max(1, min($limit, 200)),
        );
    }

    /**
     * Full client card: the person, their assignments and completed history.
     *
     * @return array{client: array<string, mixed>, assignments: list<array<string, mixed>>, history: list<array<string, mixed>>}|null
     */
    public function findForOwner(string $id): ?array
    {
        $client = $this->db->selectOne(
            'SELECT id, label, note, email, created_at, updated_at FROM therapist_clients WHERE id = :id',
            ['id' => $id],
        );
        if ($client === null) {
            return null;
        }

        $assignments = TestInviteService::withDisplayStatus($this->db->select(
            'SELECT invites.id, invites.owner_note, invites.status, invites.created_at, invites.expires_at,
                    invites.claimed_at, invites.claimed_session_id,
                    tests.name AS test_name, sessions.status AS session_status, sessions.completed_at
             FROM test_invites AS invites
             INNER JOIN tests ON tests.id = invites.test_id
             LEFT JOIN test_sessions AS sessions ON sessions.id = invites.claimed_session_id
             WHERE invites.client_id = :client_id
             ORDER BY invites.created_at DESC',
            ['client_id' => $id],
        ));

        $history = array_values(array_filter(
            $assignments,
            static fn (array $assignment): bool => $assignment['display_status'] === 'completed',
        ));
        usort(
            $history,
            static fn (array $a, array $b): int => strcmp((string) $b['completed_at'], (string) $a['completed_at']),
        );

        return ['client' => $client, 'assignments' => $assignments, 'history' => $history];
    }

    /**
     * Removes the card with every clinical artifact made under it.
     *
     * One transaction covers the sessions and the card itself, so a partially
     * deleted client cannot survive a failure; the invitations (and their
     * owner notes) go away with the card through the foreign key.
     */
    public function delete(string $id): bool
    {
        if (!$this->exists($id)) {
            return false;
        }

        $sessions = $this->db->select(
            'SELECT claimed_session_id FROM test_invites WHERE client_id = :client_id AND claimed_session_id IS NOT NULL',
            ['client_id' => $id],
        );

        $this->db->beginTransaction();
        try {
            foreach ($sessions as $session) {
                $this->lifecycle->deleteSessionAndArtifacts((string) $session['claimed_session_id']);
            }
            $deleted = $this->db->delete('therapist_clients', 'id = ?', [$id]);
            if ($deleted === 0) {
                $this->db->rollback();
                // The rows are back; their documents must be too.
                $this->lifecycle->discardPendingArtifacts();

                return false;
            }

            // Like the case audit event, this proves that an owner action
            // happened without keeping the label, the card ID or any answer.
            $this->writeOwnerAuditEvent('therapist_client_deleted');
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
