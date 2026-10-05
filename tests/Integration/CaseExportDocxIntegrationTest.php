<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Ai\AiCompletion;
use PsyTest\Core\Ai\AiReportRepository;
use PsyTest\Core\Ai\AiReportRevisionService;
use PsyTest\Core\Ai\Prompt;
use PsyTest\Core\CaseExportDocx;
use PsyTest\Core\CaseExportPresenter;
use PsyTest\Core\Database;
use PsyTest\Core\RetentionPolicy;
use PsyTest\Core\SessionManager;
use PsyTest\Core\TemplateFunctions;
use PsyTest\Modules\Lazarus\LazarusModule;
use PsyTest\Modules\Smil\SmilModule;
use PsyTest\Modules\Smil\SmilProfileImageRenderer;
use PsyTest\Modules\TestModuleInterface;
use PsyTest\Tests\DocxInspection;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Выгрузка кейса специалиста в Word (07.K5m).
 *
 * Владелец дорабатывает заключение в Word, поэтому проверяется то, что он
 * увидит в файле: настоящие заголовки и таблицы Word, картинка профиля СМИЛ,
 * переведённые из Markdown списки и таблицы разборов, пометка о публикации, —
 * и то, чего там быть не должно: токена, идентификатора кейса и ссылки на
 * клиентскую страницу результата.
 */
#[Group('database')]
final class CaseExportDocxIntegrationTest extends TestCase
{
    use DocxInspection;

    private const PROFESSIONAL_MD = "## Заключение\n\nСтруктура профиля синтетическая.";

    private const CLEAR_MD = <<<'MD'
## Разбор

Отредактированный специалистом текст.

**жирный текст** и *курсив*.

- пункт списка
- ещё один пункт списка

1. шаг нумерации
2. второй шаг нумерации

| Шкала | Выраженность |
|---|---|
| Тревога | повышена |

---

Завершающий абзац.
MD;

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

    public function testTheSmilDocumentCarriesTheHeaderSectionsAndBothReports(): void
    {
        $parts = $this->unzip($this->docx($this->smilSessionId, $this->smil));
        $dom = $this->xmlPart($parts['word/document.xml']);
        $text = $this->plainText($dom);

        self::assertStringContainsString('СМИЛ', $text);
        self::assertStringContainsString('Клиент К. (синтетика)', $text);
        self::assertStringContainsString('Подготовил: специалист', $text);
        self::assertStringContainsString('Конфиденциально', $text);
        self::assertStringContainsString(CaseExportPresenter::DISCLAIMER, $text);

        // Разделы — настоящие «Заголовок 1/2»: по ним работает навигация Word.
        $xpath = $this->xpath($dom);
        $h1 = [];
        foreach ($xpath->query('//w:p[w:pPr/w:pStyle/@w:val="Heading1"]') ?: [] as $paragraph) {
            $h1[] = $paragraph->textContent;
        }
        self::assertContains('Базовый результат', $h1);
        self::assertContains('Профессиональное заключение', $h1);
        self::assertContains('Понятный разбор', $h1);
        $h2 = [];
        foreach ($xpath->query('//w:p[w:pPr/w:pStyle/@w:val="Heading2"]') ?: [] as $paragraph) {
            $h2[] = $paragraph->textContent;
        }
        self::assertContains('Основные шкалы', $h2);
        self::assertContains('Дополнительные шкалы', $h2);
        self::assertContains('Профиль личности', $h2);

        // Разбор клиенту: опубликованная версия и текст правки специалиста.
        self::assertStringContainsString('Опубликовано клиенту, версия №2', $text);
        self::assertStringContainsString('Отредактированный специалистом текст.', $text);
        self::assertStringContainsString('Структура профиля синтетическая.', $text);
        self::assertStringNotContainsString('Первая версия модели.', $text);
    }

