<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Database;
use PsyTest\Core\RetentionPolicy;
use PsyTest\Core\SessionLifecycleService;
use PsyTest\Core\SessionManager;
use PsyTest\Core\TestInviteService;
use PsyTest\Core\TherapistCaseService;
use PsyTest\Core\TherapistClientService;

#[Group('database')]
final class TherapistCaseServiceTest extends TestCase
{
    private Database $db;
    private SessionManager $sessions;
    private TestInviteService $invites;
    private TherapistClientService $clients;
    private TherapistCaseService $cases;
    private int $testId;
    private string $storagePath;

    /** @var list<string> */
    private array $clientIds = [];

    /** @var list<string> */
    private array $sessionIds = [];

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->sessions = new SessionManager($this->db);
        $this->storagePath = sys_get_temp_dir() . '/psytest-therapist-case-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0700, true);
        $lifecycle = new SessionLifecycleService($this->db, new RetentionPolicy(180), $this->storagePath);
        $this->invites = new TestInviteService($this->db, $this->sessions);
        $this->clients = new TherapistClientService($this->db, $lifecycle);
        $this->cases = new TherapistCaseService($this->db, $lifecycle, $this->invites, $this->clients);
        $test = $this->db->selectOne("SELECT id FROM tests WHERE slug = 'bdi'");
        $this->testId = (int) $test['id'];
    }

    protected function tearDown(): void
    {
        foreach ($this->clientIds as $clientId) {
            $this->db->delete('therapist_clients', 'id = ?', [$clientId]);
        }
        $this->clientIds = [];
        foreach ($this->sessionIds as $sessionId) {
            $this->db->delete('test_sessions', 'id = ?', [$sessionId]);
        }
        $this->sessionIds = [];
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

        $audit = $this->db->select('SELECT session_id, test_id, details FROM activity_log WHERE action = ?', ['therapist_case_deleted']);
        self::assertNotEmpty($audit);
        self::assertNull($audit[0]['session_id']);
        self::assertNull($audit[0]['test_id']);
        self::assertSame(['actor' => 'owner'], json_decode((string) $audit[0]['details'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testAttachingACompletedSessionToAClientCreatesTheCaseExactlyOnce(): void
    {
        $clientId = $this->createClient('Клиент привязки');
        $sessionId = $this->completedSession();

        self::assertTrue($this->cases->attachToClient($sessionId, $clientId, 'Пришёл по общей ссылке'));
        self::assertSame(
            RetentionPolicy::THERAPIST_CASE,
            $this->db->selectOne('SELECT retention_class FROM test_sessions WHERE id = ?', [$sessionId])['retention_class'],
        );

        $case = $this->invites->claimedCaseForOwner($sessionId);
        self::assertNotNull($case);
        self::assertSame($clientId, $case['client_id']);
        self::assertSame('Клиент привязки', $case['client_label']);
        self::assertSame('Пришёл по общей ссылке', $case['owner_note']);

        $invite = $this->db->selectOne(
            'SELECT status, token_hash, expires_at, claimed_at FROM test_invites WHERE claimed_session_id = ?',
            [$sessionId],
        );
        self::assertSame('claimed', $invite['status']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $invite['token_hash']);
        self::assertNotNull($invite['claimed_at']);
        // Ссылка не предназначена для открытия: срок истекает сразу.
        self::assertLessThanOrEqual(time(), strtotime((string) $invite['expires_at']));

        // Карточка кейса показывает его завершённым, как и приглашённый кейс.
        $assignments = $this->clients->findForOwner($clientId)['assignments'];
        self::assertCount(1, $assignments);
        self::assertSame('completed', $assignments[0]['display_status']);

        // Второй раз ту же сессию привязать нельзя: приглашение уже есть.
        self::assertFalse($this->cases->attachToClient($sessionId, $clientId, 'Дубль'));
        self::assertSame(
            1,
            (int) $this->db->selectOne('SELECT COUNT(*) AS total FROM test_invites WHERE claimed_session_id = ?', [$sessionId])['total'],
        );
    }

    public function testAttachingCanCreateTheClientCardInTheSameTransaction(): void
    {
        $sessionId = $this->completedSession();
        self::assertTrue($this->cases->attachToClient($sessionId, null, '', 'Проверка'));

        $case = $this->invites->claimedCaseForOwner($sessionId);
        self::assertNotNull($case);
        self::assertSame('Проверка', $case['client_label']);
        self::assertNull($case['owner_note']);
        $this->clientIds[] = (string) $case['client_id'];
    }

    public function testPartialDeletedAndVisitorAccountSessionsAreNeverAttached(): void
    {
        $clientId = $this->createClient('Клиент отказов');

        $partial = $this->sessions->createSession($this->testId);
        self::assertFalse($this->cases->attachToClient($partial['id'], $clientId, ''));

        $account = $this->completedSession();
        $this->db->update('test_sessions', ['retention_class' => RetentionPolicy::ACCOUNT], 'id = ?', [$account]);
        self::assertFalse($this->cases->attachToClient($account, $clientId, ''));

        $deleted = $this->completedSession();
        $this->db->update('test_sessions', ['status' => 'deleted'], 'id = ?', [$deleted]);
        self::assertFalse($this->cases->attachToClient($deleted, $clientId, ''));

        // Несуществующая карточка тоже не создаёт приглашение.
        self::assertFalse($this->cases->attachToClient($this->completedSession(), '11111111-1111-4111-8111-111111111111', ''));

        self::assertSame(
            0,
            (int) $this->db->selectOne('SELECT COUNT(*) AS total FROM test_invites WHERE client_id = ?', [$clientId])['total'],
        );

        $this->db->delete('test_sessions', 'id = ?', [$partial['id']]);
    }

    public function testDeletingTheClientTakesTheAttachedInvitationAndSessionAway(): void
    {
        $clientId = $this->createClient('Клиент удаления');
        $sessionId = $this->completedSession();
        self::assertTrue($this->cases->attachToClient($sessionId, $clientId, 'Заметка уходит вместе с карточкой'));

        self::assertTrue($this->clients->delete($clientId));
        self::assertNull($this->db->selectOne('SELECT id FROM test_sessions WHERE id = ?', [$sessionId]));
        self::assertNull($this->db->selectOne('SELECT id FROM test_invites WHERE claimed_session_id = ?', [$sessionId]));
        self::assertNull($this->invites->claimedCaseForOwner($sessionId));
    }

    private function createClient(string $label): string
    {
        $clientId = $this->clients->create($label, '');
        $this->clientIds[] = $clientId;

        return $clientId;
    }

    private function completedSession(): string
    {
        $session = $this->sessions->createSession($this->testId);
        $this->sessions->completeSession($session['id'], ['fixture' => true]);
        $this->sessionIds[] = (string) $session['id'];

        return (string) $session['id'];
    }
}
