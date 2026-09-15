<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Ai\AiCompletion;
use PsyTest\Core\Ai\AiReportRepository;
use PsyTest\Core\Ai\AiReportRevisionService;
use PsyTest\Core\Ai\Prompt;
use PsyTest\Core\CaseExportPresenter;
use PsyTest\Core\Database;
use PsyTest\Core\PDFGenerator;
use PsyTest\Core\ResultSectionRenderer;
use PsyTest\Core\RetentionPolicy;
use PsyTest\Core\SessionManager;
use PsyTest\Core\TemplateFunctions;
use PsyTest\Modules\Lazarus\LazarusModule;
use PsyTest\Modules\Smil\SmilModule;
use PsyTest\Modules\TestModuleInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Выгрузка кейса специалиста в PDF и версию для печати (07.K5j).
 *
 * Владелец просил получать результат вместе с интерпретациями целиком, чтобы
 * сохранить или распечатать. Проверяется то, что он увидит в документе: шапка,
 * расчётный материал, оба разбора с честной пометкой о публикации, — и то, чего
 * там быть не должно: bearer-токена и ссылки на клиентскую страницу результата.
 */
#[Group('database')]
final class CaseExportTest extends TestCase
{
    private Database $db;
    private SessionManager $sessions;
    private SmilModule $smil;
    private LazarusModule $lazarus;

    private string $smilSessionId = '';
    private string $pairFirstId = '';
    private string $pairSecondId = '';
    private string $comparisonId = '';
    /** @var list<string> */
    private array $reportIds = [];
    /** @var list<string> */
    private array $inviteIds = [];
    /** @var list<string> */
    private array $clientIds = [];

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->sessions = new SessionManager($this->db);
        $this->smil = new SmilModule();
        $this->lazarus = new LazarusModule();

