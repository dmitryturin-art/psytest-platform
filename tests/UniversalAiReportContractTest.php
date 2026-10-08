<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\Ai\Prompt;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Страницы «Промпты» после 07.WP10: галочки по методикам и карточка ключа-заготовки.
 *
 * Владелец — клинический психолог: на странице нет служебных слов
 * («manifest», «fixture», «провайдер», английских ключей настроек).
 */
final class UniversalAiReportContractTest extends TestCase
{
    private const JARGON = ['manifest', 'fixture', 'провайдер', 'report_enabled</', 'send_item_answers</', 'slug', 'json'];

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    private function twig(): Environment
    {
        $twig = new Environment(new FilesystemLoader($this->root . '/templates'), ['cache' => false]);
        \PsyTest\Core\TemplateFunctions::register($twig);

        return $twig;
    }

    /**
     * Строка методики, как её отдаёт OwnerController::methodologyRow().
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function methodologyItem(string $test, string $title, array $overrides = []): array
    {
        $none = [
            'exists' => false, 'state' => 'none', 'published_version' => null, 'published_at' => null,
            'published_source' => null, 'draft_version' => null, 'draft_at' => null, 'versions' => 0,
            'path' => "/admin/tests/{$test}/prompts/individual/clear", 'kind_title' => 'понятный разбор', 'mode_title' => 'индивидуальный',
        ];

        return $overrides + [
            'test' => $test, 'title' => $title, 'active' => true,
            'report_enabled' => true, 'send_item_answers' => false, 'items_always' => false, 'clinical_signal' => false,
            'offered' => false, 'status' => 'pending', 'status_label' => 'промпт не опубликован', 'items_label' => 'нет',
            'modes' => ['individual'],
            'prompts' => ['individual' => ['clear' => $none, 'professional' => ['path' => "/admin/tests/{$test}/prompts/individual/professional"] + $none]],
            'path' => '/admin/tests/' . $test,
        ];
    }

    /** @param array<string, mixed> $item */
    private function methodologyPage(array $item, ?array $flash = null): string
    {
        return $this->twig()->render('owner-test.twig', [
            'appName' => 'PsyTest', 'basePath' => '', 'csrf_token' => 'synthetic-csrf',
            'flash' => $flash, 'item' => $item, 'mode' => 'individual',
            'description' => 'Описание методики.', 'question_label' => '21 вопрос',
            'instruction' => ['Первый абзац инструкции.'],
            'variables' => [['key' => 'total', 'label' => 'Итог', 'hint' => 'Итоговый балл и максимально возможный балл методики.']],
            'ai_form_key' => 'tests-key', 'ai' => ['enabled' => true, 'model' => 'model/env'],
        ]);
    }

    public function testMethodologyPageHasTwoAutosavingCheckboxesWithPlainHelp(): void
    {
        $bai = $this->methodologyPage($this->methodologyItem('beck-anxiety', 'Шкала тревоги Бека (BAI)', ['send_item_answers' => true]));
        $hads = $this->methodologyPage($this->methodologyItem('hads', 'HADS', ['report_enabled' => false, 'status' => 'muted', 'status_label' => 'разбор выключен']));

        // Одна методика — одна форма; сохраняется сразу (data-autosave), без JS — кнопкой.
        self::assertStringContainsString('action="/admin/tests/beck-anxiety/ai"', $bai);
        self::assertStringContainsString('data-autosave>', $bai);
        self::assertStringContainsString('name="form_key" value="tests-key" data-autosave-key', $bai);
        self::assertStringContainsString('data-autosave-submit>Сохранить</button>', $bai);
        self::assertStringContainsString('role="status" aria-live="polite" data-autosave-status', $bai);
        self::assertSame(1, substr_count($bai, 'name="report_enabled"'));
        self::assertSame(1, substr_count($bai, 'name="send_item_answers"'));
        self::assertStringContainsString('ИИ-разбор для этой методики', $bai);
        self::assertStringContainsString('Передавать модели ответы по пунктам', $bai);

        // Вторая галочка недоступна, пока первая снята, и связана с ней.
        self::assertMatchesRegularExpression('/id="ai-test-items-hads" name="send_item_answers" value="1" disabled>/', $hads);
        self::assertStringContainsString('data-enables="ai-test-items-beck-anxiety"', $bai);
        self::assertMatchesRegularExpression('/id="ai-test-items-beck-anxiety" name="send_item_answers" value="1" checked>/', $bai);
        $template = (string) file_get_contents($this->root . '/templates/owner-test.twig');
        self::assertStringContainsString("asset('js/owner-forms.js')", $template);
        self::assertStringContainsString("asset('js/owner-tests.js')", $template);

        // Что уходит модели и что не уходит — простыми словами.
        self::assertStringContainsString('баллы, уровни и подшкалы', $bai);
        self::assertStringContainsString('Имя, контакты и ваши заметки не уходят никогда', $bai);
        self::assertStringContainsString('ответ человека на каждый вопрос опросника', $bai);
        self::assertStringContainsString('промпт не опубликован', $bai);
        self::assertStringContainsString('Промптов пока нет. Включите ИИ-разбор выше', $hads);

        foreach ([$bai, $hads] as $html) {
            $visible = mb_strtolower(strip_tags($html));
            foreach (self::JARGON as $word) {
                self::assertStringNotContainsString(mb_strtolower($word), $visible, "Служебное слово «{$word}» на странице владельца.");
            }
        }
    }

