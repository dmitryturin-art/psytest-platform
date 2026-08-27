<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Database;
use PsyTest\Core\ModuleLoader;

/**
 * Закрытая методика: не показывается в каталоге и не отдаётся без ключа.
 *
 * Решение владельца 26.08: СМИЛ закрывается ссылкой-приглашением, потому что
 * публикация 566 формулировок — это распространение авторской адаптации,
 * права на которую не подтверждены. Тесты стерегут именно закрытость,
 * а не удобство.
 */
final class TestVisibilityContractTest extends TestCase
{
    public function testCatalogueAsksTheLoaderForPublicModulesOnly(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/controllers/HomeController.php');

        self::assertStringContainsString('getPublicModules()', $controller);
        self::assertStringNotContainsString(
            'getActiveModules()',
            $controller,
            'Каталог обязан спрашивать публичный список: активная методика может быть закрытой.',
        );
    }

    public function testClosedTestAnswersNotFoundInsteadOfForbidden(): void
    {
        // «Запрещено» подтверждало бы посторонним сам факт существования методики.
        $controller = (string) file_get_contents(dirname(__DIR__) . '/controllers/TestController.php');

        self::assertStringContainsString('grantsInviteAccess($test)', $controller);
        self::assertStringContainsString('notFoundTest($slug)', $controller);
        self::assertStringContainsString('http_response_code(404)', $controller);
    }

    public function testAccessKeyIsComparedInConstantTime(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/controllers/TestController.php');

        self::assertStringContainsString('hash_equals($expected, $provided)', $controller);
        self::assertStringNotContainsString('$expected === $provided', $controller);
    }

    public function testClosedTestWithoutAKeyIsDeniedRatherThanOpened(): void
    {
        // Незаполненная настройка не должна открывать методику: это тот случай,
        // когда безопаснее отказать, чем пустить.
        $controller = (string) file_get_contents(dirname(__DIR__) . '/controllers/TestController.php');

        $guard = substr($controller, (int) strpos($controller, 'private function grantsInviteAccess'));
        $guard = substr($guard, 0, (int) strpos($guard, 'private function notFoundTest'));

        self::assertStringContainsString("if (\$expected === '') {", $guard);
        self::assertStringContainsString('return false;', $guard);
    }

    #[Group('database')]
    public function testPublicCatalogueExcludesInviteOnlyMethodologies(): void
    {
        $loader = (new ModuleLoader(null, null))->discover();

        $public = $loader->getPublicModules();
        $active = $loader->getActiveModules();

        self::assertNotEmpty($active, 'Предусловие: в базе есть активные методики.');
        self::assertArrayHasKey('smil', $active, 'СМИЛ остаётся активной методикой — она закрыта, а не выключена.');
        self::assertArrayNotHasKey('smil', $public, 'Закрытая методика не должна попадать в публичный каталог.');

        foreach ($public as $slug => $test) {
            self::assertSame('public', $test['visibility'] ?? 'public', "Методика {$slug} закрыта, но попала в каталог.");
        }
    }

    #[Group('database')]
    public function testClosedMethodologyKeepsAKeyLongEnoughToResistGuessing(): void
    {
        $row = Database::getInstance()->selectOne("SELECT visibility, access_key FROM tests WHERE slug = 'smil'");

        self::assertIsArray($row);
        self::assertSame('invite', $row['visibility']);
        self::assertGreaterThanOrEqual(32, strlen((string) $row['access_key']));
    }

    #[Group('database')]
    public function testOtherMethodologiesStayOpen(): void
    {
        $public = (new ModuleLoader(null, null))->discover()->getPublicModules();

        foreach (['lazarus', 'hads', 'beck-anxiety', 'bdi'] as $slug) {
            self::assertArrayHasKey($slug, $public, "Методика {$slug} должна остаться в открытом каталоге.");
        }
    }
}
