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
use PsyTest\Core\TherapistClientService;
use Ramsey\Uuid\Uuid;

#[Group('database')]
final class TherapistClientServiceTest extends TestCase
{
    private Database $db;
    private SessionManager $sessions;
    private SessionLifecycleService $lifecycle;
    private TestInviteService $invites;
    private TherapistClientService $clients;
    private string $storagePath;

    /** @var list<string> */
    private array $clientIds = [];

    /** @var list<string> */
    private array $sessionIds = [];

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->sessions = new SessionManager($this->db);
        $this->storagePath = sys_get_temp_dir() . '/psytest-therapist-client-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0700, true);
        $this->lifecycle = new SessionLifecycleService($this->db, new RetentionPolicy(180), $this->storagePath);
        $this->invites = new TestInviteService($this->db, $this->sessions);
        $this->clients = new TherapistClientService($this->db, $this->lifecycle);
    }

    protected function tearDown(): void
    {
        foreach ($this->clientIds as $clientId) {
            $this->db->delete('therapist_clients', 'id = ?', [$clientId]);
        }
        foreach ($this->sessionIds as $sessionId) {
            $this->db->delete('test_sessions', 'id = ?', [$sessionId]);
        }
        foreach (glob($this->storagePath . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storagePath);
    }

    public function testClientCardCollectsItsAssignmentsAndCompletedHistory(): void
    {
        $clientId = $this->createClient('Клиент А', 'Работаем с тревогой');
        $other = $this->createClient('Клиент Б', '');

        $pending = $this->invites->create($this->testId('hads'), 'Не открыто', $clientId);
        $claimed = $this->invites->create($this->testId('bdi'), 'Динамика недели 1', $clientId);
        $claim = $this->invites->claim($claimed['token']);
        self::assertNotNull($claim);
        $sessionId = (string) $claim['session']['id'];
        $this->sessionIds[] = $sessionId;
        $this->sessions->saveAnswers($sessionId, ['q1' => 1]);
        $this->sessions->completeSession($sessionId, ['score' => 1]);

        $card = $this->clients->findForOwner($clientId);
        self::assertNotNull($card);
        self::assertSame('Клиент А', $card['client']['label']);
        self::assertSame('Работаем с тревогой', $card['client']['note']);
        self::assertCount(2, $card['assignments']);

        $byId = [];
        foreach ($card['assignments'] as $assignment) {
            $byId[$assignment['id']] = $assignment;
        }
        self::assertSame('pending', $byId[$pending['id']]['display_status']);
        self::assertSame('completed', $byId[$claimed['id']]['display_status']);
        self::assertSame($sessionId, $byId[$claimed['id']]['claimed_session_id']);
        self::assertSame('Динамика недели 1', $byId[$claimed['id']]['owner_note']);

        self::assertCount(1, $card['history']);
        self::assertSame($sessionId, $card['history'][0]['claimed_session_id']);

        $list = $this->labelledList();
        self::assertSame(2, (int) $list['Клиент А']['assignment_count']);
        self::assertSame(1, (int) $list['Клиент А']['completed_count']);
        self::assertSame(0, (int) $list['Клиент Б']['assignment_count']);

        self::assertTrue($this->clients->update($clientId, 'Клиент А (обновлён)', ''));
        $updated = $this->clients->findForOwner($clientId);
        self::assertNotNull($updated);
        self::assertSame('Клиент А (обновлён)', $updated['client']['label']);
        self::assertNull($updated['client']['note']);

        self::assertNull($this->clients->findForOwner(Uuid::uuid4()->toString()));
        self::assertNotNull($this->clients->findForOwner($other));
    }

    public function testDeletingAClientErasesItsCasesArtifactsAndNotesButSparesTheNeighbour(): void
    {
        $clientId = $this->createClient('Удаляемый клиент', 'Заметка владельца');
        $neighbourId = $this->createClient('Соседний клиент', '');

        $invite = $this->invites->create($this->testId('bdi'), 'Заметка назначения', $clientId);
        $claim = $this->invites->claim($invite['token']);
        self::assertNotNull($claim);
        $sessionId = (string) $claim['session']['id'];
        $this->sessions->completeSession($sessionId, ['score' => 3]);

        $neighbourInvite = $this->invites->create($this->testId('hads'), 'Соседняя заметка', $neighbourId);
        $neighbourClaim = $this->invites->claim($neighbourInvite['token']);
        self::assertNotNull($neighbourClaim);
        $neighbourSessionId = (string) $neighbourClaim['session']['id'];
        $this->sessionIds[] = $neighbourSessionId;

        $pdf = $this->storagePath . '/result_' . $sessionId . '.pdf';
        file_put_contents($pdf, 'result');
        $reportId = $this->insertAiReport($sessionId);

        self::assertTrue($this->clients->delete($clientId));
        self::assertFalse($this->clients->delete($clientId), 'Repeated deletion must be safe.');

        self::assertNull($this->db->selectOne('SELECT id FROM therapist_clients WHERE id = ?', [$clientId]));
        self::assertNull($this->db->selectOne('SELECT id FROM test_sessions WHERE id = ?', [$sessionId]));
        self::assertNull($this->db->selectOne('SELECT id FROM test_invites WHERE id = ?', [$invite['id']]));
        self::assertNull($this->db->selectOne('SELECT id FROM ai_reports WHERE id = ?', [$reportId]));
        self::assertFileDoesNotExist($pdf);
        self::assertSame(
            0,
            (int) $this->db->selectOne(
                'SELECT COUNT(*) AS total FROM test_invites WHERE owner_note = ?',
                ['Заметка назначения'],
            )['total'],
        );

        self::assertNotNull($this->db->selectOne('SELECT id FROM therapist_clients WHERE id = ?', [$neighbourId]));
        self::assertNotNull($this->db->selectOne('SELECT id FROM test_sessions WHERE id = ?', [$neighbourSessionId]));
        self::assertNotNull($this->db->selectOne('SELECT id FROM test_invites WHERE id = ?', [$neighbourInvite['id']]));

        $audit = $this->db->select(
            'SELECT session_id, test_id, details FROM activity_log WHERE action = ? ORDER BY id DESC LIMIT 1',
            ['therapist_client_deleted'],
        );
        self::assertNotEmpty($audit);
        self::assertNull($audit[0]['session_id']);
        self::assertNull($audit[0]['test_id']);
        self::assertSame(['actor' => 'owner'], json_decode((string) $audit[0]['details'], true, flags: JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Удаляемый клиент', (string) $audit[0]['details']);
    }

    public function testDeletingASessionAlsoRemovesItsInvitationSoTheOwnerNoteCannotOutliveTheCase(): void
    {
        $clientId = $this->createClient('Клиент с удалённым кейсом', '');
        $invite = $this->invites->create($this->testId('bdi'), 'Заметка, которая не должна пережить кейс', $clientId);
        $claim = $this->invites->claim($invite['token']);
        self::assertNotNull($claim);
        $sessionId = (string) $claim['session']['id'];

        self::assertTrue($this->lifecycle->deleteSessionAndArtifacts($sessionId));
        self::assertNull($this->db->selectOne('SELECT id FROM test_invites WHERE id = ?', [$invite['id']]));

        $card = $this->clients->findForOwner($clientId);
        self::assertNotNull($card);
        self::assertSame([], $card['assignments']);
        self::assertSame([], $card['history']);
    }

    public function testVisitorSelfDeletionAlsoRemovesTheInvitationAndShowsTheCaseAsDeleted(): void
    {
        $clientId = $this->createClient('Клиент, удаливший результат сам', '');
        $invite = $this->invites->create($this->testId('bdi'), 'Заметка владельца', $clientId);
        $claim = $this->invites->claim($invite['token']);
        self::assertNotNull($claim);
        $sessionId = (string) $claim['session']['id'];
        $this->sessionIds[] = $sessionId;

        // Путь посетителя: soft-delete через SessionManager, а не lifecycle.
        self::assertTrue($this->sessions->deleteSession($sessionId));

        self::assertNull($this->db->selectOne('SELECT id FROM test_invites WHERE id = ?', [$invite['id']]));
        self::assertNull($this->invites->claimedCaseForOwner($sessionId));
        $card = $this->clients->findForOwner($clientId);
        self::assertNotNull($card);
        self::assertSame([], $card['assignments']);

        $deleted = TestInviteService::withDisplayStatus([[
            'id' => 'soft-deleted',
            'status' => 'claimed',
            'claimed_session_id' => $sessionId,
            'session_status' => 'deleted',
            'expires_at' => '2000-01-01 00:00:00',
        ]]);
        self::assertSame('result_deleted', $deleted[0]['display_status']);
    }

    public function testAClaimedInviteWithoutItsSessionIsShownAsDeletedResultWithoutACaseLink(): void
    {
        $orphan = TestInviteService::withDisplayStatus([[
            'id' => 'legacy',
            'status' => 'claimed',
            'claimed_session_id' => null,
            'session_status' => null,
            'expires_at' => '2000-01-01 00:00:00',
        ]]);

        self::assertSame('result_deleted', $orphan[0]['display_status']);
        self::assertNull($orphan[0]['claimed_session_id']);
    }

    public function testInvitationsRejectAnUnknownClientAndStayValidWithoutOne(): void
    {
        $withoutClient = $this->invites->create($this->testId('bdi'), 'Без карточки');
        $row = $this->db->selectOne('SELECT client_id FROM test_invites WHERE id = ?', [$withoutClient['id']]);
        self::assertNotNull($row);
        self::assertNull($row['client_id']);
        $this->db->delete('test_invites', 'id = ?', [$withoutClient['id']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->invites->create($this->testId('bdi'), '', Uuid::uuid4()->toString());
    }

    public function testLabelIsRequiredAndBounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->clients->create('   ', '');
    }

    /**
     * Email карточки: необязательный, нормализованный и удаляемый (D-054).
     *
     * Он единственный контакт, который платформа вообще хранит, поэтому важно,
     * что пустое поле его стирает, а мусор в него не попадает.
     */
    public function testClientEmailIsOptionalNormalisedValidatedAndErasable(): void
    {
        $withoutEmail = $this->createClient('Без адреса', '');
        $card = $this->clients->findForOwner($withoutEmail);
        self::assertNotNull($card);
        self::assertNull($card['client']['email']);
        self::assertFalse($this->clients->hasEmail($withoutEmail));

        $id = $this->clients->create('С адресом', '', '  Client@Example.TEST ');
        $this->clientIds[] = $id;
        $card = $this->clients->findForOwner($id);
        self::assertNotNull($card);
        self::assertSame('client@example.test', $card['client']['email'], 'Адрес хранится в одном виде.');
        self::assertTrue($this->clients->hasEmail($id));

        // Пустое поле — это «уведомлять некуда», а не «оставить как было».
        self::assertTrue($this->clients->update($id, 'С адресом', '', ''));
        $card = $this->clients->findForOwner($id);
        self::assertNotNull($card);
        self::assertNull($card['client']['email']);
        self::assertFalse($this->clients->hasEmail($id));

        self::assertFalse($this->clients->hasEmail(null));
        self::assertFalse($this->clients->hasEmail('not-a-uuid'));

        $this->expectException(\InvalidArgumentException::class);
        $this->clients->update($id, 'С адресом', '', 'не адрес');
    }

    public function testDeletingAClientTakesItsEmailAwayWithTheCard(): void
    {
        $id = $this->createClient('Уходит вместе с адресом', '', 'erased@example.test');
        self::assertTrue($this->clients->hasEmail($id));

        self::assertTrue($this->clients->delete($id));
        self::assertSame(
            0,
            (int) $this->db->selectOne(
                'SELECT COUNT(*) AS total FROM therapist_clients WHERE email = ?',
                ['erased@example.test'],
            )['total'],
        );
    }

    public function testEmailLongerThanTheColumnIsRejectedInsteadOfBeingTruncated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->clients->create('Длинный адрес', '', str_repeat('a', 250) . '@example.test');
    }

    /**
     * Долг K2: карточка с несколькими кейсами удаляет документы после commit.
     *
     * Удаление идёт одной транзакцией, а `unlink` — только после неё. Тест
     * держит проводку: обе сессии ушли, оба PDF ушли следом, и список
     * отложенных файлов не остался висеть внутри сервиса.
     */
    public function testDeletingACardWithSeveralCasesRemovesEveryArtifactAfterTheCommit(): void
    {
        $clientId = $this->createClient('Клиент с двумя кейсами', '');

        $files = [];
        foreach (['bdi', 'hads'] as $slug) {
            $invite = $this->invites->create($this->testId($slug), 'Назначение ' . $slug, $clientId);
            $claim = $this->invites->claim($invite['token']);
            self::assertNotNull($claim);
            $sessionId = (string) $claim['session']['id'];
            $this->sessions->completeSession($sessionId, ['score' => 2]);

            $file = $this->storagePath . '/result_' . $sessionId . '.pdf';
            file_put_contents($file, 'result');
            $files[$sessionId] = $file;
        }

        self::assertTrue($this->clients->delete($clientId));

        foreach ($files as $sessionId => $file) {
            self::assertNull($this->db->selectOne('SELECT id FROM test_sessions WHERE id = ?', [$sessionId]));
            self::assertFileDoesNotExist($file);
        }

        // Ничего не осталось в отложенном списке: повторный сброс — no-op.
        $this->lifecycle->flushPendingArtifacts();
    }

    private function createClient(string $label, string $note, string $email = ''): string
    {
        $id = $this->clients->create($label, $note, $email);
        $this->clientIds[] = $id;

        return $id;
    }

    /** @return array<string, array<string, mixed>> */
    private function labelledList(): array
    {
        $byLabel = [];
        foreach ($this->clients->listForOwner() as $client) {
            $byLabel[(string) $client['label']] = $client;
        }

        return $byLabel;
    }

    private function insertAiReport(string $sessionId): string
    {
        $id = Uuid::uuid4()->toString();
        $this->db->insert('ai_reports', [
            'id' => $id,
            'session_id' => $sessionId,
            'test_slug' => 'bdi',
            'mode' => 'individual',
            'report_kind' => 'professional',
            'prompt_key' => 'test-fixture',
            'prompt_version' => 1,
            'status' => 'ready',
            'content' => 'Синтетический текст разбора',
        ]);

        return $id;
    }

    private function testId(string $slug): int
    {
        return (int) $this->db->selectOne('SELECT id FROM tests WHERE slug = ?', [$slug])['id'];
    }
}