    public function testAdditionalScalesAreOneWordTableWithARepeatingHeaderAndTheRightRowCount(): void
    {
        $document = $this->document($this->smilSessionId, $this->smil, []);
        $items = 0;
        $categories = 0;
        foreach ($document['sections'] as $section) {
            foreach ($section->data['categories'] ?? [] as $category) {
                $categories++;
                $items += count($category['items']);
            }
        }
        // Женский протокол: нормы по полу респондента дают 107 строк.
        self::assertSame(107, $items);

        $dom = $this->xmlPart($this->unzip($this->docx($this->smilSessionId, $this->smil))['word/document.xml']);
        $xpath = $this->xpath($dom);
        $table = $this->largestTable($dom);

        // Шапка + строки категорий + шкалы.
        self::assertSame(1 + $categories + $items, $xpath->query('./w:tr', $table)->length);
        self::assertSame(1, $xpath->query('./w:tr/w:trPr/w:tblHeader', $table)->length, 'Шапка повторяется на страницах.');
        self::assertSame(5, $xpath->query('./w:tblGrid/w:gridCol', $table)->length);
        self::assertSame('w:tblPr', $table->firstChild?->nodeName);

        // Ширины колонок разумны: название шире кода и баллов, сумма — ширина текста.
        $widths = [];
        foreach ($xpath->query('./w:tblGrid/w:gridCol/@w:w', $table) ?: [] as $width) {
            $widths[] = (int) $width->nodeValue;
        }
        self::assertSame(9638, array_sum($widths));
        self::assertGreaterThan(max($widths[0], $widths[2], $widths[3]), $widths[1]);

        // Примечание шкалы (если есть) — мелким текстом в ячейке названия.
        $noted = $xpath->query('.//w:r[w:rPr/w:sz/@w:val="15"]', $table);
        self::assertGreaterThan(0, $noted->length, 'Примечания и нормы набраны мелким шрифтом.');
    }

    public function testTheProfileIsAnImageWhenTheRendererIsAvailableAndAPlaceholderOtherwise(): void
    {
        $parts = $this->unzip($this->docx($this->smilSessionId, $this->smil));
        $media = array_values(array_filter(array_keys($parts), static fn (string $n): bool => str_starts_with($n, 'word/media/')));
        $text = $this->plainText($this->xmlPart($parts['word/document.xml']));

        if (SmilProfileImageRenderer::isAvailable()) {
            self::assertCount(1, $media);
            self::assertStringStartsWith("\x89PNG", $parts[$media[0]]);
            self::assertMatchesRegularExpression('~<(w:drawing|v:imagedata)~', $parts['word/document.xml']);
            self::assertStringNotContainsString('График недоступен', $text);
        } else {
            self::assertSame([], $media);
            self::assertStringContainsString('График недоступен', $text);
        }
    }

    public function testMarkdownOfTheReportsBecomesWordStructures(): void
    {
        $dom = $this->xmlPart($this->unzip($this->docx($this->smilSessionId, $this->smil))['word/document.xml']);
        $xpath = $this->xpath($dom);

        // Таблица из Markdown разбора — настоящая таблица Word с шапкой.
        $found = false;
        foreach ($xpath->query('//w:tbl') ?: [] as $table) {
            if (str_contains($table->textContent, 'Выраженность') && str_contains($table->textContent, 'повышена')) {
                $found = true;
                self::assertSame(2, $xpath->query('./w:tr', $table)->length);
                self::assertSame(1, $xpath->query('./w:tr/w:trPr/w:tblHeader', $table)->length);
            }
        }
        self::assertTrue($found, 'Таблица Markdown не потерялась.');

        $text = $this->plainText($dom);
        self::assertStringContainsString('пункт списка', $text);
        self::assertStringContainsString('шаг нумерации', $text);
        self::assertGreaterThan(0, $xpath->query('//w:numPr')->length);
        self::assertGreaterThan(0, $xpath->query('//w:r[w:rPr/w:b]/w:t[.="жирный текст"]')->length);
        self::assertStringNotContainsString('**', $text);
        self::assertStringNotContainsString('| Шкала |', $text);
        self::assertGreaterThan(0, $xpath->query('//w:pBdr/w:bottom')->length, 'Горизонтальная черта.');
    }

