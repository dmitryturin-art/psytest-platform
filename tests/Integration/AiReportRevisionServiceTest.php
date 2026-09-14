<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Ai\AiCompletion;
use PsyTest\Core\Ai\AiReportRepository;
use PsyTest\Core\Ai\AiReportRevisionService;
use PsyTest\Core\Ai\Prompt;
use PsyTest\Core\Database;
use PsyTest\Core\RetentionPolicy;
use PsyTest\Core\SessionManager;

/**
 * История версий разбора и публикация одобренной редакции (D-054).
 *
 * Клиент специалиста не должен получить неодобренный черновик ни при какой
 * последовательности действий (AGENTS, PRODUCT_RULES §4), поэтому здесь
 * проверяется не «сохранилось ли», а что именно считается опубликованным.
 *
 * Внешний провайдер не вызывается: готовый отчёт записывается напрямую.
 */
#[Group('database')]
final class AiReportRevisionServiceTest extends TestCase
{
    private Database $db;
    private SessionManager $sessions;
    private AiReportRepository $reports;
    private AiReportRevisionService $revisions;
    private int $testId = 0;
    /** @var list<string> */
    private array $createdSessions = [];

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->sessions = new SessionManager($this->db);
        $this->reports = new AiReportRepository($this->db);
        $this->revisions = new AiReportRevisionService($this->db);

