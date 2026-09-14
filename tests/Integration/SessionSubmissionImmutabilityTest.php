<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Database;
use PsyTest\Core\SessionManager;

#[Group('database')]
final class SessionSubmissionImmutabilityTest extends TestCase
{
    private Database $db;
    private SessionManager $sessions;
    private string $sessionId;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->sessions = new SessionManager($this->db);
        $this->sessionId = $this->sessions->createSession($this->testId('bdi'))['id'];
    }

    protected function tearDown(): void
    {
        $this->db->delete('activity_log', 'session_id = ?', [$this->sessionId]);
        $this->db->delete('test_sessions', 'id = ?', [$this->sessionId]);
    }

    public function testCompletedSessionRejectsRepeatedSubmissionWithoutChangingClinicalData(): void
    {
        $initialAnswers = ['q1' => 1];
        $initialDemographics = ['gender' => 'female'];
        $initialResults = ['score' => 1, 'interpretation' => 'Initial result'];

        self::assertTrue($this->sessions->finalizeSession(
            $this->sessionId,
            $initialAnswers,
            $initialResults,
            $initialDemographics,
        ));

        self::assertFalse($this->sessions->finalizeSession($this->sessionId, ['q1' => 99], ['score' => 99], ['gender' => 'male']));
        self::assertFalse($this->sessions->saveAnswers($this->sessionId, ['q1' => 99]));
        self::assertFalse($this->sessions->saveDemographics($this->sessionId, ['gender' => 'male']));
        self::assertFalse($this->sessions->completeSession($this->sessionId, ['score' => 99]));

        $stored = $this->sessions->getSessionById($this->sessionId);
        self::assertNotNull($stored);
        self::assertSame('completed', $stored['status']);
        self::assertSame($initialAnswers, $stored['answers']);
        self::assertSame($initialDemographics, $stored['demographics']);
        self::assertSame($initialResults, $stored['calculated_results']);
    }

    private function testId(string $slug): int
    {
        return (int) $this->db->selectOne('SELECT id FROM tests WHERE slug = ?', [$slug])['id'];
    }
}
