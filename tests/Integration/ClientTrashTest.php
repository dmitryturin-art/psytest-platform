<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Database;
use PsyTest\Core\FormOnce;
use PsyTest\Core\InviteFilter;
use PsyTest\Core\OwnerClientTrashAction;
use PsyTest\Core\OwnerInviteClientAttach;
use PsyTest\Core\OwnerInviteSubmission;
use PsyTest\Core\RetentionPolicy;
use PsyTest\Core\SessionLifecycleService;
use PsyTest\Core\SessionManager;
use PsyTest\Core\TestInviteService;
use PsyTest\Core\TherapistCaseService;
use PsyTest\Core\TherapistClientService;
use Ramsey\Uuid\Uuid;

/** Корзина карточек клиентов: перенос, восстановление, окончательное удаление, очистка (07.K11). */
#[Group('database')]
final class ClientTrashTest extends TestCase
{
    private Database $db;
    private SessionManager $sessions;
    private TestInviteService $invites;
    private TherapistClientService $clients;
    private TherapistCaseService $cases;
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
        $this->storagePath = sys_get_temp_dir() . '/psytest-k11-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0700, true);
        $lifecycle = new SessionLifecycleService($this->db, new RetentionPolicy(180), $this->storagePath);
        $this->invites = new TestInviteService($this->db, $this->sessions);
        $this->clients = new TherapistClientService($this->db, $lifecycle);
        $this->cases = new TherapistCaseService($this->db, $lifecycle, $this->invites, $this->clients);
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
        foreach (glob($this->storagePath . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storagePath);
    }

    public function testTrashHidesTheCardAndItsAssignmentsAndKeepsEveryRecord(): void
    {
        $marker = 'k11-' . bin2hex(random_bytes(4));
        $clientId = $this->client('Карточка ' . $marker);
        $other = $this->client('Соседняя ' . $marker);
        $case = $this->completedCase($marker . ' кейс', $clientId);
        $otherCase = $this->completedCase($marker . ' чужой', $other);
        $archived = $this->completedCase($marker . ' архив', $clientId);
        $this->invites->archive([$archived]);
        $pending = $this->invites->create($this->testId('bdi'), $marker . ' ожидает', $clientId);
        $this->inviteIds[] = $pending['id'];

        self::assertSame(TherapistClientService::TRASH_DONE, $this->clients->trash($clientId));

        // Карточка ушла из рабочего списка и появилась в корзине с датой удаления.
        $working = array_column($this->clients->listForOwner(), 'id');
        self::assertNotContains($clientId, $working);
        self::assertContains($other, $working);
        $trashRow = $this->row($this->clients->listForOwner(100, '', null, true), $clientId);
        self::assertSame(3, (int) $trashRow['assignment_count']);
        self::assertSame(
            (new \DateTimeImmutable((string) $trashRow['trashed_at']))->modify('+30 days')->format('Y-m-d H:i:s'),
            $trashRow['purge_at'],
        );
        self::assertGreaterThanOrEqual(1, $this->clients->countsForOwner()['trash']);

        // Назначения карточки скрыты из рабочего и архивного списков, видны в корзине приглашений.
        $slugs = array_column($this->db->select('SELECT slug FROM tests'), 'slug');
        $ids = fn (array $query): array => array_column($this->invites->listForOwner(InviteFilter::fromQuery($query + ['q' => $marker], $slugs)), 'id');
        self::assertSame([$otherCase], $ids([]));
        self::assertSame([], $ids(['status' => 'archived']));
        self::assertEqualsCanonicalizing([$case, $archived, $pending['id']], $ids(['status' => 'trash']));

        // Живая ссылка отозвана: из корзины клиент не начнёт тест.
        self::assertNull($this->invites->claim($pending['token']));
        self::assertSame('revoked', $this->db->selectOne('SELECT status FROM test_invites WHERE id = ?', [$pending['id']])['status']);

        // Ничего клинического не удалено.
        self::assertNotNull($this->db->selectOne('SELECT id FROM test_sessions WHERE id = ?', [$this->sessionOf($case)]));
        self::assertSame($clientId, $this->clients->findForOwner($clientId)['client']['id']);
        self::assertNotNull($this->clients->findForOwner($clientId)['client']['purge_at']);
        self::assertSame(
            'client_trashed',
            $this->db->selectOne('SELECT action FROM activity_log WHERE action LIKE ? ORDER BY id DESC LIMIT 1', ['client_trashed'])['action'],
        );

        // Повтор и несуществующая карточка.
        self::assertSame(TherapistClientService::TRASH_UNCHANGED, $this->clients->trash($clientId));
        self::assertSame(TherapistClientService::TRASH_MISSING, $this->clients->trash(Uuid::uuid4()->toString()));
    }

