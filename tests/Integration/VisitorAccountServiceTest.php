<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Database;
use PsyTest\Core\RetentionPolicy;
use PsyTest\Core\SessionLifecycleService;
use PsyTest\Core\SessionManager;
use PsyTest\Core\VisitorAccountService;
use PsyTest\Tests\Support\RecordingMailer;

#[Group('database')]
final class VisitorAccountServiceTest extends TestCase
{
    private Database $db;
    private SessionManager $sessions;
    private SessionLifecycleService $lifecycle;
    private RecordingMailer $mailer;
    private VisitorAccountService $accounts;
    private int $testId;
    private string $storagePath;
    private string $email;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->sessions = new SessionManager($this->db);
        $this->storagePath = sys_get_temp_dir() . '/psytest-account-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0700, true);
        $this->lifecycle = new SessionLifecycleService($this->db, new RetentionPolicy(180), $this->storagePath);
        $this->mailer = new RecordingMailer();
        $this->accounts = new VisitorAccountService(
            $this->db,
            $this->mailer,
            $this->lifecycle,
            'https://example.test',
        );
        $test = $this->db->selectOne("SELECT id FROM tests WHERE slug = 'bdi'");
        $this->testId = (int) $test['id'];
        $this->email = 'visitor-' . bin2hex(random_bytes(6)) . '@example.test';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storagePath);
    }

    public function testLoginLinkWorksOnceAndOnlyWhileItIsFresh(): void
    {
        $this->accounts->requestLogin(' ' . strtoupper($this->email) . ' ');
        $token = $this->mailer->lastLoginToken();
        self::assertIsString($token);
        self::assertSame($this->email, $this->mailer->sent[0]['to'], 'Адрес нормализуется до отправки.');

        $account = $this->accounts->consumeLogin($token);
        self::assertIsArray($account);
        self::assertSame($this->email, $account['email']);

        // Второе открытие той же ссылки не должно давать вход: иначе письмо в
        // чужих руках оставалось бы рабочим ключом.
        self::assertNull($this->accounts->consumeLogin($token));
        self::assertNull($this->accounts->consumeLogin(bin2hex(random_bytes(32))));

        $expiredEmail = 'expired-' . $this->email;
        $this->accounts->requestLogin($expiredEmail);
        $expiredToken = $this->mailer->lastLoginToken();
        self::assertIsString($expiredToken);
        $this->db->update(
            'visitor_login_tokens',
            ['expires_at' => (new DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')],
            'token_hash = ?',
            [hash('sha256', $expiredToken)],
        );
        self::assertNull($this->accounts->consumeLogin($expiredToken));

        $this->deleteAccountFor($this->email);
        $this->db->delete('visitor_login_tokens', 'email = ?', [$expiredEmail]);
    }

    public function testNoMoreThanThreeLinksPerAddressInsideTheWindow(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->accounts->requestLogin($this->email);
        }

        self::assertCount(VisitorAccountService::MAX_LOGIN_REQUESTS_PER_WINDOW, $this->mailer->sent);
        self::assertSame(
            VisitorAccountService::MAX_LOGIN_REQUESTS_PER_WINDOW,
            (int) $this->db->selectOne(
                'SELECT COUNT(*) AS count FROM visitor_login_tokens WHERE email = ?',
                [$this->email],
            )['count'],
        );

        $this->db->delete('visitor_login_tokens', 'email = ?', [$this->email]);
    }

    public function testInvalidAddressNeitherStoresATokenNorSendsMail(): void
    {
        $this->accounts->requestLogin('not-an-email');

        self::assertSame([], $this->mailer->sent);
    }

    public function testAttachRequiresTheRealResultTokenOfACompletedAnonymousSession(): void
    {
        $accountId = $this->loginAccount($this->email);

        $partial = $this->sessions->createSession($this->testId);
        self::assertFalse(
            $this->accounts->attach($accountId, $partial['id'], $partial['session_token']),
            'Незавершённая сессия не сохраняется в кабинет.',
        );

        $session = $this->completedSession();
        $other = $this->completedSession();

        self::assertFalse(
            $this->accounts->attach($accountId, $session['id'], $other['session_token']),
            'Чужой токен не открывает доступ к результату.',
        );

        $therapistCase = $this->completedSession();
        $this->db->update(
            'test_sessions',
            ['retention_class' => RetentionPolicy::THERAPIST_CASE],
            'id = ?',
            [$therapistCase['id']],
        );
        self::assertFalse(
            $this->accounts->attach($accountId, $therapistCase['id'], $therapistCase['session_token']),
            'Кейс специалиста не переходит в кабинет посетителя.',
        );

        self::assertTrue($this->accounts->attach($accountId, $session['id'], $session['session_token']));
        self::assertSame(
            RetentionPolicy::ACCOUNT,
            $this->db->selectOne('SELECT retention_class FROM test_sessions WHERE id = ?', [$session['id']])['retention_class'],
        );
        self::assertFalse(
            $this->accounts->attach($accountId, $session['id'], $session['session_token']),
            'Уже привязанный результат не привязывается второй раз.',
        );

        $history = $this->accounts->history($accountId);
        self::assertCount(1, $history);
        self::assertSame($session['id'], $history[0]['id']);
        self::assertArrayNotHasKey('session_token', $history[0], 'В истории кабинета bearer-токену не место.');

        self::assertNotNull($this->accounts->findSessionForAccount($accountId, $session['id']));
        self::assertNull($this->accounts->findSessionForAccount($accountId, $other['id']));

        self::assertTrue($this->accounts->detach($accountId, $session['id']));
        $afterDetach = $this->db->selectOne(
            'SELECT retention_class, account_id FROM test_sessions WHERE id = ?',
            [$session['id']],
        );
        self::assertSame(RetentionPolicy::ANONYMOUS, $afterDetach['retention_class']);
        self::assertNull($afterDetach['account_id']);
        self::assertFalse($this->accounts->detach($accountId, $session['id']));

        $this->deleteAccountFor($this->email);
        foreach ([$partial, $session, $other, $therapistCase] as $leftover) {
            $this->lifecycle->deleteSessionAndArtifacts($leftover['id']);
        }
    }

    public function testDeletingAnAccountRemovesEverythingItHeldAndNothingElse(): void
    {
        $accountId = $this->loginAccount($this->email);

        $mine = $this->completedSession();
        $stranger = $this->completedSession();
        self::assertTrue($this->accounts->attach($accountId, $mine['id'], $mine['session_token']));

        file_put_contents($this->storagePath . '/result_' . $mine['id'] . '.pdf', 'result');
        $this->db->insert('ai_reports', [
            'id' => \Ramsey\Uuid\Uuid::uuid4()->toString(),
            'session_id' => $mine['id'],
            'test_slug' => 'bdi',
            'mode' => 'individual',
            'report_kind' => 'clear',
            'prompt_key' => 'fixture',
            'prompt_version' => 1,
            'status' => 'ready',
            'content' => 'fixture',
        ]);

        self::assertTrue($this->accounts->deleteAccount($accountId));

        self::assertNull($this->db->selectOne('SELECT id FROM test_sessions WHERE id = ?', [$mine['id']]));
        self::assertSame(
            0,
            (int) $this->db->selectOne('SELECT COUNT(*) AS count FROM ai_reports WHERE session_id = ?', [$mine['id']])['count'],
        );
        self::assertFileDoesNotExist($this->storagePath . '/result_' . $mine['id'] . '.pdf');
        self::assertSame(
            0,
            (int) $this->db->selectOne('SELECT COUNT(*) AS count FROM visitor_login_tokens WHERE email = ?', [$this->email])['count'],
        );
        self::assertNull($this->accounts->find($accountId));
        self::assertNotNull($this->db->selectOne('SELECT id FROM test_sessions WHERE id = ?', [$stranger['id']]));

        $this->lifecycle->deleteSessionAndArtifacts($stranger['id']);
    }

    public function testAutomaticAnonymousCleanupNeverTouchesASavedResult(): void
    {
        $accountId = $this->loginAccount($this->email);
        $saved = $this->completedSession();
        self::assertTrue($this->accounts->attach($accountId, $saved['id'], $saved['session_token']));

        $long_ago = '2020-01-01 12:00:00';
        $this->db->update('test_sessions', ['created_at' => $long_ago], 'id = ?', [$saved['id']]);

        $this->lifecycle->purgeExpiredAnonymousSessions(new DateTimeImmutable('2026-09-15 12:00:00'));

        self::assertNotNull($this->db->selectOne('SELECT id FROM test_sessions WHERE id = ?', [$saved['id']]));

        $this->accounts->deleteAccount($accountId);
    }

    /** @return array{id: string, session_token: string} */
    private function completedSession(): array
    {
        $session = $this->sessions->createSession($this->testId);
        $this->sessions->completeSession($session['id'], ['fixture' => true]);

        return ['id' => $session['id'], 'session_token' => $session['session_token']];
    }

    private function loginAccount(string $email): string
    {
        $this->accounts->requestLogin($email);
        $token = $this->mailer->lastLoginToken();
        self::assertIsString($token);
        $account = $this->accounts->consumeLogin($token);
        self::assertIsArray($account);

        return $account['id'];
    }

    private function deleteAccountFor(string $email): void
    {
        $account = $this->db->selectOne('SELECT id FROM visitor_accounts WHERE email = ?', [$email]);
        if ($account !== null) {
            $this->accounts->deleteAccount((string) $account['id']);
        }
    }
}
