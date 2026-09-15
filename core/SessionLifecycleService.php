<?php

declare(strict_types=1);

namespace PsyTest\Core;

use DateTimeImmutable;

/**
 * Removes a session and its known generated artifacts.
 *
 * This service intentionally has no public-route knowledge. Callers decide
 * whether a session is eligible; this service guarantees that a known PDF is
 * removed once its database row is really gone.
 *
 * Order matters and is the point of this class (debt K2): the file names are
 * read *before* the deletion, because `pair_comparisons` rows cascade away
 * with the session, but the files are unlinked only *after* the commit that
 * removed the rows. Deleting a client card with several sessions used to wipe
 * the PDFs of the first sessions and then roll the database back on the
 * failing one, leaving rows that pointed at missing documents.
 *
 * When this service joins a caller's transaction it cannot know whether that
 * transaction will commit, so it parks the names in `$pendingArtifacts`; the
 * caller flushes them after its own commit and discards them on rollback.
 */
final class SessionLifecycleService
{
    private string $storagePath;

    /** @var list<string> Artifacts of an outer transaction that has not committed yet. */
    private array $pendingArtifacts = [];

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

        // Read the names first: the `pair_comparisons` rows they come from
        // disappear with the session. Nothing is unlinked yet.
        $artifacts = $this->artifactFileNames($sessionId);

        // A caller may already be deleting a whole client card in one
        // transaction; joining it keeps that deletion atomic.
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }
        try {
            // Keep no session-bound technical record after a clinical record
            // is erased. General operational retention is handled separately.
            $this->db->delete('activity_log', 'session_id = ?', [$sessionId]);
            // The owner note of an invitation is clinical context about the
            // client, so it must not outlive the case it describes
            // (PRODUCT_RULES §11). ON DELETE SET NULL would keep the note.
            $this->db->delete('test_invites', 'claimed_session_id = ?', [$sessionId]);
            $this->db->delete('test_sessions', 'id = ?', [$sessionId]);
            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollback();
            }
            throw $exception;
        }

        if ($ownsTransaction) {
            // The rows are gone for good; the documents may follow.
            foreach ($artifacts as $filename) {
                $this->deleteArtifact($filename);
            }
        } else {
            // The outer transaction still decides. Files stay on disk until
            // `flushPendingArtifacts()`; `discardPendingArtifacts()` keeps them.
            foreach ($artifacts as $filename) {
                $this->pendingArtifacts[] = $filename;
            }
        }

        return true;
    }

    /**
     * Removes the artifacts collected inside a caller-owned transaction.
     *
     * Call it after the outer `commit()` and never before: until then the
     * database may still roll back and the documents must survive.
     */
    public function flushPendingArtifacts(): void
    {
        $artifacts = $this->pendingArtifacts;
        $this->pendingArtifacts = [];

        foreach (array_unique($artifacts) as $filename) {
            $this->deleteArtifact($filename);
        }
    }

    /**
     * Forgets the collected artifacts — the outer transaction rolled back, so
     * the rows still reference these documents.
     */
    public function discardPendingArtifacts(): void
    {
        $this->pendingArtifacts = [];
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
