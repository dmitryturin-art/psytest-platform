<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Database;
use PsyTest\Core\FormOnce;
use PsyTest\Core\InviteFilter;
use PsyTest\Core\OwnerInviteBulkAction;
use PsyTest\Core\RetentionPolicy;
use PsyTest\Core\SessionLifecycleService;
use PsyTest\Core\SessionManager;
use PsyTest\Core\TestInviteService;
use PsyTest\Core\TherapistCaseService;
use PsyTest\Core\TherapistClientService;
use Ramsey\Uuid\Uuid;

/** Фильтры, архив, корзина и окончательное удаление приглашений (07.K8). */
#[Group('database')]
final class InviteArchiveTrashTest extends TestCase
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
        $this->storagePath = sys_get_temp_dir() . '/psytest-k8-' . bin2hex(random_bytes(6));
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

    public function testDefaultListHidesArchiveAndTrashAndStatusViewsShowThem(): void
    {
        $marker = 'k8-' . bin2hex(random_bytes(4));
        $active = $this->completedCase($marker . '-active');
        $archived = $this->completedCase($marker . '-archived');
        $trashed = $this->completedCase($marker . '-trashed');
        $this->invites->archive([$archived]);
        $this->invites->trash([$trashed]);

        $default = $this->ids(InviteFilter::fromQuery(['q' => $marker], $this->slugs()));
        self::assertSame([$active], $default);
        self::assertSame([$archived], $this->ids(InviteFilter::fromQuery(['q' => $marker, 'status' => 'archived'], $this->slugs())));
        self::assertSame([$trashed], $this->ids(InviteFilter::fromQuery(['q' => $marker, 'status' => 'trash'], $this->slugs())));

        $counts = $this->invites->countsForOwner();
        self::assertGreaterThanOrEqual(1, $counts['active']);
        self::assertGreaterThanOrEqual(1, $counts['archived']);
        self::assertGreaterThanOrEqual(1, $counts['trash']);

        $row = $this->invites->listForOwner(InviteFilter::fromQuery(['q' => $marker, 'status' => 'trash'], $this->slugs()))[0];
        self::assertNotNull($row['purge_at']);
        self::assertSame(
            (new \DateTimeImmutable((string) $row['trashed_at']))->modify('+30 days')->format('Y-m-d H:i:s'),
            $row['purge_at'],
        );
    }

    public function testClientTestStatusAndTextFiltersNarrowTheList(): void
    {
        $marker = 'k8-' . bin2hex(random_bytes(4));
        $clientA = $this->clients->create('Клиент ' . $marker . ' А', '');
        $clientB = $this->clients->create('Клиент ' . $marker . ' Б', '');
        $this->clientIds = [$clientA, $clientB];

        $bdi = $this->invites->create($this->testId('bdi'), 'заметка ' . $marker, $clientA);
        $hads = $this->invites->create($this->testId('hads'), 'другая', $clientB);
        $loose = $this->invites->create($this->testId('bdi'), 'без клиента ' . $marker);
        $revoked = $this->invites->create($this->testId('bdi'), 'отозвано ' . $marker, $clientA);
        $this->invites->revoke($revoked['id']);
        $expired = $this->invites->create($this->testId('bdi'), 'просрочено ' . $marker, $clientA);
        $this->db->update('test_invites', ['expires_at' => date('Y-m-d H:i:s', time() - 60)], 'id = ?', [$expired['id']]);
        foreach ([$bdi, $hads, $loose, $revoked, $expired] as $invite) {
            $this->inviteIds[] = $invite['id'];
        }

        $list = fn (array $query): array => $this->ids(InviteFilter::fromQuery($query, $this->slugs()));

        self::assertEqualsCanonicalizing(
            [$bdi['id'], $revoked['id'], $expired['id']],
            $list(['client' => $clientA]),
        );
        self::assertSame([$hads['id']], $list(['client' => $clientB, 'test' => 'hads']));
        self::assertSame([], $list(['client' => $clientB, 'test' => 'bdi']));
        self::assertSame([$loose['id']], $list(['client' => 'none', 'q' => $marker]));
        self::assertSame([$bdi['id']], $list(['status' => 'pending', 'client' => $clientA]));
        self::assertSame([$revoked['id']], $list(['status' => 'revoked', 'client' => $clientA]));
        self::assertSame([$expired['id']], $list(['status' => 'expired', 'client' => $clientA]));
        // Поиск идёт и по подписи клиента, и по заметке; % и _ — обычные символы.
        self::assertEqualsCanonicalizing([$bdi['id'], $revoked['id'], $expired['id'], $hads['id']], $list(['q' => 'Клиент ' . $marker]));
        self::assertSame([], $list(['q' => $marker . '%']));
        self::assertSame([], $list(['q' => 'k8_' . substr($marker, 3)]));
    }

    public function testOpenedAndCompletedStatusFiltersMatchTheDisplayStatus(): void
    {
        $marker = 'k8-' . bin2hex(random_bytes(4));
        $completed = $this->completedCase($marker . ' готово');
        $openedInvite = $this->invites->create($this->testId('bdi'), $marker . ' открыто');
        $this->inviteIds[] = $openedInvite['id'];
        $claim = $this->invites->claim($openedInvite['token']);
        self::assertNotNull($claim);
        $this->sessionIds[] = $claim['session']['id'];

        self::assertSame([$completed], $this->ids(InviteFilter::fromQuery(['q' => $marker, 'status' => 'completed'], $this->slugs())));
        self::assertSame([$openedInvite['id']], $this->ids(InviteFilter::fromQuery(['q' => $marker, 'status' => 'opened'], $this->slugs())));
        $displayed = array_column(
            $this->invites->listForOwner(InviteFilter::fromQuery(['q' => $marker], $this->slugs())),
            'display_status',
            'id',
        );
        self::assertSame('completed', $displayed[$completed]);
        self::assertSame('opened', $displayed[$openedInvite['id']]);
    }

    public function testArchiveTrashAndRestoreSemanticsAreIdempotentAndRefusePending(): void
    {
        $pending = $this->invites->create($this->testId('bdi'), '');
        $this->inviteIds[] = $pending['id'];
        $case = $this->completedCase('k8 semantics');

        self::assertSame(0, $this->invites->archive([$pending['id']]), 'Ожидающее приглашение сначала отзывают.');
        self::assertSame(0, $this->invites->trash([$pending['id']]));
        self::assertSame(0, $this->invites->archive(['not-a-uuid']));
        self::assertSame(0, $this->invites->archive([]));

        self::assertSame(1, $this->invites->archive([$case]));
        self::assertSame(0, $this->invites->archive([$case]), 'Повтор по заархивированному ничего не меняет.');
        self::assertSame(1, $this->invites->unarchive([$case]));
        self::assertSame(0, $this->invites->unarchive([$case]));

        self::assertSame(1, $this->invites->archive([$case]));
        self::assertSame(1, $this->invites->trash([$case]));
        $row = $this->db->selectOne('SELECT archived_at, trashed_at FROM test_invites WHERE id = ?', [$case]);
        self::assertNull($row['archived_at'], 'Корзина снимает отметку архива.');
        self::assertNotNull($row['trashed_at']);
        self::assertSame(0, $this->invites->trash([$case]));
        self::assertSame(0, $this->invites->archive([$case]), 'Из корзины в архив нельзя.');

        self::assertSame(1, $this->invites->restore([$case]));
        self::assertSame(0, $this->invites->restore([$case]));
        $row = $this->db->selectOne('SELECT archived_at, trashed_at FROM test_invites WHERE id = ?', [$case]);
        self::assertNull($row['trashed_at']);
        self::assertNull($row['archived_at']);

        // Просроченное, никем не открытое приглашение можно убрать.
        $this->db->update('test_invites', ['expires_at' => date('Y-m-d H:i:s', time() - 60)], 'id = ?', [$pending['id']]);
        self::assertSame(1, $this->invites->trash([$pending['id']]));
    }

    public function testRestoreKeepsEveryClinicalRecord(): void
    {
        $case = $this->completedCase('k8 restore');
        $sessionId = $this->sessionOf($case);
        $reportId = $this->aiReport($sessionId);

        $this->invites->trash([$case]);
        $this->invites->restore([$case]);

        self::assertNotNull($this->db->selectOne('SELECT id FROM test_sessions WHERE id = ?', [$sessionId]));
        self::assertNotNull($this->db->selectOne('SELECT id FROM ai_reports WHERE id = ?', [$reportId]));
        self::assertNotNull($this->invites->claimedCaseForOwner($sessionId));
    }

    public function testTrashedCaseKeepsTheClientRecordAndLeavesTheClientCardCounts(): void
    {
        $clientId = $this->clients->create('Клиент корзины', '');
        $this->clientIds[] = $clientId;
        $case = $this->completedCase('k8 card', $clientId);
        $other = $this->completedCase('k8 card 2', $clientId);

        $card = $this->clients->findForOwner($clientId);
        self::assertCount(2, $card['history']);

        $this->invites->trash([$case]);
        $this->invites->archive([$other]);
        $card = $this->clients->findForOwner($clientId);
        self::assertCount(1, $card['history'], 'Корзина не входит в историю карточки.');
        self::assertCount(2, $card['assignments'], 'Строки остаются в данных карточки; фильтрует контроллер.');
        $listed = array_values(array_filter(
            $this->clients->listForOwner(100, 'Клиент корзины'),
            static fn (array $row): bool => $row['id'] === $clientId,
        ));
        self::assertSame(1, (int) $listed[0]['assignment_count']);
        self::assertSame(1, (int) $listed[0]['completed_count']);

        // Список клиентов: поиск по подписи и по методике.
        self::assertCount(1, $this->clients->listForOwner(100, 'Клиент корзины', 'bdi'));
        self::assertSame([], $this->clients->listForOwner(100, 'Клиент корзины', 'hads'));
        self::assertSame([], $this->clients->listForOwner(100, 'Клиент корзины%'));

        // Окончательное удаление кейса карточку не трогает.
        self::assertSame(TherapistCaseService::PURGE_DONE, $this->cases->purgeTrashed($case));
        self::assertTrue($this->clients->exists($clientId));
    }

    public function testPurgeRemovesCaseReportsRevisionsAndArtifactsButOnlyFromTrash(): void
    {
        $case = $this->completedCase('k8 purge');
        $sessionId = $this->sessionOf($case);
        $reportId = $this->aiReport($sessionId);
        $this->db->insert('ai_report_revisions', [
            'id' => Uuid::uuid4()->toString(),
            'report_id' => $reportId,
            'revision_no' => 1,
            'content' => 'версия',
            'source' => 'owner',
        ]);
        file_put_contents($this->storagePath . '/result_' . $sessionId . '.pdf', 'pdf');

        self::assertSame(TherapistCaseService::PURGE_REFUSED, $this->cases->purgeTrashed($case), 'Из рабочего списка удалять нельзя.');
        self::assertNotNull($this->db->selectOne('SELECT id FROM test_sessions WHERE id = ?', [$sessionId]));

        $this->invites->trash([$case]);
        self::assertSame(TherapistCaseService::PURGE_DONE, $this->cases->purgeTrashed($case));

        self::assertNull($this->db->selectOne('SELECT id FROM test_invites WHERE id = ?', [$case]));
        self::assertNull($this->db->selectOne('SELECT id FROM test_sessions WHERE id = ?', [$sessionId]));
        self::assertNull($this->db->selectOne('SELECT id FROM ai_reports WHERE id = ?', [$reportId]));
        self::assertNull($this->db->selectOne('SELECT id FROM ai_report_revisions WHERE report_id = ?', [$reportId]));
        self::assertFileDoesNotExist($this->storagePath . '/result_' . $sessionId . '.pdf');
        self::assertSame(TherapistCaseService::PURGE_MISSING, $this->cases->purgeTrashed($case), 'Повтор — не ошибка.');
    }

    public function testPurgeOfATrashedRevokedInviteDeletesJustTheRow(): void
    {
        $invite = $this->invites->create($this->testId('bdi'), 'отозвано');
        $this->inviteIds[] = $invite['id'];
        $this->invites->revoke($invite['id']);
        $this->invites->trash([$invite['id']]);

        self::assertSame(TherapistCaseService::PURGE_DONE, $this->cases->purgeTrashed($invite['id']));
        self::assertNull($this->db->selectOne('SELECT id FROM test_invites WHERE id = ?', [$invite['id']]));
    }

    public function testCronPurgeUsesThirtyDayThresholdAndSkipsEverythingOutsideTrash(): void
    {
        $fresh = $this->completedCase('k8 cron 29');
        $old = $this->completedCase('k8 cron 31');
        $working = $this->completedCase('k8 cron working');
        $archivedOld = $this->completedCase('k8 cron archived');
        $this->invites->trash([$fresh, $old]);
        $this->invites->archive([$archivedOld]);
        $this->db->update('test_invites', ['trashed_at' => date('Y-m-d H:i:s', time() - 29 * 86400)], 'id = ?', [$fresh]);
        $this->db->update('test_invites', ['trashed_at' => date('Y-m-d H:i:s', time() - 31 * 86400)], 'id = ?', [$old]);
        $this->db->update('test_invites', ['archived_at' => date('Y-m-d H:i:s', time() - 400 * 86400)], 'id = ?', [$archivedOld]);
        $oldSession = $this->sessionOf($old);

        $threshold = (new \DateTimeImmutable())->modify('-' . TestInviteService::TRASH_RETENTION_DAYS . ' days');
        $result = $this->cases->purgeTrash($threshold);

        self::assertGreaterThanOrEqual(1, $result['invites']);
        self::assertGreaterThanOrEqual(1, $result['cases']);
        self::assertSame(0, $result['failed']);
        self::assertNull($this->db->selectOne('SELECT id FROM test_invites WHERE id = ?', [$old]));
        self::assertNull($this->db->selectOne('SELECT id FROM test_sessions WHERE id = ?', [$oldSession]));
        foreach ([$fresh, $working, $archivedOld] as $kept) {
            self::assertNotNull($this->db->selectOne('SELECT id FROM test_invites WHERE id = ?', [$kept]), 'Нужно сохранить ' . $kept);
        }

        // Повторный запуск того же дня ничего не находит.
        self::assertSame(['invites' => 0, 'cases' => 0, 'failed' => 0], $this->cases->purgeTrash($threshold));
    }

    public function testBulkActionIsIdempotentOnDoubleSubmitAndExplainsItself(): void
    {
        $session = [];
        $bulk = new OwnerInviteBulkAction($this->invites, $this->cases, new FormOnce($session));
        $a = $this->completedCase('k8 bulk a');
        $b = $this->completedCase('k8 bulk b');
        $pending = $this->invites->create($this->testId('bdi'), '');
        $this->inviteIds[] = $pending['id'];

        $key = $bulk->issueKey();
        $post = ['form_key' => $key, 'invite_ids' => [$a, $b, $pending['id']]];
        $first = $bulk->submit(OwnerInviteBulkAction::TRASH, $post);
        self::assertSame('success', $first['type']);
        self::assertStringContainsString('В корзину перемещено 2 приглашения', $first['message']);
        self::assertStringContainsString('30 дней', $first['message']);

        // Двойной клик: ключ уже отработал, действие не повторяется, сообщение прежнее.
        $second = $bulk->submit(OwnerInviteBulkAction::TRASH, $post);
        self::assertSame($first, $second);

        // Без ключа, с чужим ключом и без выбора действие не выполняется.
        self::assertSame('error', $bulk->submit(OwnerInviteBulkAction::RESTORE, ['invite_ids' => [$a]])['type']);
        self::assertSame('error', $bulk->submit(OwnerInviteBulkAction::RESTORE, ['form_key' => str_repeat('a', 32), 'invite_ids' => [$a]])['type']);
        self::assertStringContainsString('Ничего не выбрано', $bulk->submit(OwnerInviteBulkAction::RESTORE, ['form_key' => $bulk->issueKey()])['message']);
        self::assertNotNull($this->db->selectOne('SELECT trashed_at FROM test_invites WHERE id = ? AND trashed_at IS NOT NULL', [$a]));

        $restore = $bulk->submit(OwnerInviteBulkAction::RESTORE, ['form_key' => $bulk->issueKey(), 'invite_id' => $a, 'invite_ids' => [$b]]);
        self::assertSame('success', $restore['type']);
        self::assertStringContainsString('Восстановлено: 1 приглашение', $restore['message']);
        self::assertNotNull($this->db->selectOne('SELECT trashed_at FROM test_invites WHERE id = ? AND trashed_at IS NOT NULL', [$b]), 'Кнопка строки главнее галочек.');
    }

    public function testPurgeThroughTheBulkActionNeedsTheConfirmationFieldAndTheTrash(): void
    {
        $session = [];
        $bulk = new OwnerInviteBulkAction($this->invites, $this->cases, new FormOnce($session));
        $case = $this->completedCase('k8 purge bulk');

        $withoutTick = $bulk->submit(OwnerInviteBulkAction::PURGE, ['form_key' => $bulk->issueKey(), 'invite_id' => $case]);
        self::assertSame('error', $withoutTick['type']);
        self::assertNotNull($this->db->selectOne('SELECT id FROM test_invites WHERE id = ?', [$case]));

        $notInTrash = $bulk->submit(OwnerInviteBulkAction::PURGE, ['form_key' => $bulk->issueKey(), 'invite_id' => $case, 'confirm_delete' => 'delete']);
        self::assertSame('error', $notInTrash['type']);
        self::assertNotNull($this->db->selectOne('SELECT id FROM test_invites WHERE id = ?', [$case]));

        $this->invites->trash([$case]);
        $done = $bulk->submit(OwnerInviteBulkAction::PURGE, ['form_key' => $bulk->issueKey(), 'invite_id' => $case, 'confirm_delete' => 'delete']);
        self::assertSame('success', $done['type']);
        self::assertNull($this->db->selectOne('SELECT id FROM test_invites WHERE id = ?', [$case]));
    }

    /** @return list<string> */
    private function slugs(): array
    {
        return array_column($this->db->select('SELECT slug FROM tests'), 'slug');
    }

    /** @return list<string> */
    private function ids(InviteFilter $filter): array
    {
        return array_column($this->invites->listForOwner($filter), 'id');
    }

    private function testId(string $slug): int
    {
        return (int) $this->db->selectOne('SELECT id FROM tests WHERE slug = ?', [$slug])['id'];
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

    private function sessionOf(string $inviteId): string
    {
        return (string) $this->db->selectOne('SELECT claimed_session_id FROM test_invites WHERE id = ?', [$inviteId])['claimed_session_id'];
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