    public function testTheDocumentNeverCarriesTheBearerTokenOrAnyIdentifier(): void
    {
        $session = $this->sessions->getSessionById($this->smilSessionId);
        self::assertIsArray($session);
        $parts = $this->unzip($this->docx($this->smilSessionId, $this->smil, ['include_answers' => '1', 'include_note' => '1']));

        foreach ($parts as $name => $content) {
            self::assertStringNotContainsString((string) $session['session_token'], $content, $name);
            self::assertStringNotContainsString($this->smilSessionId, $content, $name);
            self::assertStringNotContainsString('/result/', $content, $name);
            if (str_ends_with($name, '.xml')) {
                self::assertStringNotContainsString('@', $content, $name . ': адресов почты в документе нет.');
            }
        }
    }

    public function testTheQuestionnaireAndTheNoteAppearOnlyWhenAsked(): void
    {
        $plain = $this->plainText($this->xmlPart($this->unzip($this->docx($this->smilSessionId, $this->smil))['word/document.xml']));
        self::assertStringNotContainsString('Анкета по пунктам', $plain);
        self::assertStringNotContainsString('Заметка к назначению', $plain);
        self::assertStringNotContainsString('Назначено перед первой сессией.', $plain);

        $dom = $this->xmlPart($this->unzip($this->docx($this->smilSessionId, $this->smil, [
            'include_answers' => '1',
            'include_note' => '1',
        ]))['word/document.xml']);
        $full = $this->plainText($dom);
        self::assertStringContainsString('Анкета по пунктам', $full);
        self::assertStringContainsString('Заметка к назначению', $full);
        self::assertStringContainsString('Назначено перед первой сессией.', $full);

        // Анкета — таблица на все пункты методики плюс шапка.
        self::assertSame(count($this->smil->getQuestions()) + 1, $this->xpath($dom)->query('./w:tr', $this->largestTable($dom))->length);
    }

    public function testTurningAReportOffKeepsItOutOfTheWordFile(): void
    {
        $text = $this->plainText($this->xmlPart($this->unzip(
            $this->docx($this->smilSessionId, $this->smil, ['include_professional' => null]),
        )['word/document.xml']));

        self::assertStringNotContainsString('Профессиональное заключение', $text);
        self::assertStringContainsString('Понятный разбор', $text);
    }

    public function testAnUnpublishedClearReportIsMarkedAsADraft(): void
    {
        $report = $this->db->selectOne(
            'SELECT id FROM ai_reports WHERE session_id = ? AND report_kind = ?',
            [$this->smilSessionId, Prompt::KIND_CLEAR],
        );
        self::assertIsArray($report);
        (new AiReportRevisionService($this->db))->unpublish((string) $report['id']);

        $text = $this->plainText($this->xmlPart($this->unzip($this->docx($this->smilSessionId, $this->smil))['word/document.xml']));
        self::assertStringContainsString('Черновик, не опубликован', $text);
        self::assertStringNotContainsString('Опубликовано клиенту', $text);
    }

