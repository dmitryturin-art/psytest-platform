<?php

declare(strict_types=1);

namespace PsyTest\Core;

use Ramsey\Uuid\Uuid;

/**
 * Client cards of the single owner: a label, an optional note, and the
 * assignments (invitations) made for that person.
 *
 * The card deliberately stores no contact data at all — no email, phone or
 * messenger account — because the platform never sends anything itself
 * (INVITE_FLOW, PRODUCT_RULES §11). The label is the owner's own note about
 * their client: it stays inside the dashboard, never reaches the respondent
 * page, an invitation URL, an AI context or the activity log.
 */
final class TherapistClientService
{
    public const LABEL_MAX_LENGTH = 120;
    public const NOTE_MAX_LENGTH = 1000;

    public function __construct(
        private readonly Database $db,
        private readonly SessionLifecycleService $lifecycle,
    ) {
    }

    public function create(string $label, string $note): string
    {
        $label = $this->normaliseLabel($label);
        $id = Uuid::uuid4()->toString();
        $this->db->insert('therapist_clients', [
            'id' => $id,
            'label' => $label,
            'note' => $this->normaliseNote($note),
        ]);

        return $id;
    }

    public function update(string $id, string $label, string $note): bool
    {
        $label = $this->normaliseLabel($label);
        $note = $this->normaliseNote($note);
        if (!$this->exists($id)) {
            return false;
        }

        $this->db->update('therapist_clients', ['label' => $label, 'note' => $note], 'id = ?', [$id]);

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
            'SELECT id, label, note, created_at, updated_at FROM therapist_clients WHERE id = :id',
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

                return false;
            }

            // Like the case audit event, this proves that an owner action
            // happened without keeping the label, the card ID or any answer.
            $this->writeOwnerAuditEvent('therapist_client_deleted');
            $this->db->commit();

            return true;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }

            throw $exception;
        }
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
