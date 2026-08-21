<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\Database;
use PsyTest\Core\RetentionPolicy;
use PsyTest\Core\SessionLifecycleService;
use PsyTest\Core\SessionManager;
use PsyTest\Core\TherapistCaseService;

final class TherapistCaseServiceTest extends TestCase
{
    private Database $db;
    private SessionManager $sessions;
    private TherapistCaseService $cases;
    private int $testId;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->sessions = new SessionManager($this->db);
        $this->storagePath = sys_get_temp_dir() . '/psytest-therapist-case-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0700, true);
        $this->cases = new TherapistCaseService(
            $this->db,
            new SessionLifecycleService($this->db, new RetentionPolicy(180), $this->storagePath),
        );
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

    public function testOnlyCompletedAnonymousSessionCanBeExplicitlyAssignedAndThenPhysicallyDeleted(): void
    {
        $partial = $this->sessions->createSession($this->testId);
        self::assertFalse($this->cases->assignCompletedSession($partial['id']));

        $session = $this->sessions->createSession($this->testId);
        $this->sessions->completeSession($session['id'], ['fixture' => true]);

        $lookup = $this->cases->lookupByResultToken($session['session_token']);
        self::assertSame($session['id'], $lookup['id']);
        self::assertSame(RetentionPolicy::ANONYMOUS, $lookup['retention_class']);
        self::assertTrue($this->cases->assignCompletedSession($session['id']));
        self::assertTrue($this->cases->assignCompletedSession($session['id']));
        self::assertSame(
            RetentionPolicy::THERAPIST_CASE,
            $this->db->selectOne('SELECT retention_class FROM test_sessions WHERE id = ?', [$session['id']])['retention_class'],
        );

        file_put_contents($this->storagePath . '/result_' . $session['id'] . '.pdf', 'result');
        file_put_contents($this->storagePath . '/interpretation_' . $session['id'] . '.pdf', 'interpretation');

        self::assertTrue($this->cases->deleteAssignedCase($session['id']));
        self::assertFalse($this->cases->deleteAssignedCase($session['id']));
        self::assertNull($this->db->selectOne('SELECT id FROM test_sessions WHERE id = ?', [$session['id']]));
        self::assertFileDoesNotExist($this->storagePath . '/result_' . $session['id'] . '.pdf');
        self::assertFileDoesNotExist($this->storagePath . '/interpretation_' . $session['id'] . '.pdf');

        $audit = $this->db->select('SELECT session_id, test_id, details, ip_address, user_agent FROM activity_log WHERE action = ?', ['therapist_case_deleted']);
        self::assertNotEmpty($audit);
        self::assertNull($audit[0]['session_id']);
        self::assertNull($audit[0]['test_id']);
        self::assertSame(['actor' => 'owner'], json_decode((string) $audit[0]['details'], true, flags: JSON_THROW_ON_ERROR));
        self::assertNull($audit[0]['ip_address']);
        self::assertNull($audit[0]['user_agent']);
    }
}
