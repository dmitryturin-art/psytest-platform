<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Database;
use PsyTest\Core\RetentionPolicy;
use PsyTest\Core\SessionLifecycleService;
use PsyTest\Core\SessionManager;

final class SessionLifecycleServiceTest extends TestCase
{
    private Database $db;
    private SessionManager $sessions;
    private SessionLifecycleService $lifecycle;
    private int $testId;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->sessions = new SessionManager();
        $this->storagePath = sys_get_temp_dir() . '/psytest-lifecycle-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0700, true);
        $this->lifecycle = new SessionLifecycleService($this->db, new RetentionPolicy(180), $this->storagePath);
        $test = $this->db->selectOne("SELECT id FROM tests WHERE slug = 'bdi'");
        $this->testId = (int) $test['id'];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storagePath);
    }

    public function testPurgesOnlyAnonymousSessionsAtThe180DayBoundaryAndRemovesArtifacts(): void
    {
        $expired = $this->sessions->createSession($this->testId);
        $current = $this->sessions->createSession($this->testId);
        $therapist = $this->sessions->createSession($this->testId);
        $pairedExpired = $this->sessions->createSession($this->testId);
        $pairedCurrent = $this->sessions->createSession($this->testId);
        $comparison = $this->sessions->createPairComparison(
            $this->testId,
            $pairedExpired['id'],
            $pairedCurrent['id'],
            ['fixture' => true],
        );

        $this->db->update('test_sessions', ['created_at' => '2026-02-16 12:00:00'], 'id = ?', [$expired['id']]);
        $this->db->update('test_sessions', ['created_at' => '2026-02-16 12:00:00'], 'id = ?', [$pairedExpired['id']]);
        $this->db->update('test_sessions', ['created_at' => '2026-02-17 12:00:01'], 'id = ?', [$current['id']]);
        $this->db->update('test_sessions', ['created_at' => '2026-02-01 12:00:00', 'retention_class' => RetentionPolicy::THERAPIST_CASE], 'id = ?', [$therapist['id']]);

        file_put_contents($this->storagePath . '/result_' . $expired['id'] . '.pdf', 'result');
        file_put_contents($this->storagePath . '/interpretation_' . $expired['id'] . '.pdf', 'interpretation');
        file_put_contents($this->storagePath . '/pair_' . $comparison['id'] . '.pdf', 'pair');

        self::assertSame(2, $this->lifecycle->purgeExpiredAnonymousSessions(new DateTimeImmutable('2026-08-16 12:00:00')));
        self::assertNull($this->db->selectOne('SELECT id FROM test_sessions WHERE id = ?', [$expired['id']]));
        self::assertNull($this->db->selectOne('SELECT id FROM test_sessions WHERE id = ?', [$pairedExpired['id']]));
        self::assertNotNull($this->db->selectOne('SELECT id FROM test_sessions WHERE id = ?', [$current['id']]));
        self::assertNotNull($this->db->selectOne('SELECT id FROM test_sessions WHERE id = ?', [$therapist['id']]));
        self::assertFileDoesNotExist($this->storagePath . '/result_' . $expired['id'] . '.pdf');
        self::assertFileDoesNotExist($this->storagePath . '/interpretation_' . $expired['id'] . '.pdf');
        self::assertFileDoesNotExist($this->storagePath . '/pair_' . $comparison['id'] . '.pdf');
        self::assertNull($this->db->selectOne('SELECT id FROM pair_comparisons WHERE id = ?', [$comparison['id']]));
        self::assertSame(0, (int) $this->db->selectOne('SELECT COUNT(*) AS count FROM activity_log WHERE session_id = ?', [$expired['id']])['count']);
        self::assertSame(0, $this->lifecycle->purgeExpiredAnonymousSessions(new DateTimeImmutable('2026-08-16 12:00:00')));
    }
}