    public function testThePairedCaseIsTablesWithoutImagesAndWithBothQuestionnaires(): void
    {
        $parts = $this->unzip($this->docx($this->pairFirstId, $this->lazarus, ['include_answers' => '1']));
        $dom = $this->xmlPart($parts['word/document.xml']);
        $text = $this->plainText($dom);

        self::assertStringContainsString('Парный результат', $text);
        self::assertStringContainsString('Индивидуальный результат', $text);
        self::assertStringContainsString('Анкеты обоих партнёров', $text);
        self::assertStringContainsString('Партнёр 1 — начавший опросник', $text);
        self::assertStringContainsString('Партнёр 2 — приглашённый участник', $text);
        self::assertStringContainsString('Подробное сравнение', $text);
        self::assertStringNotContainsString($this->pairSecondId, $parts['word/document.xml']);
        self::assertStringNotContainsString('/result/', $parts['word/document.xml']);

        // Сравнение, профиль по пунктам и две анкеты — таблицы; SVG и картинок нет.
        self::assertGreaterThanOrEqual(4, $this->xpath($dom)->query('//w:tbl')->length);
        self::assertSame([], array_filter(array_keys($parts), static fn (string $n): bool => str_starts_with($n, 'word/media/')));
        self::assertStringNotContainsString('<svg', $parts['word/document.xml']);
        // Служебная шкала-полоска из веб-версии не должна просочиться текстом.
        self::assertStringNotContainsString('dissatisfied', $text);
    }

    public function testEveryPartOfBothPackagesIsWellFormedXml(): void
    {
        foreach ([[$this->smilSessionId, $this->smil], [$this->pairFirstId, $this->lazarus]] as [$id, $module]) {
            $parts = $this->unzip($this->docx($id, $module, ['include_answers' => '1', 'include_note' => '1']));
            foreach ($parts as $name => $content) {
                if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
                    $this->xmlPart($content);
                }
            }
            self::assertArrayHasKey('word/document.xml', $parts);
            self::assertArrayHasKey('[Content_Types].xml', $parts);
        }
    }

    public function testTheCardAndTheRouteExposeTheWordExport(): void
    {
        $root = dirname(__DIR__, 2);
        $card = (string) file_get_contents($root . '/templates/owner-invited-case.twig');
        $routes = (string) file_get_contents($root . '/public/index.php');
        $controller = (string) file_get_contents($root . '/controllers/OwnerController.php');

        self::assertStringContainsString('/admin/invited-case/{{ case.id }}/export.docx', $card);
        self::assertMatchesRegularExpression('~formaction="[^"]*export\.docx">Word</button>~', $card);
        self::assertStringContainsString("\$router->get('/admin/invited-case/{sessionId}/export.docx', [OwnerController::class, 'exportCaseDocx']);", $routes);
        self::assertStringContainsString('public function exportCaseDocx(string $sessionId): void', $controller);
        // Те же проверки доступа и параметры галочек, что у PDF.
        self::assertSame(3, substr_count($controller, '$prepared = $this->caseExport($sessionId);'));
        self::assertStringContainsString("header('Content-Type: ' . CaseExportDocx::CONTENT_TYPE);", $controller);
        self::assertStringContainsString('Content-Disposition: attachment; filename="case_', $controller);
        self::assertStringContainsString("header('Cache-Control: no-store');", $controller);
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            CaseExportDocx::CONTENT_TYPE,
        );
    }

    public function testNoDocumentFileRemainsOnTheServer(): void
    {
        $before = glob(sys_get_temp_dir() . '/psytest-docx-*') ?: [];
        $this->docx($this->smilSessionId, $this->smil);
        $after = glob(sys_get_temp_dir() . '/psytest-docx-*') ?: [];

        self::assertSame($before, $after);
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * Готовый .docx по выбору специалиста.
     *
     * @param array<string, string|null> $options
     */
    private function docx(string $sessionId, TestModuleInterface $module, array $options = []): string
    {
        $twig = $this->twig();

        return (new CaseExportDocx(
            static fn (string $template, array $data): string => $twig->render($template . '.twig', $data),
        ))->render($this->document($sessionId, $module, $options));
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

        $this->readyReport($id, Prompt::KIND_PROFESSIONAL, self::PROFESSIONAL_MD);
        $clearId = $this->readyReport($id, Prompt::KIND_CLEAR, "## Разбор\n\nПервая версия модели.");

        $revisions = new AiReportRevisionService($this->db);
        $revisionId = $revisions->save($clearId, self::CLEAR_MD);
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
