<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Database;
use PsyTest\Core\ModuleLoader;

/**
 * Закрытая методика: не показывается в каталоге и не открывается прямой ссылкой.
 *
 * Решение владельца 26.08: СМИЛ закрывается, потому что публикация 566
 * формулировок — это распространение авторской адаптации, права на которую
 * не подтверждены. Решение владельца 14.09: общий ключ доступа снят, вход
 * в закрытую методику только по личному приглашению (`/invite/{token}`).
 * Тесты стерегут именно закрытость, а не удобство.
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

    public function testSharedAccessKeyIsGoneFromTheGuard(): void
    {
        // Общий ключ был затычкой: его негде хранить, перевыпуск требовал SSH,
        // и он не отвечал на вопрос, кто именно прошёл методику.
        $controller = (string) file_get_contents(dirname(__DIR__) . '/controllers/TestController.php');

        $guard = substr($controller, (int) strpos($controller, 'private function grantsInviteAccess'));
        $guard = substr($guard, 0, (int) strpos($guard, 'private function notFoundTest'));

        self::assertStringNotContainsString('access_key', $guard);
        self::assertStringNotContainsString("\$_GET['key']", $guard);
        self::assertStringNotContainsString('psytest_invite_', $guard);
        self::assertStringNotContainsString('$_SESSION', $guard);
    }

    public function testClosedMethodologyIsDeniedEvenWithAKeyInTheAddress(): void
    {
        // Единственный вход в закрытую методику — персональное приглашение,
        // которое создаёт сессию само и не проходит через этот guard.
        $controller = (string) file_get_contents(dirname(__DIR__) . '/controllers/TestController.php');

        $guard = substr($controller, (int) strpos($controller, 'private function grantsInviteAccess'));
        $guard = substr($guard, 0, (int) strpos($guard, 'private function notFoundTest'));

        self::assertStringContainsString("!== 'invite'", $guard);
        self::assertStringNotContainsString('return true;', $guard);
    }

    public function testInvitationPathStaysTheOnlyEntranceToAClosedMethodology(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/controllers/TestController.php');

        self::assertStringContainsString('public function startInvite(', $controller);
        // Старт по приглашению не спрашивает видимость: доступ уже доказан токеном.
        $startInvite = substr($controller, (int) strpos($controller, 'public function startInvite('));
        $startInvite = substr($startInvite, 0, (int) strpos($startInvite, 'public function start('));
        self::assertStringNotContainsString('grantsInviteAccess', $startInvite);
    }

    #[Group('database')]
    public function testClosedMethodologyNoLongerCarriesASharedKeyColumn(): void
    {
        $columns = Database::getInstance()->select("SHOW COLUMNS FROM tests LIKE 'access_key'");

        self::assertSame([], $columns, 'Колонка общего ключа должна быть снята миграцией.');

        $row = Database::getInstance()->selectOne("SELECT visibility FROM tests WHERE slug = 'smil'");
        self::assertIsArray($row);
        self::assertSame('invite', $row['visibility'], 'СМИЛ остаётся закрытой методикой.');
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
    public function testOtherMethodologiesStayOpen(): void
    {
        $public = (new ModuleLoader(null, null))->discover()->getPublicModules();

        foreach (['lazarus', 'hads', 'beck-anxiety', 'bdi'] as $slug) {
            self::assertArrayHasKey($slug, $public, "Методика {$slug} должна остаться в открытом каталоге.");
        }
    }
}
