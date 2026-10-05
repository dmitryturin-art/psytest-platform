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
use PsyTest\Core\ReportMarkdown;
use PsyTest\Core\RetentionPolicy;
use PsyTest\Core\SessionManager;
use PsyTest\Core\TemplateFunctions;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * История версий профессионального заключения, только чтение (07.K7).
 *
 * Версии собираются так же, как это делает контроллер: сервис ревизий плюс
 * ReportMarkdown. Внешний провайдер не вызывается.
 */
#[Group('database')]
final class OwnerProfessionalVersionsTest extends TestCase
{
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
        ], 'id = ?', [$this->sessionId]);
    }

    protected function tearDown(): void
    {
        if ($this->sessionId !== '') {
            $this->db->delete('test_sessions', 'id = ?', [$this->sessionId]);
        }
    }

    public function testVersionsListIsNewestFirstRendersMarkdownAndHasNoForms(): void
    {
        $reportId = $this->readyProfessional('## Первое заключение');
        $this->revisions->seedFromContent($reportId, '## Второе заключение');
        $this->revisions->seedFromContent($reportId, "## Третье заключение\n\n- **важный** пункт");

        self::assertSame(3, $this->revisions->count($reportId));

        $html = $this->renderVersions($reportId);

        self::assertLessThan(strpos($html, 'Версия №2'), strpos($html, 'Версия №3'));
        self::assertLessThan(strpos($html, 'Версия №1'), strpos($html, 'Версия №2'));
        self::assertSame(3, substr_count($html, 'class="owner-revision"'));
        self::assertSame(3, substr_count($html, 'черновик модели'));
        self::assertStringContainsString('<strong>важный</strong>', $html);
        self::assertStringNotContainsString('<pre', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('<button', $html);
        self::assertStringNotContainsString('<textarea', $html);
        self::assertStringNotContainsString('/restore', $html);
        self::assertStringNotContainsString('/publish', $html);
        self::assertStringContainsString('← К кейсу', $html);
    }

    public function testSpecialistEditsAreLabelledSeparately(): void
    {
        $reportId = $this->readyProfessional('Текст модели.');
        $this->revisions->save($reportId, 'Правка.');

        self::assertStringContainsString('правка специалиста', $this->renderVersions($reportId));
    }

    public function testCaseCardLinksToVersionsOnlyWhenThereIsMoreThanOne(): void
    {
        $reportId = $this->readyProfessional('Одна версия.');
        $one = $this->renderCard($reportId, $this->revisions->count($reportId));
        self::assertStringNotContainsString('/versions', $one);
        self::assertStringNotContainsString('Версии (', $one);

        $this->revisions->seedFromContent($reportId, 'Вторая.');
        $this->revisions->seedFromContent($reportId, 'Третья.');
        $three = $this->renderCard($reportId, $this->revisions->count($reportId));
        self::assertStringContainsString(
            'href="/admin/invited-case/' . $this->sessionId . '/reports/' . $reportId . '/versions">Версии (3)</a>',
            $three,
        );
    }

    public function testControllerGuardsOwnerSessionOwnershipAndClearRedirect(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/OwnerController.php');
        $start = (int) strpos($controller, 'public function caseReportVersions(');
        $action = substr($controller, $start, (int) strpos($controller, 'public function saveCaseReportRevision(') - $start);

        // Гость без сессии владельца: ownedCase() -> requireOwner() -> редирект на вход / 404.
        self::assertStringContainsString('$this->ownedCase($sessionId)', $action);
        // Чужой отчёт — 404.
        self::assertStringContainsString("(string) \$report['session_id'] !== \$sessionId", $action);
        self::assertStringContainsString('$this->notFound()', $action);
        // Понятный разбор — в существующий редактор.
        self::assertMatchesRegularExpression('#KIND_CLEAR\)\s*\{\s*\$this->redirect\([^;]*/edit\'\)#', $action);
        self::assertStringContainsString("header('X-Robots-Tag: noindex, nofollow')", $action);
        // Страница только читает.
        foreach (['->save(', '->publish(', '->restore(', '->unpublish(', '->insert(', '->update('] as $write) {
            self::assertStringNotContainsString($write, $action, $write);
        }
        self::assertStringContainsString(
            "\$router->get('/admin/invited-case/{sessionId}/reports/{reportId}/versions'",
            (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php'),
        );
    }

    private function readyProfessional(string $content): string
    {
        $prompt = new Prompt('bdi', 'individual', Prompt::KIND_PROFESSIONAL, 1, Prompt::STATUS_PUBLISHED, 'Текст промпта.', false, 'fixture');
        $job = $this->reports->request($this->sessionId, 'bdi', 'individual', Prompt::KIND_PROFESSIONAL, $prompt, ['scales' => []]);
        $reportId = (string) $job['id'];
        $this->reports->markReady($reportId, new AiCompletion($content, 'fixture/requested', 'fixture/served', 1, 2));

        return $reportId;
    }

    private function twig(): Environment
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), [
            'cache' => false,
            'strict_variables' => true,
        ]);
        TemplateFunctions::register($twig);

        return $twig;
    }

    private function renderVersions(string $reportId): string
    {
        $versions = [];
        foreach (array_reverse($this->revisions->revisions($reportId)) as $revision) {
            $revision['html'] = ReportMarkdown::toHtml((string) $revision['content']);
            $versions[] = $revision;
        }

        return $this->twig()->render('owner-report-versions.twig', [
            'appName' => 'PsyTest',
            'basePath' => '',
            'csrf_token' => 'synthetic-csrf-token',
            'session_id' => $this->sessionId,
            'case' => ['test_name' => 'Шкала Бека', 'client_label' => 'Клиент А'],
            'versions' => $versions,
        ]);
    }

    private function renderCard(string $reportId, int $count): string
    {
        return $this->twig()->render('owner-invited-case.twig', [
            'appName' => 'PsyTest',
            'basePath' => '',
            'csrf_token' => 'synthetic-csrf-token',
            'flash' => null,
            'case' => [
                'id' => $this->sessionId,
                'test_name' => 'Шкала Бека',
                'status' => 'completed',
                'claimed_at' => '2026-09-14 10:00:00',
                'completed_at' => '2026-09-14 10:20:00',
                'client_id' => null,
                'client_label' => null,
                'owner_note' => null,
                'answer_rows' => [],
                'result_sections' => [],
                'pair' => null,
            ],
            'ai' => [
                'available' => true,
                'has_jobs' => true,
                'ai_disabled' => false,
                'owner_context_max' => 4000,
                'kinds' => [[
                    'kind' => 'professional',
                    'title' => 'Профессиональное заключение',
                    'report_id' => $reportId,
                    'status' => 'ready',
                    'failure_reason' => null,
                    'html' => '<p>Текст</p>',
                    'published' => null,
                    'versions_count' => $count,
                ]],
            ],
            'order_keys' => ['professional' => 'k', 'clear' => 'k', 'all' => 'k'],
            'notify' => ['published' => false, 'has_email' => false, 'client_id' => null, 'last_at' => null],
        ]);
    }
}
