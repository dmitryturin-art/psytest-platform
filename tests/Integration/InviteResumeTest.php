<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Database;
use PsyTest\Core\FormOnce;
use PsyTest\Core\OwnerCaseResumeLink;
use PsyTest\Core\SessionManager;
use PsyTest\Core\TestInviteService;

/** Продолжение прохождения по той же ссылке и перевыпуск ссылки владельцем (07.K12). */
#[Group('database')]
final class InviteResumeTest extends TestCase
{
    private Database $db;
    private SessionManager $sessions;
    private TestInviteService $invites;

    /** @var list<string> */
    private array $inviteIds = [];
    /** @var list<string> */
    private array $sessionIds = [];

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->sessions = new SessionManager($this->db);
        $this->invites = new TestInviteService($this->db, $this->sessions);
    }

    protected function tearDown(): void
    {
        foreach ($this->inviteIds as $id) {
            $this->db->delete('test_invites', 'id = ?', [$id]);
        }
        foreach ($this->sessionIds as $id) {
            $this->db->delete('test_sessions', 'id = ?', [$id]);
        }
    }

    public function testSameTokenResumesItsOwnSessionWithSavedAnswersAndCreatesNoSecondSession(): void
    {
        [$invite, $sessionId] = $this->openedInvite();
        $this->sessions->mergeAnswers($sessionId, ['1' => '2', '2' => '0']);
        $this->sessions->saveDemographics($sessionId, ['gender' => 'female', 'age' => 30]);

        self::assertNull($this->invites->claim($invite['token']), 'The pending claim path stays one-time.');
        self::assertNull($this->invites->preview($invite['token']));

        $resume = $this->invites->resumable($invite['token']);
        self::assertNotNull($resume);
        self::assertSame($sessionId, $resume['session']['id']);
        self::assertSame(['1' => '2', '2' => '0'], $resume['session']['answers']);
        self::assertEqualsCanonicalizing(['gender' => 'female', 'age' => 30], $resume['session']['demographics']);
        self::assertSame('bdi', $resume['test']['slug']);
        self::assertSame(2, TestInviteService::answeredCount($resume['session']['answers']));

        $sessions = $this->db->selectOne(
            'SELECT COUNT(*) AS n FROM test_sessions WHERE id = ?',
            [$sessionId],
        );
        self::assertSame(1, (int) $sessions['n']);
        $bound = $this->db->selectOne('SELECT COUNT(*) AS n FROM test_invites WHERE claimed_session_id = ?', [$sessionId]);
        self::assertSame(1, (int) $bound['n']);
    }

    public function testUnknownMalformedAndPendingTokensAreNotResumable(): void
    {
        $pending = $this->invites->create($this->testId('bdi'), '');
        $this->inviteIds[] = $pending['id'];

        self::assertNull($this->invites->resumable($pending['token']), 'A never-opened invite is opened, not resumed.');
        self::assertNull($this->invites->resumable(str_repeat('a', 64)));
        self::assertNull($this->invites->resumable('not-a-token'));
    }

    public function testCompletedRevokedTrashedAndDeletedCasesAreNotResumable(): void
    {
        [$completed, $completedSession] = $this->openedInvite();
        $this->sessions->completeSession($completedSession, ['score' => 1]);
        self::assertNull($this->invites->resumable($completed['token']));

        [$trashed] = $this->openedInvite();
        self::assertSame(1, $this->invites->trash([$trashed['id']]));
        self::assertNull($this->invites->resumable($trashed['token']));
        self::assertSame(1, $this->invites->restore([$trashed['id']]));
        self::assertNotNull($this->invites->resumable($trashed['token']));

        [$deleted, $deletedSession] = $this->openedInvite();
        $this->db->update('test_sessions', ['status' => 'deleted'], 'id = ?', [$deletedSession]);
        self::assertNull($this->invites->resumable($deleted['token']));

        [$revoked] = $this->openedInvite();
        $this->db->update('test_invites', ['status' => 'revoked', 'revoked_at' => date('Y-m-d H:i:s')], 'id = ?', [$revoked['id']]);
        self::assertNull($this->invites->resumable($revoked['token']));

        $unopened = $this->invites->create($this->testId('bdi'), '');
        $this->inviteIds[] = $unopened['id'];
        $this->invites->revoke($unopened['id']);
        self::assertNull($this->invites->resumable($unopened['token']));
    }

    public function testResumeWindowIsFourteenDaysFromOpeningAndDoesNotDependOnTheOriginalExpiry(): void
    {
        [$invite] = $this->openedInvite();

        // Срок первого открытия вышел, но открыли недавно — продолжать можно.
        $this->db->update('test_invites', ['expires_at' => '2000-01-01 00:00:00'], 'id = ?', [$invite['id']]);
        self::assertNotNull($this->invites->resumable($invite['token']));

        // Открыли 13 дней назад — ещё можно, 15 дней назад — уже нет.
        $this->db->update('test_invites', ['claimed_at' => date('Y-m-d H:i:s', strtotime('-13 days'))], 'id = ?', [$invite['id']]);
        self::assertNotNull($this->invites->resumable($invite['token']));
        $this->db->update('test_invites', ['claimed_at' => date('Y-m-d H:i:s', strtotime('-15 days'))], 'id = ?', [$invite['id']]);
        self::assertNull($this->invites->resumable($invite['token']));
    }

    public function testExpiredUnopenedInviteIsStillNotOpenable(): void
    {
        $invite = $this->invites->create($this->testId('bdi'), '');
        $this->inviteIds[] = $invite['id'];
        $this->db->update('test_invites', ['expires_at' => '2000-01-01 00:00:00'], 'id = ?', [$invite['id']]);

        self::assertNull($this->invites->preview($invite['token']));
        self::assertNull($this->invites->claim($invite['token']));
        self::assertNull($this->invites->resumable($invite['token']));
    }

    public function testReissueRotatesTheTokenKeepsSessionAndAnswersAndLogsWithoutTheToken(): void
    {
        [$invite, $sessionId] = $this->openedInvite();
        $this->sessions->mergeAnswers($sessionId, ['1' => '1', '2' => '2', '3' => '0']);
        $this->db->update('test_invites', [
            'claimed_at' => date('Y-m-d H:i:s', strtotime('-20 days')),
            'expires_at' => date('Y-m-d H:i:s', strtotime('-6 days')),
        ], 'id = ?', [$invite['id']]);
        self::assertNull($this->invites->resumable($invite['token']), 'The window has passed before the re-issue.');

        $newToken = $this->invites->reissueResumeLink($sessionId);
        self::assertNotNull($newToken);
        self::assertNotSame($invite['token'], $newToken);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $newToken);

        self::assertNull($this->invites->resumable($invite['token']), 'The old link stops working.');
        $resume = $this->invites->resumable($newToken);
        self::assertNotNull($resume, 'The new link renews the resume window.');
        self::assertSame($sessionId, $resume['session']['id']);
        self::assertSame(3, TestInviteService::answeredCount($resume['session']['answers']));

        $log = $this->db->selectOne(
            "SELECT details FROM activity_log WHERE action = 'invite_resume_link_issued' ORDER BY id DESC LIMIT 1",
        );
        self::assertNotNull($log);
        self::assertStringNotContainsString($newToken, (string) $log['details']);
        self::assertStringNotContainsString($sessionId, (string) $log['details']);
    }

    public function testReissueIsRefusedForCompletedTrashedAndUnknownCases(): void
    {
        [$done, $doneSession] = $this->openedInvite();
        $this->sessions->completeSession($doneSession, ['score' => 1]);
        self::assertNull($this->invites->reissueResumeLink($doneSession));

        [$trashed, $trashedSession] = $this->openedInvite();
        $this->invites->trash([$trashed['id']]);
        self::assertNull($this->invites->reissueResumeLink($trashedSession));

        self::assertNull($this->invites->reissueResumeLink('00000000-0000-4000-8000-000000000000'));
        self::assertNull($this->invites->resumable($done['token']));
    }

    public function testFormIssuesOneLinkPerKeyAndKeepsTheTokenOutOfTheMessage(): void
    {
        [, $sessionId] = $this->openedInvite();
        $store = [];
        $form = new OwnerCaseResumeLink($this->invites, new FormOnce($store), 'https://example.test');
        $key = $form->issueKey();

        $first = $form->submit($sessionId, ['form_key' => $key]);
        self::assertSame('success', $first['type']);
        self::assertArrayHasKey('invite_url', $first);
        self::assertStringStartsWith('https://example.test/invite/', $first['invite_url']);
        $token = substr($first['invite_url'], strlen('https://example.test/invite/'));
        self::assertStringNotContainsString($token, $first['message']);
        self::assertStringContainsString('повторно она не показывается', $first['message']);

        $replay = $form->submit($sessionId, ['form_key' => $key]);
        self::assertSame($first['invite_url'], $replay['invite_url'], 'A double click must not rotate the token twice.');
        self::assertNotNull($this->invites->resumable($token));

        $stale = $form->submit($sessionId, ['form_key' => str_repeat('0', 32)]);
        self::assertSame('error', $stale['type']);
        self::assertArrayNotHasKey('invite_url', $stale);
    }

    public function testMergeAnswersKeepsEarlierAnswersAndIgnoresEmptySets(): void
    {
        [, $sessionId] = $this->openedInvite();

        self::assertTrue($this->sessions->mergeAnswers($sessionId, ['1' => '1', '2' => '2']));
        self::assertFalse($this->sessions->mergeAnswers($sessionId, []), 'An empty set must not touch the record.');
        self::assertSame(['1' => '1', '2' => '2'], $this->answersOf($sessionId));

        // Устаревшая вкладка присылает только первый ответ: второй остаётся.
        self::assertTrue($this->sessions->mergeAnswers($sessionId, ['1' => '0']));
        self::assertSame(['1' => '0', '2' => '2'], $this->answersOf($sessionId));

        self::assertTrue($this->sessions->mergeAnswers($sessionId, ['3' => '1']));
        self::assertSame(['1' => '0', '2' => '2', '3' => '1'], $this->answersOf($sessionId));

        $this->sessions->completeSession($sessionId, ['score' => 1]);
        self::assertFalse($this->sessions->mergeAnswers($sessionId, ['4' => '1']), 'A completed session is immutable.');
        self::assertArrayNotHasKey('4', $this->answersOf($sessionId));
    }

    public function testAnsweredCountCollapsesDualScaleKeys(): void
    {
        self::assertSame(0, TestInviteService::answeredCount([]));
        self::assertSame(3, TestInviteService::answeredCount(['1_self' => '5', '1_partner' => '6', '2' => '1', '10_self' => '1']));
    }

    /** @return array<int|string, mixed> */
    private function answersOf(string $sessionId): array
    {
        $row = $this->db->selectOne('SELECT answers FROM test_sessions WHERE id = ?', [$sessionId]);

        return json_decode((string) $row['answers'], true);
    }

    /** @return array{0: array{id: string, token: string, expires_at: string}, 1: string} */
    private function openedInvite(): array
    {
        $invite = $this->invites->create($this->testId('bdi'), '');
        $this->inviteIds[] = $invite['id'];
        $claim = $this->invites->claim($invite['token']);
        self::assertNotNull($claim);
        $this->sessionIds[] = $claim['session']['id'];

        return [$invite, $claim['session']['id']];
    }

    private function testId(string $slug): int
    {
        return (int) $this->db->selectOne('SELECT id FROM tests WHERE slug = ?', [$slug])['id'];
    }
}