    public function testMethodologyPageShowsStubCardsAndTheCreatedNoticeInThePromptsSection(): void
    {
        $draft = static fn (string $kind): array => [
            'exists' => true, 'state' => 'draft', 'published_version' => null, 'published_at' => null,
            'published_source' => null, 'draft_version' => 1000, 'draft_at' => '2026-10-08 12:00:00', 'versions' => 1,
            'path' => "/admin/tests/beck-anxiety/prompts/individual/{$kind}", 'kind_title' => $kind, 'mode_title' => 'индивидуальный',
        ];
        $item = $this->methodologyItem('beck-anxiety', 'Шкала тревоги Бека (BAI)', [
            'prompts' => ['individual' => ['clear' => $draft('clear'), 'professional' => $draft('professional')]],
        ]);
        $html = $this->methodologyPage($item, ['type' => 'success', 'message' => 'Созданы две заготовки промптов — прочитайте и опубликуйте.', 'section' => 'prompts']);

        self::assertSame(2, substr_count($html, 'class="owner-case-ai-item report-card prompt-card"'));
        self::assertSame(2, substr_count($html, 'data-state="draft"'));
        self::assertStringContainsString('Черновик версии 1000 от 08.10.2026', $html);
        self::assertStringContainsString('href="/admin/tests/beck-anxiety/prompts/individual/clear#prompt-publish">Опубликовать…</a>', $html);
        // Сообщение о заготовках — в разделе «Промпты», а не в шапке.
        $prompts = substr($html, (int) strpos($html, 'id="prompts"'));
        self::assertStringContainsString('Созданы две заготовки промптов', $prompts);
        self::assertSame(1, substr_count($html, 'Созданы две заготовки промптов'));
        // Разделы страницы — в полосе .case-nav, как в карточке кейса.
        foreach (['#ai', '#prompts', '#model-input', '#instruction'] as $anchor) {
            self::assertStringContainsString('href="' . $anchor . '" data-case-nav-link', $html);
        }
        self::assertStringContainsString('<dt>Итог</dt>', $html);
        self::assertStringContainsString('Первый абзац инструкции.', $html);
    }

    public function testLazarusItemCheckboxIsLockedOnAndBdiWarnsAboutTheSuicideItem(): void
    {
        $lazarus = $this->methodologyPage($this->methodologyItem('lazarus', 'Лазарус', ['items_always' => true, 'items_label' => 'всегда']));
        $bdi = $this->methodologyPage($this->methodologyItem('bdi', 'BDI', ['clinical_signal' => true]));

        self::assertStringContainsString('<input type="checkbox" id="ai-test-items-lazarus" checked disabled>', $lazarus);
        self::assertStringNotContainsString('data-enables="ai-test-items-lazarus"', $lazarus);
        self::assertStringNotContainsString('name="send_item_answers"', $lazarus);
        self::assertSame(1, substr_count($lazarus, 'Эта методика всегда передаёт оценки по пунктам: так устроен её утверждённый разбор.'));
        self::assertStringContainsString('Уходят всегда: так устроен утверждённый разбор этой методики.', $lazarus);
        self::assertSame(1, substr_count($bdi, 'У этой методики есть пункт о мыслях о смерти; при передаче ответов модель увидит его.'));

        // Флаг задаёт сам модуль: только у Лазаруса оценки по пунктам — часть
        // утверждённого контекста.
        $loader = (new \PsyTest\Core\ModuleLoader(null, null))->discover();
        foreach (array_keys($loader->getAllModules()) as $slug) {
            $module = $loader->getModule((string) $slug);
            self::assertNotNull($module);
            self::assertSame($slug === 'lazarus', $module->aiReportSendsItemsAlways(), (string) $slug);
        }
    }