        $test = $this->db->selectOne("SELECT id FROM tests WHERE slug = 'bdi'");
        self::assertIsArray($test, 'Предусловие: методика BDI зарегистрирована.');
        $this->testId = (int) $test['id'];
    }

    protected function tearDown(): void
    {
        foreach ($this->createdSessions as $sessionId) {
            $this->db->delete('test_sessions', 'id = ?', [$sessionId]);
        }
        $this->createdSessions = [];
    }

    public function testMarkReadyStoresTheModelDraftAsRevisionOne(): void
    {
        $reportId = $this->readyReport(Prompt::KIND_CLEAR, RetentionPolicy::THERAPIST_CASE, 'Черновик модели.');

        $revisions = $this->revisions->revisions($reportId);
        self::assertCount(1, $revisions);
        self::assertSame(1, (int) $revisions[0]['revision_no']);
        self::assertSame(AiReportRevisionService::SOURCE_AI, $revisions[0]['source']);
        self::assertSame('Черновик модели.', $revisions[0]['content']);
    }

    public function testSaveAndRestoreNumberInOrderAndNeverRewriteAnOlderRevision(): void
    {
        $reportId = $this->readyReport(Prompt::KIND_CLEAR, RetentionPolicy::THERAPIST_CASE, 'Черновик модели.');

        $this->revisions->save($reportId, 'Правка специалиста.');
        $firstRevisionId = (string) $this->revisions->revisions($reportId)[0]['id'];
        $this->revisions->restore($reportId, $firstRevisionId);

        $revisions = $this->revisions->revisions($reportId);
        self::assertSame([1, 2, 3], array_map(static fn (array $r): int => (int) $r['revision_no'], $revisions));
        self::assertSame(
            [AiReportRevisionService::SOURCE_AI, AiReportRevisionService::SOURCE_OWNER, AiReportRevisionService::SOURCE_OWNER],
            array_map(static fn (array $r): string => (string) $r['source'], $revisions),
        );
        // Восстановление — копия, а не откат: исходные версии остались как были.
        self::assertSame('Черновик модели.', $revisions[0]['content']);
        self::assertSame('Правка специалиста.', $revisions[1]['content']);
        self::assertSame('Черновик модели.', $revisions[2]['content']);
    }

    public function testEmptyTextIsRejected(): void
    {
        $reportId = $this->readyReport(Prompt::KIND_CLEAR, RetentionPolicy::THERAPIST_CASE, 'Черновик модели.');

        $this->expectException(\InvalidArgumentException::class);
        $this->revisions->save($reportId, "   \n  ");
    }

    public function testOversizedTextIsRejected(): void
    {
        $reportId = $this->readyReport(Prompt::KIND_CLEAR, RetentionPolicy::THERAPIST_CASE, 'Черновик модели.');

        $this->expectException(\InvalidArgumentException::class);
        $this->revisions->save($reportId, str_repeat('я', AiReportRevisionService::CONTENT_MAX_LENGTH + 1));
    }

    public function testOnlyClearReportsOfTherapistCasesCanBePublished(): void
    {
        $professional = $this->readyReport(Prompt::KIND_PROFESSIONAL, RetentionPolicy::THERAPIST_CASE, 'Заключение.');
        $anonymous = $this->readyReport(Prompt::KIND_CLEAR, RetentionPolicy::ANONYMOUS, 'Разбор посетителя.');

        self::assertFalse(
            $this->revisions->publish($professional, (string) $this->revisions->revisions($professional)[0]['id']),
            'Профессиональное заключение адресовано специалисту и клиенту не публикуется.',
        );
        self::assertFalse(
            $this->revisions->publish($anonymous, (string) $this->revisions->revisions($anonymous)[0]['id']),
            'У анонимного посетителя нет специалиста, который что-либо одобряет.',
        );
    }

    public function testPublishedContentIsExactlyTheApprovedRevisionAndNotTheLatestOne(): void
    {
        $sessionId = $this->therapistSession();
        $reportId = $this->readyReportFor($sessionId, Prompt::KIND_CLEAR, 'Черновик модели.');

        self::assertNull($this->revisions->publishedContent($sessionId), 'До публикации клиенту нечего показывать.');

        $this->revisions->save($reportId, 'Одобренная редакция.');
        $approved = (string) $this->revisions->revisions($reportId)[1]['id'];
        self::assertTrue($this->revisions->publish($reportId, $approved));

        $published = $this->revisions->publishedContent($sessionId);
        self::assertIsArray($published);
        self::assertSame('Одобренная редакция.', $published['content']);
        self::assertSame(2, $published['revision_no']);

        // Новая правка без публикации не должна доехать до клиента.
        $this->revisions->save($reportId, 'Незаконченная правка.');
        self::assertSame('Одобренная редакция.', (string) $this->revisions->publishedContent($sessionId)['content']);

        self::assertTrue($this->revisions->unpublish($reportId));
        self::assertNull($this->revisions->publishedContent($sessionId));
    }

    public function testDeletingTheSessionRemovesEveryRevision(): void
    {
        $sessionId = $this->therapistSession();
        $reportId = $this->readyReportFor($sessionId, Prompt::KIND_CLEAR, 'Черновик модели.');
        $this->revisions->save($reportId, 'Правка специалиста.');

        $this->db->delete('test_sessions', 'id = ?', [$sessionId]);

        self::assertSame([], $this->revisions->revisions($reportId));
        self::assertNull($this->db->selectOne('SELECT id FROM ai_reports WHERE id = ?', [$reportId]));
    }

    // ---------------------------------------------------------------- fixtures

    private function therapistSession(string $retentionClass = RetentionPolicy::THERAPIST_CASE): string
    {
        $session = $this->sessions->createSession($this->testId);
        $sessionId = (string) $session['id'];
        $this->createdSessions[] = $sessionId;

        $this->db->update('test_sessions', [
            'status' => 'completed',
            'retention_class' => $retentionClass,
            'calculated_results' => json_encode(['total_score' => 7], JSON_UNESCAPED_UNICODE),
        ], 'id = ?', [$sessionId]);

        return $sessionId;
    }

    private function readyReport(string $kind, string $retentionClass, string $content): string
    {
        return $this->readyReportFor($this->therapistSession($retentionClass), $kind, $content);
    }

    private function readyReportFor(string $sessionId, string $kind, string $content): string
    {
        $prompt = new Prompt('bdi', 'individual', $kind, 1, Prompt::STATUS_PUBLISHED, 'Текст промпта.', false, 'fixture');
        $job = $this->reports->request($sessionId, 'bdi', 'individual', $kind, $prompt, ['scales' => []]);
        $reportId = (string) $job['id'];

        $this->reports->markReady($reportId, new AiCompletion($content, 'fixture/requested', 'fixture/served', 1, 2));

        return $reportId;
    }
}