    public function testRestoreReturnsOnlyTheAssignmentsTrashedWithTheCard(): void
    {
        $marker = 'k11-' . bin2hex(random_bytes(4));
        $clientId = $this->client('Восстановление ' . $marker);
        $withCard = $this->completedCase($marker . ' вместе', $clientId);
        $archived = $this->completedCase($marker . ' архив', $clientId);
        $this->invites->archive([$archived]);
        $byHand = $this->completedCase($marker . ' вручную', $clientId);
        self::assertSame(1, $this->invites->trash([$byHand]));
        // Ручная корзина была раньше: метка времени у неё другая.
        $this->db->update('test_invites', ['trashed_at' => date('Y-m-d H:i:s', time() - 86400)], 'id = ?', [$byHand]);

        $this->clients->trash($clientId);
        self::assertSame(2, $this->clients->assignmentsWithCard($clientId));

        $lastAudit = (int) $this->db->selectOne('SELECT COALESCE(MAX(id), 0) AS id FROM activity_log')['id'];
        self::assertSame(TherapistClientService::RESTORE_DONE, $this->clients->restore($clientId));

        self::assertContains($clientId, array_column($this->clients->listForOwner(), 'id'));
        self::assertNull($this->trashedAtOf($withCard));
        self::assertNull($this->trashedAtOf($archived));
        self::assertNotNull($this->trashedAtOf($byHand), 'A hand-trashed assignment stays in the trash.');
        // Архивная пометка пережила корзину карточки.
        self::assertNotNull($this->db->selectOne('SELECT archived_at FROM test_invites WHERE id = ?', [$archived])['archived_at']);
        self::assertSame(
            1,
            (int) $this->db->selectOne('SELECT COUNT(*) AS total FROM activity_log WHERE action = ? AND id > ?', ['client_restored', $lastAudit])['total'],
        );

        self::assertSame(TherapistClientService::RESTORE_UNCHANGED, $this->clients->restore($clientId));
        self::assertSame(TherapistClientService::RESTORE_MISSING, $this->clients->restore(Uuid::uuid4()->toString()));
    }

    public function testAnAssignmentOfATrashedCardCannotBeRestoredOnItsOwn(): void
    {
        $clientId = $this->client('Одиночное восстановление');
        $case = $this->completedCase('k11 single', $clientId);
        $this->clients->trash($clientId);

        self::assertSame(0, $this->invites->restore([$case]));
        self::assertNotNull($this->trashedAtOf($case));

        $this->clients->restore($clientId);
        self::assertNull($this->trashedAtOf($case));
    }

    public function testPurgeRemovesSessionsReportsArtifactsInvitesAndTheCardOnlyFromTheTrash(): void
    {
        $clientId = $this->client('Удаляется целиком');
        $neighbour = $this->client('Остаётся');
        $case = $this->completedCase('k11 purge', $clientId);
        $neighbourCase = $this->completedCase('k11 neighbour', $neighbour);
        $sessionId = $this->sessionOf($case);
        $pdf = $this->storagePath . '/result_' . $sessionId . '.pdf';
        file_put_contents($pdf, 'result');
        $reportId = $this->aiReport($sessionId);

        // Рабочую карточку удалить нельзя.
        self::assertSame(TherapistClientService::PURGE_REFUSED, $this->clients->purgeTrashed($clientId));
        self::assertNotNull($this->db->selectOne('SELECT id FROM therapist_clients WHERE id = ?', [$clientId]));
        self::assertFileExists($pdf);

        $this->clients->trash($clientId);
        self::assertSame(TherapistClientService::PURGE_DONE, $this->clients->purgeTrashed($clientId));

        self::assertNull($this->db->selectOne('SELECT id FROM therapist_clients WHERE id = ?', [$clientId]));
        self::assertNull($this->db->selectOne('SELECT id FROM test_sessions WHERE id = ?', [$sessionId]));
        self::assertNull($this->db->selectOne('SELECT id FROM test_invites WHERE id = ?', [$case]));
        self::assertNull($this->db->selectOne('SELECT id FROM ai_reports WHERE id = ?', [$reportId]));
        self::assertFileDoesNotExist($pdf);
        self::assertNotNull($this->db->selectOne('SELECT id FROM therapist_clients WHERE id = ?', [$neighbour]));
        self::assertNotNull($this->db->selectOne('SELECT id FROM test_invites WHERE id = ?', [$neighbourCase]));
        self::assertNotEmpty($this->db->select(
            'SELECT details FROM activity_log WHERE action = ? AND created_at >= ?',
            ['client_purged', date('Y-m-d H:i:s', time() - 60)],
        ));
        self::assertSame(TherapistClientService::PURGE_MISSING, $this->clients->purgeTrashed($clientId));
    }

