<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Database;
use PsyTest\Core\FormOnce;
use PsyTest\Core\OwnerInviteClientAttach;
use PsyTest\Core\RetentionPolicy;
use PsyTest\Core\SessionLifecycleService;
use PsyTest\Core\SessionManager;
use PsyTest\Core\TestInviteService;
use PsyTest\Core\TherapistClientService;
use Ramsey\Uuid\Uuid;

/** Привязка приглашения к клиенту и смена клиента (07.K9). */
#[Group('database')]
final class InviteAttachClientTest extends TestCase
{
    private Database $db;
    private SessionManager $sessions;
    private TestInviteService $invites;
    private TherapistClientService $clients;
    private string $storagePath;

    /** @var list<string> */
    private array $inviteIds = [];
    /** @var list<string> */
    private array $clientIds = [];
    /** @var list<string> */
    private array $sessionIds = [];

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->sessions = new SessionManager($this->db);
        $this->storagePath = sys_get_temp_dir() . '/psytest-k9-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0700, true);
        $lifecycle = new SessionLifecycleService($this->db, new RetentionPolicy(180), $this->storagePath);
        $this->invites = new TestInviteService($this->db, $this->sessions);
        $this->clients = new TherapistClientService($this->db, $lifecycle);
    }

    protected function tearDown(): void
    {
        foreach ($this->inviteIds as $id) {
            $this->db->delete('test_invites', 'id = ?', [$id]);
        }
        foreach ($this->sessionIds as $id) {
            $this->db->delete('test_sessions', 'id = ?', [$id]);
        }
        foreach ($this->clientIds as $id) {
            $this->db->delete('therapist_clients', 'id = ?', [$id]);
        }
        @rmdir($this->storagePath);
    }

    public function testAttachesToAnExistingClientAndLogsAFirstAttach(): void
    {
        $client = $this->client('Анна К.');
        $invite = $this->invite();
        $before = $this->auditCount('invite_client_attached');

        self::assertSame(TestInviteService::ATTACH_DONE, $this->invites->attachClient($invite, $client));

        self::assertSame($client, $this->clientOf($invite));
        self::assertSame($before + 1, $this->auditCount('invite_client_attached'));
        $card = $this->clients->findForOwner($client);
        self::assertNotNull($card);
        self::assertContains($invite, array_column($card['assignments'], 'id'));
    }

    public function testNewClientIsCreatedInTheSameStep(): void
    {
        $invite = $this->invite();
        $label = 'Новый К9 ' . bin2hex(random_bytes(3));

        self::assertSame(TestInviteService::ATTACH_DONE, $this->invites->attachClient($invite, null, '  ' . $label . '  '));

        $clientId = $this->clientOf($invite);
        self::assertNotNull($clientId);
        $this->clientIds[] = $clientId;
        self::assertSame($label, $this->db->selectOne('SELECT label FROM therapist_clients WHERE id = ?', [$clientId])['label']);
    }

    public function testInvalidNewClientLabelCreatesNothing(): void
    {
        $invite = $this->invite();
        $cards = (int) $this->db->selectOne('SELECT COUNT(*) AS c FROM therapist_clients')['c'];

        self::assertSame(TestInviteService::ATTACH_REFUSED, $this->invites->attachClient($invite, null, '   '));
        self::assertSame(TestInviteService::ATTACH_REFUSED, $this->invites->attachClient($invite, null, str_repeat('я', 121)));

        self::assertNull($this->clientOf($invite));
        self::assertSame($cards, (int) $this->db->selectOne('SELECT COUNT(*) AS c FROM therapist_clients')['c']);
    }

    public function testSameClientAgainIsUnchangedAndWritesNoAudit(): void
    {
        $client = $this->client('Борис');
        $invite = $this->invite();
        $this->invites->attachClient($invite, $client);
        $attached = $this->auditCount('invite_client_attached');
        $changed = $this->auditCount('invite_client_changed');

        self::assertSame(TestInviteService::ATTACH_UNCHANGED, $this->invites->attachClient($invite, $client));

        self::assertSame($attached, $this->auditCount('invite_client_attached'));
        self::assertSame($changed, $this->auditCount('invite_client_changed'));
    }

    public function testChangingClientIsLoggedAsAChange(): void
    {
        $first = $this->client('Первый');
        $second = $this->client('Второй');
        $invite = $this->invite();
        $this->invites->attachClient($invite, $first);
        $before = $this->auditCount('invite_client_changed');

        self::assertSame(TestInviteService::ATTACH_CHANGED, $this->invites->attachClient($invite, $second));

        self::assertSame($second, $this->clientOf($invite));
        self::assertSame($before + 1, $this->auditCount('invite_client_changed'));
        $details = (string) $this->db->selectOne(
            "SELECT details FROM activity_log WHERE action = 'invite_client_changed' ORDER BY id DESC LIMIT 1",
        )['details'];
        self::assertStringNotContainsString('Второй', $details);
        self::assertStringNotContainsString($second, $details);
        self::assertStringNotContainsString($invite, $details);
    }

    public function testMissingInviteAndUnknownClientAreRefusedWithoutChanges(): void
    {
        $client = $this->client('Вера');
        $invite = $this->invite();

        self::assertSame(TestInviteService::ATTACH_MISSING, $this->invites->attachClient(Uuid::uuid4()->toString(), $client));
        self::assertSame(TestInviteService::ATTACH_REFUSED, $this->invites->attachClient($invite, Uuid::uuid4()->toString()));
        self::assertNull($this->clientOf($invite));
    }

    public function testWorksForEveryInviteStateAndLeavesTokenAndSessionUntouched(): void
    {
        $client = $this->client('Глеб');
        $pending = $this->invite();
        $revoked = $this->invite();
        $this->invites->revoke($revoked);
        $expired = $this->invite();
        $this->db->update('test_invites', ['expires_at' => date('Y-m-d H:i:s', time() - 60)], 'id = ?', [$expired]);
        [$completed, $session] = $this->completedCase();
        [$trashed] = $this->completedCase();
        $this->invites->trash([$trashed]);
        [$archived] = $this->completedCase();
        $this->invites->archive([$archived]);

        $retention = $this->db->selectOne('SELECT retention_class, status FROM test_sessions WHERE id = ?', [$session]);
        $hash = $this->db->selectOne('SELECT token_hash FROM test_invites WHERE id = ?', [$completed])['token_hash'];

        foreach ([$pending, $revoked, $expired, $completed, $trashed, $archived] as $id) {
            self::assertSame(TestInviteService::ATTACH_DONE, $this->invites->attachClient($id, $client), $id);
            self::assertSame($client, $this->clientOf($id));
        }

        self::assertSame($retention, $this->db->selectOne('SELECT retention_class, status FROM test_sessions WHERE id = ?', [$session]));
        self::assertSame($hash, $this->db->selectOne('SELECT token_hash FROM test_invites WHERE id = ?', [$completed])['token_hash']);
        self::assertSame('revoked', $this->db->selectOne('SELECT status FROM test_invites WHERE id = ?', [$revoked])['status']);
        self::assertNotNull($this->db->selectOne('SELECT trashed_at FROM test_invites WHERE id = ?', [$trashed])['trashed_at']);
        self::assertNotNull($this->db->selectOne('SELECT archived_at FROM test_invites WHERE id = ?', [$archived])['archived_at']);
    }

    public function testSubmissionChangeNeedsConfirmationAndKeepsTheKey(): void
    {
        $first = $this->client('Дарья');
        $second = $this->client('Егор');
        $invite = $this->invite();
        $this->invites->attachClient($invite, $first);
        $session = [];
        $attach = $this->submission($session);
        $key = $attach->issueKey();

        $refused = $attach->submit(['invite_id' => $invite, 'client_id' => $second, 'form_key' => $key]);
        self::assertSame('error', $refused['type']);
        self::assertSame($first, $this->clientOf($invite));

        $done = $attach->submit(['invite_id' => $invite, 'client_id' => $second, 'form_key' => $key, 'confirm_change' => '1']);
        self::assertSame('success', $done['type']);
        self::assertSame('Клиент изменён на «Егор». Результат и ссылка не изменились.', $done['message']);
        self::assertSame($second, $this->clientOf($invite));

        // Двойной клик: второй запрос с тем же ключом ничего не делает.
        $replay = $attach->submit(['invite_id' => $invite, 'client_id' => $first, 'form_key' => $key, 'confirm_change' => '1']);
        self::assertSame($second, $this->clientOf($invite));
        self::assertSame($done['message'], $replay['message']);
    }

    public function testSubmissionMessagesForFirstAttachNewClientAndSameClient(): void
    {
        $client = $this->client('Анна К.');
        [$withCase] = $this->completedCase();
        $loose = $this->invite();
        $session = [];
        $attach = $this->submission($session);

        $first = $attach->submit(['invite_id' => $withCase, 'client_id' => $client, 'form_key' => $attach->issueKey()]);
        self::assertSame('Кейс привязан к клиенту «Анна К.».', $first['message']);

        $same = $attach->submit(['invite_id' => $withCase, 'client_id' => $client, 'form_key' => $attach->issueKey()]);
        self::assertSame('success', $same['type']);
        self::assertSame('Уже привязано к этому клиенту «Анна К.».', $same['message']);

        $label = 'Из диалога ' . bin2hex(random_bytes(3));
        $new = $attach->submit(['invite_id' => $loose, 'client_id' => '__new__', 'new_client_label' => $label, 'form_key' => $attach->issueKey()]);
        self::assertSame('Приглашение привязано к клиенту «' . $label . '».', $new['message']);
        $created = $this->clientOf($loose);
        self::assertNotNull($created);
        $this->clientIds[] = $created;
    }

    public function testSubmissionRejectsBadInputAndStaleKeys(): void
    {
        $client = $this->client('Жанна');
        $invite = $this->invite();
        $session = [];
        $attach = $this->submission($session);
        $key = $attach->issueKey();

        self::assertSame('error', $attach->submit(['invite_id' => 'x', 'client_id' => $client, 'form_key' => $key])['type']);
        self::assertSame('error', $attach->submit(['invite_id' => $invite, 'client_id' => '', 'form_key' => $key])['type']);
        self::assertSame('error', $attach->submit(['invite_id' => $invite, 'client_id' => '__new__', 'new_client_label' => ' ', 'form_key' => $key])['type']);
        self::assertSame('error', $attach->submit(['invite_id' => $invite, 'client_id' => Uuid::uuid4()->toString(), 'form_key' => $key])['type']);
        self::assertSame('error', $attach->submit(['invite_id' => Uuid::uuid4()->toString(), 'client_id' => $client, 'form_key' => $key])['type']);
        self::assertSame('error', $attach->submit(['invite_id' => $invite, 'client_id' => $client, 'form_key' => 'stale'])['type']);
        self::assertNull($this->clientOf($invite));

        // Ошибки ввода ключ не тратят: исправленная форма уходит с тем же ключом.
        self::assertSame('success', $attach->submit(['invite_id' => $invite, 'client_id' => $client, 'form_key' => $key])['type']);
    }

    /** @param array<string, mixed> $session */
    private function submission(array &$session): OwnerInviteClientAttach
    {
        return new OwnerInviteClientAttach($this->invites, $this->clients, new FormOnce($session));
    }

    private function client(string $label): string
    {
        $id = $this->clients->create($label, '');
        $this->clientIds[] = $id;

        return $id;
    }

    private function invite(): string
    {
        $invite = $this->invites->create($this->testId(), 'k9-' . bin2hex(random_bytes(3)));
        $this->inviteIds[] = $invite['id'];

        return $invite['id'];
    }

    /** @return array{0: string, 1: string} invite id and session id */
    private function completedCase(): array
    {
        $session = $this->sessions->createSession($this->testId());
        $this->sessions->completeSession($session['id'], ['fixture' => true]);
        $this->sessionIds[] = (string) $session['id'];
        $inviteId = $this->invites->bindExistingSession((string) $session['id'], $this->testId(), null, 'k9');
        $this->inviteIds[] = $inviteId;

        return [$inviteId, (string) $session['id']];
    }

    private function clientOf(string $inviteId): ?string
    {
        $value = $this->db->selectOne('SELECT client_id FROM test_invites WHERE id = ?', [$inviteId])['client_id'];

        return $value === null ? null : (string) $value;
    }

    private function auditCount(string $action): int
    {
        return (int) $this->db->selectOne('SELECT COUNT(*) AS c FROM activity_log WHERE action = ?', [$action])['c'];
    }

    private function testId(): int
    {
        return (int) $this->db->selectOne("SELECT id FROM tests WHERE slug = 'bdi'")['id'];
    }
}
