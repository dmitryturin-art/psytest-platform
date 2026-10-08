<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Раздел «Методики» (07.K14): старые адреса «Промптов» перенаправляются,
 * навигация кабинета ведёт в новый раздел, страницы собраны из описи UI_KIT.
 */
final class MethodologySectionContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    private function read(string $path): string
    {
        return (string) file_get_contents($this->root . '/' . $path);
    }

    public function testOldPromptAddressesRedirectToTheMethodologySection(): void
    {
        $routes = $this->read('public/index.php');
        $controller = $this->read('controllers/OwnerController.php');

        foreach ([
            "\$router->get('/admin/prompts', [OwnerController::class, 'legacyPrompts'])",
            "\$router->post('/admin/prompts/settings', [OwnerController::class, 'legacyPromptSettings'])",
            "\$router->get('/admin/prompts/{test}/{mode}/{kind}', [OwnerController::class, 'legacyPromptKey'])",
            "\$router->get('/admin/prompts/{test}/{mode}/{kind}/{action}', [OwnerController::class, 'legacyPromptAction'])",
            "\$router->post('/admin/prompts/{test}/{mode}/{kind}/{action}', [OwnerController::class, 'legacyPromptAction'])",
        ] as $route) {
            self::assertStringContainsString($route, $routes);
        }

        // GET — 301, POST — 308: открытая до обновления вкладка отправит ту же форму по новому адресу.
        self::assertStringContainsString("\$this->movedPermanently(self::TESTS_PATH, 301);", $controller);
        self::assertStringContainsString("\$this->movedPermanently(self::TESTS_PATH . '/settings', 308);", $controller);
        self::assertStringContainsString("\$isGet ? 301 : 308", $controller);
        // Перенаправляются только известные действия страницы промпта.
        self::assertStringContainsString("private const LEGACY_PROMPT_ACTIONS = ['preview', 'versions', 'publish', 'reset', 'trial'];", $controller);
        self::assertStringContainsString("return self::TESTS_PATH . '/' . rawurlencode(\$test) . '/prompts/'", $controller);
    }

    public function testNoTemplateLinksTheOldPromptsSection(): void
    {
        foreach (glob($this->root . '/templates/{,blocks/}*.twig', GLOB_BRACE) ?: [] as $file) {
            self::assertStringNotContainsString('/admin/prompts', (string) file_get_contents($file), basename($file));
        }
        self::assertFileDoesNotExist($this->root . '/templates/owner-prompts.twig');

        $nav = $this->read('templates/blocks/owner-nav.twig');
        self::assertStringContainsString('/admin/tests"', $nav);
        self::assertStringContainsString('>Методики</a>', $nav);
        self::assertStringNotContainsString('Промпты', $nav);
        foreach (['owner-tests', 'owner-test', 'owner-prompt-key', 'owner-ai-settings'] as $page) {
            self::assertStringContainsString(
                "{% include 'blocks/owner-nav.twig' with {current: 'tests'} %}",
                $this->read("templates/{$page}.twig"),
                $page,
            );
        }
    }

    public function testPagesReuseTheCaseWorkspaceSectionNavAndTheBreadcrumbs(): void
    {
        foreach (['owner-test', 'owner-prompt-key'] as $page) {
            $template = $this->read("templates/{$page}.twig");
            self::assertStringContainsString('class="case-nav', $template, $page);
            self::assertStringContainsString('data-case-nav>', $template, $page);
            self::assertStringContainsString("asset('js/owner-case-workspace.js')", $template, $page);
            self::assertStringContainsString("{% include 'blocks/owner-crumbs.twig'", $template, $page);
        }
        self::assertStringContainsString("{% include 'blocks/owner-crumbs.twig'", $this->read('templates/owner-ai-settings.twig'));
        self::assertStringContainsString('class="report-cards"', $this->read('templates/owner-test.twig'));
        self::assertStringContainsString('class="cab-table tests-table"', $this->read('templates/owner-tests.twig'));
        // История версий свёрнута в тихий раскрывающийся блок.
        self::assertStringContainsString('disclosure-stack', $this->read('templates/owner-prompt-key.twig'));
        // Путь без отдельной ссылки «Назад» (решение владельца 08.10).
        foreach (['owner-test', 'owner-prompt-key', 'owner-ai-settings', 'blocks/owner-crumbs'] as $page) {
            $template = $this->read("templates/{$page}.twig");
            self::assertStringNotContainsString('К методик', $template, $page);
            self::assertStringNotContainsString('crumbs__back', $template, $page);
        }
    }

    public function testAutosaveSendsCsrfAndTheOneTimeKeyAndKeepsANoScriptButton(): void
    {
        $script = $this->read('public/js/owner-tests.js');
        $page = $this->read('templates/owner-test.twig');

        self::assertStringContainsString("'X-CSRF-Token'", $script);
        self::assertStringContainsString("'Accept': 'application/json'", $script);
        self::assertStringContainsString('keyField.value = data.form_key', $script);
        self::assertStringContainsString('submit.hidden = true', $script);
        self::assertStringContainsString('}, 2000);', $script, '«Сохранено» держится 2 секунды.');
        self::assertDoesNotMatchRegularExpression('#https?://#', $script);
        self::assertStringContainsString('{{ csrf_field() }}', $page);
        self::assertStringContainsString('data-autosave-submit>Сохранить</button>', $page);

        $controller = $this->read('controllers/OwnerController.php');
        $offset = strpos($controller, 'public function saveMethodologyAi(string $test): void');
        self::assertIsInt($offset);
        $body = substr($controller, $offset, 2400);
        self::assertStringContainsString('requireOwner()', substr($body, 0, 200));
        self::assertStringContainsString('$this->formOnce()->run(', $body);
        self::assertStringContainsString("'form_key' => \$this->formOnce()->issue(self::PROMPT_TESTS_FORM)", $body);
    }

    /**
     * 07.K14b: одна колонка. Контейнеры кабинета не ограничены по ширине — колонку
     * чтения держат только абзацы; подсказка всегда мелкая.
     */
    public function testCabinetContainersSpanTheColumnAndOnlyParagraphsKeepTheMeasure(): void
    {
        $cabinet = $this->read('public/css/cabinet.css');
        $main = $this->read('public/css/main.css');

        foreach ([
            '.prompt-tools', '.owner-prompt-vars', '.owner-dashboard-page .test-ai-form', '.model-input',
            '.prompt-facts', '.prompt-output', '.owner-dashboard-page .ai-settings-form', '.test-ai-more',
            '.owner-dashboard-page .case-note__form',
        ] as $selector) {
            $pattern = '/(?:^|\n)' . preg_quote($selector, '/') . ' \{([^}]*)\}/';
            self::assertSame(1, preg_match($pattern, $cabinet, $m), $selector);
            self::assertStringNotContainsString('max-width', $m[1], $selector);
        }

        self::assertStringNotContainsString('.owner-dashboard-page .case-reader {', $cabinet);
        self::assertMatchesRegularExpression(
            '/\.owner-dashboard-page p\.owner-help,\s*\.owner-dashboard-page p\.owner-panel-intro \{\s*max-width: var\(--measure-wide\);/',
            $cabinet,
        );
        self::assertMatchesRegularExpression('/\.owner-dashboard-page \.owner-help \{\s*font-size: var\(--font-size-sm\);/', $cabinet);
        self::assertStringContainsString('--spacing-section: 2.5rem', $main);

        // Общий список колонки ~68 знаков больше не захватывает подсказки и вводный текст кабинета.
        $list = substr($main, (int) strpos($main, '/* Длинный текст'), 600);
        self::assertStringNotContainsString(".owner-help,\n", $list);
        self::assertStringNotContainsString(".owner-panel-intro,\n", $list);

        self::assertStringContainsString(
            '<a class="btn btn-outline btn-sm" href="{{ key_path }}/preview?version=',
            $this->read('templates/owner-prompt-key.twig'),
        );
        $kit = $this->read('docs/UI_KIT.md');
        self::assertStringContainsString('## Принципы вёрстки', $kit);
        self::assertStringContainsString('## Сетка и ритм страниц кабинета', $kit);
    }
}
