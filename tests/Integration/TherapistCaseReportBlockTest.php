<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Ai\AiCompletion;
use PsyTest\Core\Ai\AiReportRepository;
use PsyTest\Core\Ai\AiReportRevisionService;
use PsyTest\Core\Ai\Prompt;
use PsyTest\Core\Ai\PromptRegistry;
use PsyTest\Core\Database;
use PsyTest\Core\ResultPresenter;
use PsyTest\Core\RetentionPolicy;
use PsyTest\Core\SessionManager;
use PsyTest\Core\TemplateFunctions;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Блок «Расширенный разбор» у клиента специалиста (07.K6c).
 *
 * Фраза ожидания показывается, только если специалист действительно заказал
 * понятный разбор. Без заказа — в том числе у методик без промптов, как BAI, —
 * блока нет: клиент не должен ждать того, что не появится.
 */
#[Group('database')]
final class TherapistCaseReportBlockTest extends TestCase
{
    private const WAITING = 'расширенный разбор появится после проверки специалиста';
    private const DRAFT = 'Черновик модели, который клиент видеть не должен.';
    private const APPROVED = 'Одобренная специалистом редакция.';

    private Database $db;
    private SessionManager $sessions;
    private AiReportRepository $reports;
    /** @var list<string> */
    private array $sessionIds = [];

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->sessions = new SessionManager($this->db);
        $this->reports = new AiReportRepository($this->db);
    }

    protected function tearDown(): void
    {
        foreach ($this->sessionIds as $id) {
            $this->db->delete('test_sessions', 'id = ?', [$id]);
        }
        $this->sessionIds = [];
    }

    public function testBaiTherapistCaseWithoutOrderHasNoBlock(): void
    {
        $session = $this->completedSession('beck-anxiety', RetentionPolicy::THERAPIST_CASE);

        self::assertNull($this->presenter()->reportViewData('beck-anxiety', $session));

        $html = $this->renderClientPage('beck-anxiety', $session);
        self::assertStringNotContainsString(self::WAITING, mb_strtolower($html));
        self::assertStringNotContainsString('class="ai-report"', $html);
    }

    public function testSmilTherapistCaseWithoutOrderHasNoBlock(): void
    {
        $session = $this->completedSession('smil', RetentionPolicy::THERAPIST_CASE);

        self::assertNull($this->presenter()->reportViewData('smil', $session));
    }

    public function testOnlyProfessionalOrderDoesNotPromiseClientAnything(): void
    {
        $session = $this->completedSession('smil', RetentionPolicy::THERAPIST_CASE);
        $this->order($session, Prompt::KIND_PROFESSIONAL);

        self::assertNull($this->presenter()->reportViewData('smil', $session));
    }

    #[DataProvider('orderedStates')]
    public function testOrderedClearReportShowsWaitingWithoutDraft(string $state): void
    {
        $session = $this->completedSession('smil', RetentionPolicy::THERAPIST_CASE);
        $reportId = $this->order($session, Prompt::KIND_CLEAR);
        if ($state === AiReportRepository::STATUS_READY) {
            $this->reports->markReady($reportId, new AiCompletion(self::DRAFT, 'fixture/requested', 'fixture/served', 1, 2));
        } elseif ($state === AiReportRepository::STATUS_FAILED) {
            $this->reports->markFailed($reportId, 'fixture failure');
        }

        $data = $this->presenter()->reportViewData('smil', $session);
        self::assertIsArray($data);
        self::assertTrue($data['restricted']);
        self::assertNull($data['published']);
        self::assertSame([], $data['kinds']);

        $html = $this->renderClientPage('smil', $session);
        self::assertStringContainsString(self::WAITING, mb_strtolower($html));
        self::assertStringNotContainsString(self::DRAFT, $html);
        self::assertStringNotContainsString('fixture failure', $html);
        // Статус задания на страницу не выводится: опрос состояния не стартует.
        self::assertStringNotContainsString('data-kind="clear"', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function orderedStates(): iterable
    {
        yield 'pending' => [AiReportRepository::STATUS_PENDING];
        yield 'ready' => [AiReportRepository::STATUS_READY];
        yield 'failed' => [AiReportRepository::STATUS_FAILED];
    }

    public function testPublishedRevisionIsShown(): void
    {
        $session = $this->completedSession('smil', RetentionPolicy::THERAPIST_CASE);
        $reportId = $this->order($session, Prompt::KIND_CLEAR);
        $this->reports->markReady($reportId, new AiCompletion(self::DRAFT, 'fixture/requested', 'fixture/served', 1, 2));
        $revisions = new AiReportRevisionService($this->db);
        $revisions->save($reportId, self::APPROVED);
        self::assertTrue($revisions->publish($reportId, (string) $revisions->revisions($reportId)[1]['id']));

        $data = $this->presenter()->reportViewData('smil', $session);
        self::assertIsArray($data);
        self::assertIsArray($data['published']);
        self::assertStringContainsString(self::APPROVED, (string) $data['published']['html']);

        $html = $this->renderClientPage('smil', $session);
        self::assertStringNotContainsString(self::WAITING, mb_strtolower($html));
        self::assertStringNotContainsString(self::DRAFT, $html);
    }

    public function testRegularSessionKeepsPreviousBehaviour(): void
    {
        $bai = $this->completedSession('beck-anxiety', RetentionPolicy::ANONYMOUS);
        self::assertNull($this->presenter()->reportViewData('beck-anxiety', $bai), 'У BAI нет промптов — блока нет, как и раньше.');

        $smil = $this->completedSession('smil', RetentionPolicy::ANONYMOUS);
        $data = $this->presenter()->reportViewData('smil', $smil);
        $registry = PromptRegistry::default($this->db);
        $expected = array_values(array_filter(
            [Prompt::KIND_CLEAR, Prompt::KIND_PROFESSIONAL],
            static fn (string $kind): bool => $registry->published('smil', 'individual', $kind) !== null,
        ));

        if ($expected === []) {
            self::assertNull($data);

            return;
        }

        self::assertIsArray($data);
        self::assertArrayNotHasKey('restricted', $data);
        self::assertSame($expected, array_column($data['kinds'], 'kind'));
        self::assertSame(['none'], array_values(array_unique(array_column($data['kinds'], 'status'))));
    }

    // ---------------------------------------------------------------- fixtures

    private function presenter(): ResultPresenter
    {
        return new ResultPresenter($this->db, $this->sessions);
    }

    /**
     * @return array<string, mixed>
     */
    private function completedSession(string $slug, string $retention): array
    {
        $test = $this->db->selectOne('SELECT id FROM tests WHERE slug = ?', [$slug]);
        self::assertIsArray($test, 'Предусловие: методика ' . $slug . ' зарегистрирована.');

        $session = $this->sessions->createSession((int) $test['id']);
        $id = (string) $session['id'];
        $this->sessionIds[] = $id;
        $this->db->update('test_sessions', [
            'status' => 'completed',
            'retention_class' => $retention,
            'calculated_results' => json_encode(['total_score' => 7], JSON_UNESCAPED_UNICODE),
        ], 'id = ?', [$id]);

        $fresh = $this->sessions->getSessionById($id);
        self::assertIsArray($fresh);

        return $fresh;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function order(array $session, string $kind): string
    {
        $prompt = new Prompt('smil', 'individual', $kind, 1, Prompt::STATUS_PUBLISHED, 'Текст промпта.', false, 'fixture');
        $job = $this->reports->request((string) $session['id'], 'smil', 'individual', $kind, $prompt, ['scales' => []]);

        return (string) $job['id'];
    }

    /**
     * @param array<string, mixed> $session
     */
    private function renderClientPage(string $slug, array $session): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), [
            'cache' => false,
            'strict_variables' => true,
        ]);
        TemplateFunctions::register($twig);

        return $twig->render('result-layout.twig', [
            'appName' => 'PsyTest',
            'basePath' => '',
            'csrf_token' => 'synthetic-csrf-token',
            'test' => ['name' => 'Методика', 'slug' => $slug],
            'session' => $session,
            'sections' => [],
            'clinical_safety_notice' => null,
            'ai_report' => $this->presenter()->reportViewData($slug, $session),
            'result_base' => '/result/' . $slug . '/' . $session['session_token'],
            'account_view' => false,
            'visitor_account' => null,
        ]);
    }
}