    public function testNightlyPurgeUsesTheThirtyDayThreshold(): void
    {
        $young = $this->client('Корзина 29 дней');
        $old = $this->client('Корзина 31 день');
        $working = $this->client('Рабочая карточка');
        $oldCase = $this->completedCase('k11 cron old', $old);
        $youngCase = $this->completedCase('k11 cron young', $young);
        $this->clients->trash($young);
        $this->clients->trash($old);
        $this->db->update('therapist_clients', ['trashed_at' => date('Y-m-d H:i:s', time() - 29 * 86400)], 'id = ?', [$young]);
        $this->db->update('therapist_clients', ['trashed_at' => date('Y-m-d H:i:s', time() - 31 * 86400)], 'id = ?', [$old]);

        $threshold = (new \DateTimeImmutable())->modify('-' . TestInviteService::TRASH_RETENTION_DAYS . ' days');
        $result = $this->clients->purgeTrash($threshold);

        self::assertGreaterThanOrEqual(1, $result['clients']);
        self::assertSame(0, $result['failed']);
        self::assertNull($this->db->selectOne('SELECT id FROM therapist_clients WHERE id = ?', [$old]));
        self::assertNull($this->db->selectOne('SELECT id FROM test_invites WHERE id = ?', [$oldCase]));
        self::assertNotNull($this->db->selectOne('SELECT id FROM therapist_clients WHERE id = ?', [$young]));
        self::assertNotNull($this->db->selectOne('SELECT id FROM test_invites WHERE id = ?', [$youngCase]));
        self::assertNotNull($this->db->selectOne('SELECT id FROM therapist_clients WHERE id = ?', [$working]));
    }

    public function testTrashedCardIsReadOnlyAndNotOfferedForNewWork(): void
    {
        $clientId = $this->client('Только чтение');
        $loose = $this->completedCase('k11 loose');
        $this->clients->trash($clientId);

        self::assertFalse($this->clients->isActive($clientId));
        self::assertTrue($this->clients->exists($clientId));
        self::assertFalse($this->clients->update($clientId, 'Новое имя', '', ''));
        self::assertSame('Только чтение', $this->clients->labelOf($clientId));

        // Новое назначение, привязка кейса и «новый кейс клиенту» отклоняются.
        $this->expectNewInviteRefused($clientId);
        self::assertSame(TestInviteService::ATTACH_REFUSED, $this->invites->attachClient($loose, $clientId));
        self::assertNull($this->db->selectOne('SELECT client_id FROM test_invites WHERE id = ?', [$loose])['client_id']);

        $store = [];
        $once = new FormOnce($store);
        $attach = new OwnerInviteClientAttach($this->invites, $this->clients, $once);
        $outcome = $attach->submit(['invite_id' => $loose, 'client_id' => $clientId, 'form_key' => $attach->issueKey()]);
        self::assertSame('error', $outcome['type']);

        $submission = new OwnerInviteSubmission($this->db, $this->clients, $this->invites, $once, 'https://example.test');
        $created = $submission->submit(
            ['test_id' => (string) $this->testId('bdi'), 'client_id' => $clientId, 'owner_note' => '', 'form_key' => $submission->issueKey()],
            [$this->testId('bdi')],
        );
        self::assertSame('error', $created['type']);
        self::assertSame(
            0,
            (int) $this->db->selectOne('SELECT COUNT(*) AS total FROM test_invites WHERE client_id = ?', [$clientId])['total'],
        );

        // Восстановленная карточка снова доступна.
        $this->clients->restore($clientId);
        self::assertTrue($this->clients->isActive($clientId));
        self::assertTrue($this->clients->update($clientId, 'Новое имя', '', ''));
    }