        $this->smilSessionId = $this->smilCase();
        [$this->pairFirstId, $this->pairSecondId] = $this->lazarusPair();
    }

    protected function tearDown(): void
    {
        foreach ($this->reportIds as $id) {
            $this->db->delete('ai_report_revisions', 'report_id = ?', [$id]);
            $this->db->delete('ai_reports', 'id = ?', [$id]);
        }
        foreach ($this->inviteIds as $id) {
            $this->db->delete('test_invites', 'id = ?', [$id]);
        }
        if ($this->comparisonId !== '') {
            $this->db->delete('pair_comparisons', 'id = ?', [$this->comparisonId]);
        }
        foreach ([$this->smilSessionId, $this->pairFirstId, $this->pairSecondId] as $id) {
            if ($id !== '') {
                $this->db->delete('test_sessions', 'id = ?', [$id]);
            }
        }

        foreach ($this->clientIds as $id) {
            $this->db->delete('therapist_clients', 'id = ?', [$id]);
        }

        $this->reportIds = [];
        $this->inviteIds = [];
        $this->clientIds = [];
        $this->comparisonId = '';
        $this->smilSessionId = '';
        $this->pairFirstId = '';
        $this->pairSecondId = '';
    }

    public function testTheDocumentCarriesTheHeaderAdditionalScalesAndBothReports(): void
    {
        $html = $this->printHtml($this->smilSessionId, $this->smil);

        // Шапка: методика, подпись клиента, дата, кто подготовил, конфиденциальность.
        self::assertStringContainsString('СМИЛ', $html);
        self::assertStringContainsString('Клиент К. (синтетика)', $html);
        self::assertStringContainsString('Подготовил: специалист', $html);
        self::assertStringContainsString('Конфиденциально', $html);

        // Расчётный материал — те же секции, что в карточке кейса.
        self::assertStringContainsString('Дополнительные шкалы', $html);
        self::assertStringContainsString('Профиль личности', $html);

        // Оба разбора: заключение целиком и опубликованная версия с номером.
        self::assertStringContainsString('Профессиональное заключение', $html);
        self::assertStringContainsString('Структура профиля синтетическая.', $html);
        self::assertStringContainsString('Понятный разбор', $html);
        self::assertStringContainsString('Отредактированный специалистом текст.', $html);
        self::assertStringContainsString('Опубликовано клиенту, версия №2', $html);

        // Подвал и формулировка из существующего PDF результата.
        self::assertStringContainsString(CaseExportPresenter::DISCLAIMER, $html);
    }

    public function testTheDocumentNeverCarriesTheBearerTokenOrTheClientResultLink(): void
    {
        $session = $this->sessions->getSessionById($this->smilSessionId);
        self::assertIsArray($session);

        $html = $this->printHtml($this->smilSessionId, $this->smil);

        self::assertStringNotContainsString('session_token', $html);
        self::assertStringNotContainsString('/result/', $html);
        self::assertStringNotContainsString((string) $session['session_token'], $html);
        // Идентификатор кейса встречается только в экранной ссылке «К карточке»,
        // которая в `@media print` скрыта; сам документ его не показывает.
        self::assertStringNotContainsString($this->smilSessionId, $this->documentBody($html));
    }

    public function testTheQuestionnaireAppearsOnlyWhenTheSpecialistAsksForIt(): void
    {
        $withoutAnswers = $this->printHtml($this->smilSessionId, $this->smil);
        self::assertStringNotContainsString('Анкета по пунктам', $withoutAnswers);

        $withAnswers = $this->printHtml($this->smilSessionId, $this->smil, ['include_answers' => '1']);
        self::assertStringContainsString('Анкета по пунктам', $withAnswers);
        self::assertStringContainsString('owner-answer-list', $withAnswers);
    }

    public function testTheNoteAppearsOnlyWhenTheSpecialistAsksForIt(): void
    {
        $document = $this->document($this->smilSessionId, $this->smil, []);
        self::assertNull($document['note']);

        $withNote = $this->document($this->smilSessionId, $this->smil, ['include_note' => '1']);
        self::assertSame('Назначено перед первой сессией.', $withNote['note']);
    }

    public function testTurningAReportOffKeepsItOutOfTheDocument(): void
    {
        $document = $this->document($this->smilSessionId, $this->smil, ['include_professional' => null]);

        self::assertNull($document['professional']);
        self::assertIsArray($document['clear']);
        self::assertTrue($document['clear']['published']);
        self::assertSame(2, $document['clear']['revision_no']);
    }

    /**
     * Без публикации разбор помечается черновиком, а не выдаётся за одобренный.
     */
    public function testAnUnpublishedClearReportIsMarkedAsADraft(): void
    {
        $report = $this->db->selectOne(
            'SELECT id FROM ai_reports WHERE session_id = ? AND report_kind = ?',
            [$this->smilSessionId, Prompt::KIND_CLEAR],
        );
        self::assertIsArray($report);
        (new AiReportRevisionService($this->db))->unpublish((string) $report['id']);

        $document = $this->document($this->smilSessionId, $this->smil, []);
        self::assertIsArray($document['clear']);
        self::assertFalse($document['clear']['published']);

        $html = $this->printHtml($this->smilSessionId, $this->smil);
        self::assertStringContainsString('Черновик, не опубликован', $html);
        self::assertStringNotContainsString('Опубликовано клиенту', $html);
    }

    public function testThePairedCaseCarriesThePairBlockAndBothQuestionnaires(): void
    {
        $document = $this->document($this->pairFirstId, $this->lazarus, ['include_answers' => '1']);

        self::assertIsArray($document['pair']);
        $types = array_map(static fn ($section): string => $section->type, $document['pair']['sections']);
        self::assertContains('pair_chart', $types);
        self::assertContains('pair_comparison', $types);
        self::assertNotContains('pair_invite', $types);
        self::assertCount(2, $document['pair']['questionnaires']);
        // Идентификатор второй сессии из сборщика не выходит.
        self::assertArrayNotHasKey('partner_session_id', $document['pair']);

        $html = $this->printHtml($this->pairFirstId, $this->lazarus, ['include_answers' => '1']);
        self::assertStringContainsString('Парный результат', $html);
        self::assertStringContainsString('pair-comparison-block', $html);
        self::assertStringContainsString('Партнёр 1 — начавший опросник', $html);
        self::assertStringContainsString('Партнёр 2 — приглашённый участник', $html);
        self::assertStringNotContainsString($this->pairSecondId, $html);
        self::assertStringNotContainsString('/result/', $html);
    }

    /**
     * Чужой и несуществующий кейс закрыт тем же путём, что и карточка.
     *
     * Проверяется сам источник доступа: выгрузка ходит через
     * `claimedCaseForOwner()`, и сессия без приглашения кейсом не становится.
     */
    public function testASessionWithoutAnInviteIsNotACaseAndCannotBeExported(): void
    {
        $service = new \PsyTest\Core\TestInviteService($this->db, $this->sessions);

        self::assertNull($service->claimedCaseForOwner($this->pairSecondId));
        self::assertNull($service->claimedCaseForOwner('00000000-0000-4000-8000-000000000000'));

        $controller = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/OwnerController.php');
        self::assertStringContainsString('public function exportCasePdf(string $sessionId): void', $controller);
        self::assertStringContainsString('public function exportCasePrint(string $sessionId): void', $controller);
        // Обе выгрузки идут через ту же проверку владения, что и карточка.
        self::assertStringContainsString('$case = $this->ownedCase($sessionId);', $controller);
    }

    public function testTheExportPdfIsGeneratedWithoutLeavingAFileOnTheServer(): void
    {
        $directory = sys_get_temp_dir() . '/psytest-export-' . bin2hex(random_bytes(4));
        $document = $this->document($this->smilSessionId, $this->smil, []);

        $twig = $this->twig();
        $renderer = new ResultSectionRenderer(
            static fn (string $template, array $data): string => $twig->render($template . '.twig', $data),
        );

        $html = $twig->render('owner-case-export-pdf.twig', [
            'document' => $document,
            'sections_html' => $renderer->renderToHtml($document['sections']),
            'pair_html' => '',
        ]);

        $pdf = (new PDFGenerator($directory))->generate($html, 'case_export.pdf', false);

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(20 * 1024, strlen($pdf), 'Документ с профилем и таблицами не может быть меньше 20 КБ.');
        // Файл на сервере не остаётся: выгрузка живёт только в ответе.
        self::assertSame([], glob($directory . '/*.pdf') ?: []);
        @rmdir($directory);
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * Сам документ по выбору специалиста.
     *
     * `$options` — отличия от значений по умолчанию, ровно как их присылает
     * форма: `'1'` включает раздел, `null` снимает галочку.
     *
     * @param array<string, string|null> $options
     * @return array<string, mixed>
     */
    private function document(string $sessionId, TestModuleInterface $module, array $options): array
    {
        $case = (new \PsyTest\Core\TestInviteService($this->db, $this->sessions))
            ->claimedCaseForOwner($sessionId);
        self::assertIsArray($case);

        $query = [
            CaseExportPresenter::FORM_MARKER => '1',
            'include_professional' => '1',
            'include_clear' => '1',
        ];
        foreach ($options as $key => $value) {
            if ($value === null) {
                unset($query[$key]);
            } else {
                $query[$key] = $value;
            }
        }

        return (new CaseExportPresenter($this->db, $this->sessions))->build(
            $case,
            $module,
            CaseExportPresenter::options($query),
        );
    }

    /** Тело документа без экранной панели с кнопками. */
    private function documentBody(string $html): string
    {
        $start = strpos($html, '<article class="case-export__doc">');
        self::assertIsInt($start);

        return substr($html, $start);
    }

    /** @param array<string, string|null> $options */
    private function printHtml(string $sessionId, TestModuleInterface $module, array $options = []): string
    {
        return $this->twig()->render('owner-case-export.twig', [
            'appName' => 'PsyTest',
            'basePath' => '',
            'document' => $this->document($sessionId, $module, $options),
            'case_id' => $sessionId,
        ]);
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

    /**
     * Синтетический кейс СМИЛ: приглашение, кейс специалиста, оба разбора ready,
     * понятный — с правкой специалиста и публикацией второй версии.
     */
    private function smilCase(): string
    {
        $test = $this->db->selectOne("SELECT id FROM tests WHERE slug = 'smil'");
        self::assertIsArray($test, 'Предусловие: методика СМИЛ зарегистрирована.');

        $answers = ['gender' => 'female'];
        foreach ($this->smil->getQuestions() as $index => $question) {
            $answers[(string) ($question['id'] ?? $index + 1)] = $index % 3 === 0 ? 1 : 0;
        }

        $session = $this->sessions->createSession((int) $test['id']);
        $id = (string) $session['id'];
        $this->db->update('test_sessions', [
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
            'retention_class' => RetentionPolicy::THERAPIST_CASE,
            'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
            'calculated_results' => json_encode(
                $this->smil->calculateResults($answers),
                JSON_UNESCAPED_UNICODE,
            ),
        ], 'id = ?', [$id]);

        $this->invite((int) $test['id'], $id, 'Клиент К. (синтетика)', 'Назначено перед первой сессией.');

        $this->readyReport($id, Prompt::KIND_PROFESSIONAL, "## Заключение\n\nСтруктура профиля синтетическая.");
        $clearId = $this->readyReport($id, Prompt::KIND_CLEAR, "## Разбор\n\nПервая версия модели.");

        $revisions = new AiReportRevisionService($this->db);
        $revisionId = $revisions->save($clearId, "## Разбор\n\nОтредактированный специалистом текст.");
        self::assertTrue($revisions->publish($clearId, $revisionId));

        return $id;
    }

    /** @return array{0: string, 1: string} */
    private function lazarusPair(): array
    {
        $test = $this->db->selectOne("SELECT id FROM tests WHERE slug = 'lazarus'");
        self::assertIsArray($test, 'Предусловие: методика Лазаруса зарегистрирована.');
        $testId = (int) $test['id'];

        $first = $this->lazarusSession($testId, 8, 6);
        $second = $this->lazarusSession($testId, 6, 9);

        $firstSession = $this->sessions->getSessionById($first);
        $secondSession = $this->sessions->getSessionById($second);
        self::assertIsArray($firstSession);
        self::assertIsArray($secondSession);

        $record = $this->sessions->createPairComparison(
            $testId,
            $first,
            $second,
            $this->lazarus->comparePairResults(
                $firstSession['calculated_results'],
                $secondSession['calculated_results'],
            ),
        );
        $this->comparisonId = (string) $record['id'];

        $this->db->update(
            'test_sessions',
            ['retention_class' => RetentionPolicy::THERAPIST_CASE],
            'id = ?',
            [$first],
        );
        $this->invite($testId, $first, 'Пара П. (синтетика)', 'Парное назначение.');

        return [$first, $second];
    }

    private function lazarusSession(int $testId, int $self, int $partner): string
    {
        $session = $this->sessions->createSession($testId);
        $id = (string) $session['id'];

        $answers = [];
        foreach ($this->lazarus->getQuestions() as $question) {
            $answers[(string) $question['id'] . '_self'] = $self;
            $answers[(string) $question['id'] . '_partner'] = $partner;
        }

        $this->db->update('test_sessions', [
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
            'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
            'calculated_results' => json_encode(
                $this->lazarus->calculateResults($answers),
                JSON_UNESCAPED_UNICODE,
            ),
        ], 'id = ?', [$id]);

        return $id;
    }

    private function invite(int $testId, string $sessionId, string $label, string $note): void
    {
        $clientId = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $this->db->insert('therapist_clients', ['id' => $clientId, 'label' => $label]);

        // Та же привязка, что делает кабинет: приглашение без живой ссылки.
        $this->inviteIds[] = (new \PsyTest\Core\TestInviteService($this->db, $this->sessions))
            ->bindExistingSession($sessionId, $testId, $clientId, $note);
        $this->clientIds[] = $clientId;
    }

    private function readyReport(string $sessionId, string $kind, string $content): string
    {
        $id = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $this->db->insert('ai_reports', [
            'id' => $id,
            'session_id' => $sessionId,
            'test_slug' => 'smil',
            'mode' => 'individual',
            'report_kind' => $kind,
            'status' => AiReportRepository::STATUS_PENDING,
            'prompt_key' => 'synthetic',
            'prompt_version' => 1,
            'context_snapshot' => json_encode(['synthetic' => true]),
        ]);
        $this->reportIds[] = $id;

        (new AiReportRepository($this->db))->markReady(
            $id,
            new AiCompletion($content, 'synthetic/model', 'synthetic/model', 0, 0),
        );

        return $id;
    }
}
