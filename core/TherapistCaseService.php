<?php

declare(strict_types=1);

namespace PsyTest\Core;

/**
 * Explicit lifecycle operations for clinical cases belonging to the owner.
 *
 * This service deliberately accepts a resolved session ID for mutations. A
 * public result token can only be used for the initial owner lookup and never
 * moves through redirects, audit details or list URLs.
 */
final class TherapistCaseService
{
    public function __construct(
        private readonly Database $db,
        private readonly SessionLifecycleService $lifecycle,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function lookupByResultToken(string $token): ?array
    {
        return $this->db->selectOne(
            'SELECT sessions.id, sessions.status, sessions.retention_class, sessions.created_at, sessions.expires_at,
                    tests.name AS test_name, tests.slug AS test_slug
             FROM test_sessions AS sessions
             INNER JOIN tests ON tests.id = sessions.test_id
             WHERE sessions.session_token = :token AND sessions.status <> :deleted_status',
            ['token' => $token, 'deleted_status' => 'deleted'],
        );
    }

    public function assignCompletedSession(string $sessionId): bool
    {
        $this->db->beginTransaction();
        try {
            $updated = $this->db->update(
                'test_sessions',
                ['retention_class' => RetentionPolicy::THERAPIST_CASE],
                'id = ? AND status = ? AND retention_class = ?',
                [$sessionId, 'completed', RetentionPolicy::ANONYMOUS],
            );

            if ($updated === 0) {
                $existing = $this->db->selectOne(
                    'SELECT retention_class FROM test_sessions WHERE id = :id AND status = :status',
                    ['id' => $sessionId, 'status' => 'completed'],
                );
                if (($existing['retention_class'] ?? null) !== RetentionPolicy::THERAPIST_CASE) {
                    $this->db->rollback();

                    return false;
                }
            } else {
                $this->writeOwnerAuditEvent('therapist_case_assigned');
            }

            $this->db->commit();

            return true;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }

            throw $exception;
        }
    }

    public function deleteAssignedCase(string $sessionId): bool
    {
        $case = $this->db->selectOne(
            'SELECT id FROM test_sessions WHERE id = :id AND retention_class = :retention_class',
            ['id' => $sessionId, 'retention_class' => RetentionPolicy::THERAPIST_CASE],
        );
        if ($case === null || !$this->lifecycle->deleteSessionAndArtifacts($sessionId)) {
            return false;
        }

        // This deliberately survives the clinical session deletion. It proves
        // that an owner action happened without retaining a token, session ID,
        // test name, IP address, user agent or answers.
        $this->writeOwnerAuditEvent('therapist_case_deleted');

        return true;
    }

    private function writeOwnerAuditEvent(string $action): void
    {
        $this->db->insert('activity_log', [
            'session_id' => null,
            'test_id' => null,
            'action' => $action,
            'details' => json_encode(['actor' => 'owner'], JSON_THROW_ON_ERROR),
            'ip_address' => null,
            'user_agent' => null,
        ]);
    }
}
