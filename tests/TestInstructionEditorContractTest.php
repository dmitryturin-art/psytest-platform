<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\TestInstructionOverrides;

/**
 * Редактор инструкции респонденту в кабинете (07.K15): маршруты, форма,
 * подключение подмены ко всем показам. Без БД.
 */
final class TestInstructionEditorContractTest extends TestCase
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

    public function testRoutesArePostOnlyAndDeclaredBeforeNothingShadowsThem(): void
    {
        $routes = $this->read('public/index.php');

        self::assertStringContainsString("\$router->post('/admin/tests/{test}/instruction', [OwnerController::class, 'saveInstruction']);", $routes);
        self::assertStringContainsString("\$router->post('/admin/tests/{test}/instruction/reset', [OwnerController::class, 'resetInstruction']);", $routes);
    }

    public function testOwnerActionsRequireOwnerOneTimeKeyAndRedirectBackToTheSection(): void
    {
        $controller = $this->read('controllers/OwnerController.php');
        $start = strpos($controller, 'public function saveInstruction');
        $end = strpos($controller, 'public function saveMethodologyAi');
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $body = substr($controller, $start, $end - $start);

        self::assertSame(2, substr_count($body, '$this->requireOwner()'));
        self::assertStringContainsString('self::INSTRUCTION_FORM', $body);
        self::assertStringContainsString("'Инструкция сохранена.'", $body);
        self::assertStringContainsString("'Исходная инструкция возвращена.'", $body);
        self::assertSame(2, substr_count($body, "#instruction'"));
    }

    public function testEveryPublicShowingOfTheInstructionGoesThroughTheResolver(): void
    {
        $controller = $this->read('controllers/TestController.php');

        self::assertSame(0, substr_count($controller, '->getInstruction()'), 'Direct file reads would ignore the owner edit.');
        self::assertSame(1, substr_count($controller, '->resolve($module)'));
        self::assertGreaterThanOrEqual(4, substr_count($controller, '$this->instructionOf('));
    }

    public function testTemplateIsPlainTextWithCsrfAndOneTimeKey(): void
    {
        $twig = $this->read('templates/owner-test.twig');

        self::assertStringContainsString('{{ testUrl }}/instruction"', $twig);
        self::assertStringContainsString('{{ testUrl }}/instruction/reset"', $twig);
        self::assertSame(2, preg_match_all('~/instruction(?:/reset)?" class="owner-form">\s*\{\{ csrf_field\(\) \}\}~u', $twig), 'Both instruction forms carry the CSRF field.');
        self::assertStringContainsString('name="form_key" value="{{ instruction_form_key }}"', $twig);
        self::assertStringContainsString('Изменена владельцем', $twig);
        self::assertStringContainsString('Из методики', $twig);
        self::assertStringNotContainsString('|raw', $twig);
    }

    public function testTextFromTheFormSplitsIntoParagraphsByBlankLines(): void
    {
        self::assertSame(
            ['Первый.', "Второй\nв две строки.", 'Третий.'],
            TestInstructionOverrides::paragraphsFromText("\r\n  Первый.  \r\n\r\n\r\nВторой\nв две строки.\n \t \nТретий.\n\n"),
        );
        self::assertSame([], TestInstructionOverrides::paragraphsFromText("  \n\n  "));
    }
}
