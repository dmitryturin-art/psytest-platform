<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

/** Редактор промптов с предпросмотром и мобильная шапка кейса (07.WP9b, 04.D3). */
final class PromptEditorContractTest extends TestCase
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

    public function testPromptPageKeepsTheTextareaAsTheSubmittedFieldAndCarriesEditorHooks(): void
    {
        $page = $this->read('templates/owner-prompt-key.twig');

        // Источник правды — та же textarea (no-JS запасной вариант).
        self::assertStringContainsString('<textarea id="prompt-text" name="text"', $page);
        self::assertStringContainsString('data-prompt-editor>', $page);
        self::assertStringContainsString('data-prompt-editor-form', $page);
        self::assertStringContainsString('data-preview-url="{{ key_path }}/preview"', $page);
        self::assertStringContainsString('data-prompt-preview-toggle', $page);
        self::assertStringContainsString('data-prompt-var-select', $page);
        self::assertStringContainsString("asset('js/owner-prompt-editor.js')", $page);
        // Ключи FormOnce и защита форм не тронуты.
        self::assertStringContainsString('name="confirm_publish" value="1" required', $page);
        self::assertStringContainsString('name="confirm_trial" value="1" required', $page);
    }

    public function testVariablesComeFromThePromptContextNotFromTheTemplate(): void
    {
        $page = $this->read('templates/owner-prompt-key.twig');
        $controller = $this->read('controllers/OwnerController.php');

        self::assertStringContainsString('{% for variable in variables %}', $page);
        self::assertStringContainsString("'variables' => \$this->promptVariables(\$test, \$mode)", $controller);
        self::assertStringContainsString('PromptFixtureContext::build($module, $mode', $controller);
        // В шаблоне нет зашитых имён полей контекста.
        foreach (['validity', 'additional_scales', 'glossary_mode"'] as $hardcoded) {
            self::assertStringNotContainsString('value="' . $hardcoded, $page);
        }
    }

    public function testPromptPageLoadsOnlySelfHostedAssets(): void
    {
        $page = $this->read('templates/owner-prompt-key.twig');

        preg_match_all('/<(?:script|link)[^>]+(?:src|href)="([^"]+)"/', $page, $all);
        foreach ($all[1] as $url) {
            if (str_starts_with($url, '{{')) {
                self::assertStringContainsString("asset('", $url);
                continue;
            }
            self::assertStringNotContainsString('//', $url, "Внешний ресурс на странице промпта: {$url}");
        }
        self::assertDoesNotMatchRegularExpression('#https?://#', $page);

        $script = $this->read('public/js/owner-prompt-editor.js');
        self::assertDoesNotMatchRegularExpression('#https?://#', $script);
        // Как в редакторе разбора: визуальный режим по умолчанию и переключатель
        // режимов на месте (решение владельца 08.10).
        self::assertStringContainsString("initialEditType: 'wysiwyg'", $script);
        self::assertStringContainsString('hideModeSwitch: false', $script);
        self::assertStringContainsString("X-CSRF-Token", $script);
    }

    public function testDraftPreviewRouteIsPostWithOwnerGateAndNoPersistence(): void
    {
        $routes = $this->read('public/index.php');
        $controller = $this->read('controllers/OwnerController.php');

        self::assertStringContainsString(
            "\$router->post('/admin/prompts/{test}/{mode}/{kind}/preview', [OwnerController::class, 'promptDraftPreview'])",
            $routes,
        );
        // Глобальный CSRF-middleware закрывает POST, исключений для preview нет.
        self::assertStringContainsString('new CsrfMiddleware([\'/webhook/yoomoney\'])', $routes);

        $offset = strpos($controller, 'public function promptDraftPreview(');
        self::assertIsInt($offset);
        $end = strpos($controller, 'public function createPromptVersion(', $offset);
        $body = substr($controller, $offset, (int) $end - $offset);
        self::assertStringContainsString('requireOwner()', substr($body, 0, 260));
        foreach (['createOwnerVersion', 'publishVersion', 'AiClient', 'setFlash'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $body);
        }
    }

    public function testCaseHeaderClientCellDoesNotOverflowOnPhones(): void
    {
        $css = $this->read('public/css/cabinet.css');

        $media = strpos($css, '@media (max-width: 600px) {', (int) strpos($css, '.case-head__actions {'));
        self::assertIsInt($media);
        $block = substr($css, $media, 2600);
        self::assertStringContainsString('.case-head .owner-case-client__form .btn', $block);
        self::assertStringContainsString('white-space: normal', $block);
        self::assertStringContainsString('grid-column: 1 / -1', $block);
        self::assertStringContainsString('overflow-wrap: anywhere', $block);
    }
}