    public function testActionClassNeedsConfirmationClaimsTheFormKeyOnceAndWritesTheMessages(): void
    {
        $label = 'Анна К.';
        $clientId = $this->client($label);
        $this->completedCase('k11 action 1', $clientId);
        $this->completedCase('k11 action 2', $clientId);
        $store = [];
        $action = new OwnerClientTrashAction($this->clients, new FormOnce($store));

        // Окончательное удаление рабочей карточки: без галочки и из рабочего списка отказ.
        $key = $action->issueKey();
        $noCheck = $action->submit(OwnerClientTrashAction::PURGE, $clientId, ['form_key' => $key]);
        self::assertSame('error', $noCheck['type']);
        $refused = $action->submit(OwnerClientTrashAction::PURGE, $clientId, ['form_key' => $key, 'confirm_delete' => 'delete']);
        self::assertSame('error', $refused['type']);
        self::assertTrue($this->clients->exists($clientId));

        $trash = $action->submit(OwnerClientTrashAction::TRASH, $clientId, ['form_key' => $action->issueKey()]);
        self::assertSame('success', $trash['type']);
        self::assertSame(
            'Карточка «Анна К.» перемещена в корзину: 2 назначения скрыто. Через 30 дней она будет удалена окончательно.',
            $trash['message'],
        );

        // Двойной клик: тот же ключ даёт то же сообщение и ничего не повторяет.
        $key = $action->issueKey();
        $first = $action->submit(OwnerClientTrashAction::RESTORE, $clientId, ['form_key' => $key]);
        self::assertSame('Карточка восстановлена вместе с 2 назначениями.', $first['message']);
        self::assertSame($first, $action->submit(OwnerClientTrashAction::RESTORE, $clientId, ['form_key' => $key]));
        self::assertSame('error', $action->submit(OwnerClientTrashAction::RESTORE, $clientId, ['form_key' => 'bad'])['type']);

        $action->submit(OwnerClientTrashAction::TRASH, $clientId, ['form_key' => $action->issueKey()]);
        $purged = $action->submit(OwnerClientTrashAction::PURGE, $clientId, ['form_key' => $action->issueKey(), 'confirm_delete' => 'delete']);
        self::assertSame(['type' => 'success', 'message' => 'Карточка и все её данные удалены без возможности восстановления.'], $purged);
        self::assertFalse($this->clients->exists($clientId));
        self::assertSame('error', $action->submit(OwnerClientTrashAction::PURGE, $clientId, ['form_key' => $action->issueKey(), 'confirm_delete' => 'delete'])['type']);
    }

    public function testAuditEventsCarryNoLabelOrIdentifier(): void
    {
        $clientId = $this->client('Секретная подпись');
        $this->clients->trash($clientId);
        $this->clients->restore($clientId);
        $this->clients->trash($clientId);
        $this->clients->purgeTrashed($clientId);

        $rows = $this->db->select(
            'SELECT action, session_id, test_id, details FROM activity_log WHERE action IN (?, ?, ?) AND created_at >= ? ORDER BY id DESC LIMIT 4',
            ['client_trashed', 'client_restored', 'client_purged', date('Y-m-d H:i:s', time() - 60)],
        );
        self::assertCount(4, $rows);
        foreach ($rows as $row) {
            self::assertNull($row['session_id']);
            self::assertNull($row['test_id']);
            self::assertSame('{"actor": "owner"}', $row['details']);
            self::assertStringNotContainsString($clientId, (string) $row['details']);
        }
    }

    private function expectNewInviteRefused(string $clientId): void
    {
        try {
            $this->invites->create($this->testId('bdi'), 'не должно создаться', $clientId);
            self::fail('A trashed card must not receive a new assignment.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    private function client(string $label): string
    {
        $id = $this->clients->create($label, '');
        $this->clientIds[] = $id;

        return $id;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function row(array $rows, string $id): array
    {
        foreach ($rows as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        self::fail('Row not found');
    }

    private function trashedAtOf(string $inviteId): mixed
    {
        return $this->db->selectOne('SELECT trashed_at FROM test_invites WHERE id = ?', [$inviteId])['trashed_at'];
    }

    private function testId(string $slug): int
    {
        return (int) $this->db->selectOne('SELECT id FROM tests WHERE slug = ?', [$slug])['id'];
    }

    private function sessionOf(string $inviteId): string
    {
        return (string) $this->db->selectOne('SELECT claimed_session_id FROM test_invites WHERE id = ?', [$inviteId])['claimed_session_id'];
    }

    /** Завершённый кейс со строкой приглашения; возвращает id приглашения. */
    private function completedCase(string $note, ?string $clientId = null): string
    {
        $session = $this->sessions->createSession($this->testId('bdi'));
        $this->sessions->completeSession($session['id'], ['fixture' => true]);
        $this->sessionIds[] = (string) $session['id'];
        $inviteId = $this->invites->bindExistingSession((string) $session['id'], $this->testId('bdi'), $clientId, $note);
        $this->inviteIds[] = $inviteId;

        return $inviteId;
    }

    private function aiReport(string $sessionId): string
    {
        $id = Uuid::uuid4()->toString();
        $this->db->insert('ai_reports', [
            'id' => $id,
            'session_id' => $sessionId,
            'test_slug' => 'bdi',
            'mode' => 'individual',
            'report_kind' => 'clear',
            'status' => 'ready',
            'prompt_key' => 'synthetic',
            'prompt_version' => 1,
            'content' => 'синтетический текст',
        ]);

        return $id;
    }
}