    public function testMethodologyListShowsStatusPromptStatesAndItems(): void
    {
        $published = [
            'exists' => true, 'state' => 'published', 'published_version' => 2, 'published_at' => null,
            'published_source' => 'file', 'draft_version' => 3, 'draft_at' => null, 'versions' => 3,
            'path' => '/admin/tests/smil/prompts/individual/clear', 'kind_title' => 'понятный разбор', 'mode_title' => 'индивидуальный',
        ];
        $html = $this->twig()->render('owner-tests.twig', [
            'appName' => 'PsyTest', 'basePath' => '', 'csrf_token' => 'synthetic-csrf', 'flash' => null,
            'ai' => ['enabled' => false, 'model' => 'model/env'],
            'methodologies' => [
                $this->methodologyItem('smil', 'СМИЛ', ['status' => 'done', 'status_label' => 'разбор предлагается', 'prompts' => ['individual' => ['clear' => $published, 'professional' => $published]]]),
                $this->methodologyItem('hads', 'HADS', ['active' => false, 'status' => 'muted', 'status_label' => 'методика выключена']),
                $this->methodologyItem('lazarus', 'Лазарус', ['modes' => ['individual', 'pair'], 'items_label' => 'всегда', 'prompts' => [
                    'individual' => ['clear' => $published, 'professional' => $published],
                    'pair' => ['clear' => $published, 'professional' => $published],
                ]]),
            ],
        ]);

        self::assertStringContainsString('class="cab-table tests-table" data-row-links', $html);
        self::assertSame(3, substr_count($html, '<tr data-href="/admin/tests/'));
        self::assertStringContainsString('<span class="status status--done">разбор предлагается</span>', $html);
        self::assertStringContainsString('<span class="status status--muted">методика выключена</span>', $html);
        self::assertStringContainsString('опубликована версия 2', $html);
        self::assertStringContainsString('есть черновик версии 3', $html);
        self::assertStringContainsString('<span class="prompt-state__mode">пара:</span>', $html);
        self::assertStringContainsString('>всегда</td>', $html);
        self::assertStringContainsString('ИИ-разборы выключены для всех методик', $html);
        self::assertStringContainsString('href="/admin/tests/settings">Настройки ИИ</a>', $html);
        self::assertStringContainsString('aria-label="Открыть: СМИЛ"', $html);

        $visible = mb_strtolower(strip_tags($html));
        foreach (self::JARGON as $word) {
            self::assertStringNotContainsString(mb_strtolower($word), $visible, "Служебное слово «{$word}» в списке методик.");
        }
    }

    public function testUniversalPromptsCarryTheSelfHarmRule(): void
    {
        foreach (['clear', 'professional'] as $kind) {
            $text = (string) file_get_contents($this->root . "/prompts/_universal/individual.{$kind}.v1.md");
            self::assertStringContainsString('мысли о смерти или самоповреждении, не преуменьшай', $text, $kind);
            self::assertStringContainsString('явно адресуй специалисту', $text, $kind);
        }
    }

    public function testCaseOrderFormWordingFollowsOfferedKinds(): void
    {
        $case = (string) file_get_contents($this->root . '/templates/owner-invited-case.twig');

        self::assertStringContainsString("ai.offered_kinds ?? ['clear', 'professional']", $case);
        self::assertStringContainsString('Сейчас заказывается один вид — понятный разбор для клиента', $case);
        self::assertStringContainsString("'offered_kinds' => \$availability->offeredKinds(\$testSlug, \$mode)", (string) file_get_contents($this->root . '/controllers/OwnerController.php'));
    }

