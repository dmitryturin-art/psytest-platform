<?php

declare(strict_types=1);

namespace PsyTest\Core;

use DateTimeImmutable;

/**
 * Removes a session and its known generated artifacts.
 *
 * This service intentionally has no public-route knowledge. Callers decide
 * whether a session is eligible; this service guarantees that a known PDF is
 * removed before its database reference can be cascaded away.
 */
final class SessionLifecycleService
{
    private string $storagePath;

    public function __construct(
        private readonly Database $db,
        private readonly RetentionPolicy $retentionPolicy,
        ?string $storagePath = null,
    ) {
        $this->storagePath = rtrim($storagePath ?? dirname(__DIR__) . '/storage/pdfs', '/');
    }

    public function purgeExpiredAnonymousSessions(DateTimeImmutable $now): int
    {
        $cutoff = $this->retentionPolicy->anonymousCutoff($now)->format('Y-m-d H:i:s');
        $sessions = $this->db->select(
            'SELECT id FROM test_sessions WHERE retention_class = :retention_class AND created_at <= :cutoff',
            ['retention_class' => RetentionPolicy::ANONYMOUS, 'cutoff' => $cutoff],
        );

        $deleted = 0;
        foreach ($sessions as $session) {
            if ($this->deleteSessionAndArtifacts((string) $session['id'])) {
                ++$deleted;
            }
        }

        return $deleted;
    }

    public function deleteSessionAndArtifacts(string $sessionId): bool
    {
        $session = $this->db->selectOne('SELECT id FROM test_sessions WHERE id = :id', ['id' => $sessionId]);
        if ($session === null) {
            return false;
        }

        foreach ($this->artifactFileNames($sessionId) as $filename) {
            $this->deleteArtifact($filename);
        }

        $this->db->beginTransaction();
        try {
            // Keep no session-bound technical record after a clinical record
            // is erased. General operational retention is handled separately.
            $this->db->delete('activity_log', 'session_id = ?', [$sessionId]);
            $this->db->delete('test_sessions', 'id = ?', [$sessionId]);
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            throw $exception;
        }

        return true;
    }

    /** @return list<string> */
    private function artifactFileNames(string $sessionId): array
    {
        $comparisons = $this->db->select(
            'SELECT id FROM pair_comparisons WHERE session_1_id = :id1 OR session_2_id = :id2',
            ['id1' => $sessionId, 'id2' => $sessionId],
        );

        $names = ["result_{$sessionId}.pdf", "interpretation_{$sessionId}.pdf"];
        foreach ($comparisons as $comparison) {
            $names[] = 'pair_' . $comparison['id'] . '.pdf';
        }

        return array_values(array_unique($names));
    }

    private function deleteArtifact(string $filename): void
    {
        if (basename($filename) !== $filename) {
            throw new \LogicException('Artifact filename must not contain a path.');
        }

        $path = $this->storagePath . '/' . $filename;
        if (!file_exists($path)) {
            return;
        }

        if (!is_file($path) || !unlink($path)) {
            throw new \RuntimeException('Could not remove generated report artifact.');
        }
    }
}
