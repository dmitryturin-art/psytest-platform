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

    private function promptsPage(): string
    {
        return $this->twig()->render('owner-prompts.twig', [
            'appName' => 'PsyTest', 'basePath' => '', 'csrf_token' => 'synthetic-csrf',
            'flash' => null, 'ai_enabled' => true, 'ai_model' => '', 'smil_glossary_mode' => 'compact',
            'env_model' => 'model/env', 'models' => [], 'tests_form_key' => 'tests-key',
            'methodologies' => [
                ['test' => 'smil', 'title' => 'СМИЛ', 'report_enabled' => true, 'send_item_answers' => false, 'published' => true],
                ['test' => 'beck-anxiety', 'title' => 'Шкала тревоги Бека (BAI)', 'report_enabled' => true, 'send_item_answers' => true, 'published' => false],
                ['test' => 'hads', 'title' => 'HADS', 'report_enabled' => false, 'send_item_answers' => false, 'published' => false],
                ['test' => 'bdi', 'title' => 'BDI', 'report_enabled' => true, 'send_item_answers' => false, 'published' => false, 'clinical_signal' => true],
                ['test' => 'lazarus', 'title' => 'Лазарус', 'report_enabled' => true, 'send_item_answers' => false, 'published' => true, 'items_always' => true],
            ],
            'groups' => [[
                'test' => 'beck-anxiety', 'title' => 'Шкала тревоги Бека (BAI)',
                'keys' => [[
                    'test' => 'beck-anxiety', 'mode' => 'individual', 'kind' => 'clear',
                    'mode_title' => 'индивидуальный', 'kind_title' => 'понятный разбор',
                    'version' => null, 'source' => 'owner', 'created_at' => null,
                    'from_manifest' => true, 'has_factory_text' => false,
                ]],
            ]],
        ]);
    }

    public function testPromptsPageHasTwoCheckboxesPerMethodologyWithPlainHelp(): void
    {
        $html = $this->promptsPage();

        self::assertStringContainsString('action="/admin/prompts/tests"', $html);
        self::assertStringContainsString('name="form_key" value="tests-key"', $html);
        self::assertSame(5, substr_count($html, 'name="report_enabled['));
        // У Лазаруса вторая галочка заперта и в форму не уходит.
        self::assertSame(4, substr_count($html, 'name="send_item_answers['));
        self::assertSame(5, substr_count($html, 'ИИ-разбор для этой методики'));
        self::assertSame(5, substr_count($html, 'Передавать модели ответы по пунктам'));

        // Вторая галочка недоступна, пока первая снята, и связана с ней.
        self::assertMatchesRegularExpression('/id="ai-test-items-hads" name="send_item_answers\[hads\]" value="1" disabled>/', $html);
        self::assertStringContainsString('data-enables="ai-test-items-beck-anxiety"', $html);
        self::assertMatchesRegularExpression('/id="ai-test-items-beck-anxiety" name="send_item_answers\[beck-anxiety\]" value="1" checked>/', $html);
        self::assertStringContainsString("asset('js/owner-forms.js')", (string) file_get_contents($this->root . '/templates/owner-prompts.twig'));

        // Что уходит модели и что не уходит — простыми словами.
        self::assertStringContainsString('баллы, уровни и подшкалы', $html);
        self::assertStringContainsString('Имя, контакты и ваши заметки не уходят никогда', $html);
        self::assertStringContainsString('ответ человека на каждый вопрос опросника', $html);
        self::assertStringContainsString('промпт не опубликован', $html);
        self::assertStringContainsString('Ещё не опубликован — заготовка из универсального шаблона', $html);
        self::assertStringNotContainsString('заводской текст.', $html, 'У заготовки нет заводского текста.');

        $visible = mb_strtolower(strip_tags($html));
        foreach (self::JARGON as $word) {
            self::assertStringNotContainsString(mb_strtolower($word), $visible, "Служебное слово «{$word}» на странице владельца.");
        }
    }

    public function testLazarusItemCheckboxIsLockedOnAndBdiWarnsAboutTheSuicideItem(): void
    {
        $html = $this->promptsPage();

        self::assertStringContainsString('<input type="checkbox" id="ai-test-items-lazarus" checked disabled>', $html);
        self::assertStringNotContainsString('data-enables="ai-test-items-lazarus"', $html);
        self::assertSame(1, substr_count($html, 'Эта методика всегда передаёт оценки по пунктам: так устроен её утверждённый разбор.'));
        self::assertSame(1, substr_count($html, 'У этой методики есть пункт о мыслях о смерти; при передаче ответов модель увидит его.'));

        // Флаг задаёт сам модуль: только у Лазаруса оценки по пунктам — часть
        // утверждённого контекста.
        $loader = (new \PsyTest\Core\ModuleLoader(null, null))->discover();
        foreach (array_keys($loader->getAllModules()) as $slug) {
            $module = $loader->getModule((string) $slug);
            self::assertNotNull($module);
            self::assertSame($slug === 'lazarus', $module->aiReportSendsItemsAlways(), (string) $slug);
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
        self::assertStringContainsString('Опубликовать эту версию', $html);
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