    public function testStubPromptPageHidesFactoryResetAndSaysWhy(): void
    {
        $prompt = new Prompt('beck-anxiety', 'individual', 'clear', 1, Prompt::STATUS_PUBLISHED, 'Текст заготовки.', false, 'кабинет владельца: заготовка из универсального шаблона');
        $html = $this->twig()->render('owner-prompt-key.twig', [
            'appName' => 'PsyTest', 'basePath' => '', 'csrf_token' => 'synthetic-csrf', 'flash' => null,
            'test' => 'beck-anxiety', 'mode' => 'individual', 'kind' => 'clear',
            'test_title' => 'BAI', 'mode_title' => 'индивидуальный', 'kind_title' => 'понятный разбор',
            'versions' => [['version' => 1, 'source' => 'owner', 'created_at' => null, 'note' => 'заготовка из универсального шаблона']],
            'versions_desc' => [['version' => 1, 'source' => 'owner', 'created_at' => null, 'note' => 'заготовка из универсального шаблона']],
            'methodology_path' => '/admin/tests/beck-anxiety',
            'state' => ['exists' => true, 'state' => 'draft', 'published_version' => null, 'draft_version' => 1],
            'kind_tabs' => [
                ['kind' => 'clear', 'title' => 'понятный разбор', 'path' => '/admin/tests/beck-anxiety/prompts/individual/clear'],
                ['kind' => 'professional', 'title' => 'профессиональное заключение', 'path' => '/admin/tests/beck-anxiety/prompts/individual/professional'],
            ],
            'mode_tabs' => [],
            'published_version' => null, 'from_manifest' => true, 'manifest_version' => null,
            'has_factory_text' => false, 'test_report_enabled' => false,
            'selected' => $prompt, 'selected_version' => 1, 'note_max' => 255, 'variables' => [],
            'ai_enabled' => true, 'preview' => null, 'trial' => null,
        ]);

        self::assertStringNotContainsString('/reset"', $html);
        self::assertStringNotContainsString('Вернуть заводской текст (версия', $html);
        self::assertStringContainsString('заводского текста нет, это заготовка', $html);
        self::assertStringContainsString('Ни одна версия ещё не опубликована', $html);
        self::assertStringContainsString('ИИ-разбор этой методики выключен', $html);
        // Черновик есть, опубликованного нет — основная кнопка публикует его (07.K14).
        self::assertStringContainsString('Опубликовать версию 1…</summary>', $html);
        self::assertStringContainsString('action="/admin/tests/beck-anxiety/prompts/individual/clear/publish"', $html);
        // Путь, «К методике» и вкладки видов.
        self::assertStringContainsString('<a href="/admin/tests">Методики</a>', $html);
        self::assertStringContainsString('<a href="/admin/tests/beck-anxiety">BAI</a>', $html);
        self::assertStringContainsString('class="crumbs__back" href="/admin/tests/beck-anxiety#prompts"', $html);
        self::assertStringContainsString('href="/admin/tests/beck-anxiety/prompts/individual/clear" aria-current="page">Понятный разбор</a>', $html);
        self::assertStringContainsString('href="/admin/tests/beck-anxiety/prompts/individual/professional">Профессиональное заключение</a>', $html);
        foreach (['#prompt-current', '#prompt-edit', '#prompt-history'] as $anchor) {
            self::assertStringContainsString('href="' . $anchor . '" data-case-nav-link', $html);
        }
        self::assertStringContainsString('ИИ-разбор этой методики выключен на <a href="/admin/tests/beck-anxiety#ai">', $html);

        $visible = mb_strtolower(strip_tags($html));
        foreach (['manifest', 'fixture', 'провайдер', 'slug', 'json'] as $word) {
            self::assertStringNotContainsString($word, $visible, "Служебное слово «{$word}» на странице промпта.");
        }
    }

    public function testFactoryKeyPageKeepsTheResetButton(): void
    {
        $template = (string) file_get_contents($this->root . '/templates/owner-prompt-key.twig');

        self::assertStringContainsString('{% if has_factory_text ?? true %}', $template);
        self::assertStringContainsString('Вернуть заводской текст (версия {{ manifest_version }})', $template);
    }

    public function testEveryOfferingPlaceUsesTheSingleAvailabilityRule(): void
    {
        foreach ([
            'core/ResultPresenter.php',
            'core/OwnerCaseReportOrder.php',
            'controllers/ResultController.php',
            'controllers/OwnerController.php',
            'bin/generate-ai-reports.php',
        ] as $file) {
            self::assertStringContainsString('AiReportAvailability', (string) file_get_contents($this->root . '/' . $file), $file);
        }

        $case = (string) file_get_contents($this->root . '/templates/owner-invited-case.twig');
        self::assertStringContainsString('(ai.can_order ?? false)', $case);
        self::assertStringNotContainsString('Сейчас разбор доступен для Лазаруса и СМИЛ', $case);

        $block = (string) file_get_contents($this->root . '/templates/blocks/ai-report.twig');
        self::assertStringNotContainsString('Разбор временно недоступен', $block);
        self::assertStringContainsString('item.can_order', $block);
    }

    public function testArchitectureDocumentsTheRuleAndTheSettingsTable(): void
    {
        $architecture = (string) file_get_contents($this->root . '/ARCHITECTURE.md');

        self::assertStringContainsString('`/admin/prompts/tests`', $architecture);
        self::assertStringContainsString('AiReportAvailability', $architecture);
        self::assertStringContainsString('ai_test_settings', $architecture);
        self::assertStringContainsString('aiReportItems', $architecture);

        $dataMap = (string) file_get_contents($this->root . '/docs/roadmap/DATA_MAP_CURRENT.md');
        self::assertStringContainsString('ai_test_settings', $dataMap);
    }
}
