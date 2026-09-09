<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Database;
use PsyTest\Core\RetentionPolicy;
use PsyTest\Core\SessionManager;
use PsyTest\Core\TestInviteService;

#[Group('database')]
final class TestInviteServiceTest extends TestCase
{
    private Database $db;
    private TestInviteService $invites;

    /** @var list<string> */
    private array $inviteIds = [];

    /** @var list<string> */
    private array $sessionIds = [];

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->invites = new TestInviteService($this->db, new SessionManager($this->db));
    }

    protected function tearDown(): void
    {
        foreach ($this->inviteIds as $inviteId) {
            $this->db->delete('test_invites', 'id = ?', [$inviteId]);
        }
        foreach ($this->sessionIds as $sessionId) {
            $this->db->delete('test_sessions', 'id = ?', [$sessionId]);
        }
    }

    public function testAnyActiveTestCanBeInvitedAndClaimCreatesOneTherapistCaseOnly(): void
    {
        $bdi = $this->testId('bdi');
        $hads = $this->testId('hads');
        $first = $this->invites->create($bdi, 'Первая заметка');
        $second = $this->invites->create($hads, '');
        $this->inviteIds = [$first['id'], $second['id']];

        self::assertGreaterThanOrEqual(
            TestInviteService::TTL_DAYS - 1,
            (new \DateTimeImmutable())->diff(new \DateTimeImmutable($first['expires_at']))->days,
        );
        self::assertNull($this->db->selectOne('SELECT id FROM test_invites WHERE token_hash = ?', [$first['token']]));
        self::assertNotSame('', $this->invites->preview($first['token'])['test_name']);

        $claim = $this->invites->claim($first['token']);
        self::assertNotNull($claim);
        $this->sessionIds[] = $claim['session']['id'];
        self::assertSame('bdi', $claim['test']['slug']);
        self::assertSame(RetentionPolicy::THERAPIST_CASE, $claim['session']['retention_class']);
        self::assertNull($claim['session']['partner_token']);
        self::assertNull($this->invites->claim($first['token']), 'One invitation must never create a second session.');

        $invite = $this->db->selectOne('SELECT status, claimed_session_id FROM test_invites WHERE id = ?', [$first['id']]);
        self::assertSame('claimed', $invite['status']);
        self::assertSame($claim['session']['id'], $invite['claimed_session_id']);

        $secondClaim = $this->invites->claim($second['token']);
        self::assertNotNull($secondClaim);
        $this->sessionIds[] = $secondClaim['session']['id'];
        self::assertSame('hads', $secondClaim['test']['slug']);
    }

    public function testRevokedOrExpiredInviteCannotBeClaimed(): void
    {
        $revoked = $this->invites->create($this->testId('bdi'), '');
        $expired = $this->invites->create($this->testId('hads'), '');
        $this->inviteIds = [$revoked['id'], $expired['id']];

        self::assertTrue($this->invites->revoke($revoked['id']));
        self::assertNull($this->invites->preview($revoked['token']));
        self::assertNull($this->invites->claim($revoked['token']));
        $this->db->update('test_invites', ['expires_at' => '2000-01-01 00:00:00'], 'id = ?', [$expired['id']]);
        self::assertNull($this->invites->claim($expired['token']));
    }

    public function testOwnerCanReadBoundAnswersAndResultsWithoutResultToken(): void
    {
        $invite = $this->invites->create($this->testId('bdi'), 'Только для владельца');
        $this->inviteIds[] = $invite['id'];
        $claim = $this->invites->claim($invite['token']);
        self::assertNotNull($claim);
        $this->sessionIds[] = $claim['session']['id'];

        $sessions = new SessionManager($this->db);
        $sessions->saveAnswers($claim['session']['id'], ['q1' => 2]);
        $sessions->completeSession($claim['session']['id'], ['score' => 2]);

        $case = $this->invites->claimedCaseForOwner($claim['session']['id']);
        self::assertNotNull($case);
        self::assertSame(['q1' => 2], $case['answers']);
        self::assertSame(['score' => 2], $case['calculated_results']);
        self::assertSame('Только для владельца', $case['owner_note']);
    }

    private function testId(string $slug): int
    {
        return (int) $this->db->selectOne('SELECT id FROM tests WHERE slug = ?', [$slug])['id'];
    }
}
