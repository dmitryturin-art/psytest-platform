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
use PsyTest\Core\ResultPresenter;
use PsyTest\Core\RetentionPolicy;
use PsyTest\Core\SessionManager;
use PsyTest\Core\TemplateFunctions;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Что именно видит клиент специалиста на своей единственной странице (D-054).
 *
 * Проверяется собранный HTML, а не промежуточный массив: граница «клиент не
 * получает неодобренный черновик» держится ровно там, где текст попадает на
 * страницу.
 */
#[Group('database')]
final class PublishedReportClientPageTest extends TestCase
{
    private const DRAFT = 'Черновик модели, который клиент видеть не должен.';
    private const APPROVED = 'Одобренная специалистом редакция.';
    private const PROFESSIONAL = 'Профессиональное заключение для специалиста.';
    private const UNPUBLISHED = 'Незаконченная правка специалиста.';

    private Database $db;
    private SessionManager $sessions;
    private AiReportRepository $reports;
    private AiReportRevisionService $revisions;
    private string $sessionId = '';

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->sessions = new SessionManager($this->db);
        $this->reports = new AiReportRepository($this->db);
        $this->revisions = new AiReportRevisionService($this->db);

        $test = $this->db->selectOne("SELECT id FROM tests WHERE slug = 'bdi'");
        self::assertIsArray($test, 'Предусловие: методика BDI зарегистрирована.');

        $session = $this->sessions->createSession((int) $test['id']);
        $this->sessionId = (string) $session['id'];
        $this->db->update('test_sessions', [
            'status' => 'completed',
            'retention_class' => RetentionPolicy::THERAPIST_CASE,
            'calculated_results' => json_encode(['total_score' => 7], JSON_UNESCAPED_UNICODE),
        ], 'id = ?', [$this->sessionId]);
    }

    protected function tearDown(): void
    {
        if ($this->sessionId !== '') {
            $this->db->delete('test_sessions', 'id = ?', [$this->sessionId]);
            $this->sessionId = '';
        }
    }

    public function testWithoutPublicationTheClientSeesWaitingAndNoDraftText(): void
    {
        $this->readyReport(Prompt::KIND_CLEAR, self::DRAFT);
        $html = $this->renderClientPage();

        self::assertStringContainsString('расширенный разбор появится после проверки специалиста', mb_strtolower($html));
        self::assertStringNotContainsString(self::DRAFT, $html);
    }

    public function testPublishedRevisionIsShownAndNothingElseIs(): void
    {
        $clearReport = $this->readyReport(Prompt::KIND_CLEAR, self::DRAFT);
        $this->readyReport(Prompt::KIND_PROFESSIONAL, self::PROFESSIONAL);

        $this->revisions->save($clearReport, self::APPROVED);
        $approved = (string) $this->revisions->revisions($clearReport)[1]['id'];
        self::assertTrue($this->revisions->publish($clearReport, $approved));
        $this->revisions->save($clearReport, self::UNPUBLISHED);

        $html = $this->renderClientPage();

        self::assertStringContainsString('Разбор специалиста', $html);
        self::assertStringContainsString(self::APPROVED, $html);
        self::assertStringNotContainsString(self::DRAFT, $html);
        self::assertStringNotContainsString(self::PROFESSIONAL, $html);
        self::assertStringNotContainsString(self::UNPUBLISHED, $html);
        // Заказ разбора и статусы заданий остаются вне клиентской страницы (K0b).
        self::assertStringNotContainsString('name="ai_consent"', $html);
    }

    public function testPublishedRevisionAlsoReachesThePrintableDocument(): void
    {
        $clearReport = $this->readyReport(Prompt::KIND_CLEAR, self::DRAFT);
        $this->revisions->save($clearReport, self::APPROVED);
        $this->revisions->publish($clearReport, (string) $this->revisions->revisions($clearReport)[1]['id']);

        $session = $this->sessions->getSessionById($this->sessionId);
        self::assertIsArray($session);

        $module = new \PsyTest\Modules\BeckDepression\BeckDepressionModule();
        $printable = (new ResultPresenter($this->db, $this->sessions))->pdfSections($session, $module);

        self::assertStringContainsString('Разбор специалиста', $printable['published_report_html']);
        self::assertStringContainsString(self::APPROVED, $printable['published_report_html']);
        self::assertStringNotContainsString(self::DRAFT, $printable['published_report_html']);

        $this->revisions->unpublish($clearReport);
        self::assertSame(
            '',
            (new ResultPresenter($this->db, $this->sessions))->pdfSections($session, $module)['published_report_html'],
        );
    }

    // ---------------------------------------------------------------- fixtures

    private function readyReport(string $kind, string $content): string
    {
        $prompt = new Prompt('bdi', 'individual', $kind, 1, Prompt::STATUS_PUBLISHED, 'Текст промпта.', false, 'fixture');
        $job = $this->reports->request($this->sessionId, 'bdi', 'individual', $kind, $prompt, ['scales' => []]);
        $reportId = (string) $job['id'];

        $this->reports->markReady($reportId, new AiCompletion($content, 'fixture/requested', 'fixture/served', 1, 2));

        return $reportId;
    }

    private function renderClientPage(): string
    {
        $session = $this->sessions->getSessionById($this->sessionId);
        self::assertIsArray($session);

        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), [
            'cache' => false,
            'strict_variables' => true,
        ]);
        TemplateFunctions::register($twig);

        return $twig->render('result-layout.twig', [
            'appName' => 'PsyTest',
            'basePath' => '',
            'csrf_token' => 'synthetic-csrf-token',
            'test' => ['name' => 'Шкала депрессии Бека', 'slug' => 'bdi'],
            'session' => $session,
            'sections' => [],
            'clinical_safety_notice' => null,
            'ai_report' => (new ResultPresenter($this->db, $this->sessions))->reportViewData('bdi', $session),
            'result_base' => '/result/bdi/' . $session['session_token'],
            'account_view' => false,
            'visitor_account' => null,
        ]);
    }
}
