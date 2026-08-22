<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

final class PublicCatalogPresentationTest extends TestCase
{
    public function testHomeRendersLandingInsteadOfRedirectingToCatalog(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/controllers/HomeController.php');

        self::assertStringContainsString("render('home'", $controller);
        self::assertStringNotContainsString("header('Location: /tests')", $controller);
    }

    public function testLandingAndCatalogKeepFreeResultPromiseAndRealLinks(): void
    {
        $projectRoot = dirname(__DIR__);
        $home = (string) file_get_contents($projectRoot . '/templates/home.twig');
        $catalog = (string) file_get_contents($projectRoot . '/templates/tests-list.twig');

        self::assertStringContainsString('Базовый результат бесплатный', $home);
        self::assertStringContainsString('Расширенные разборы пока не включены на тестовом стенде', $home);
        self::assertStringContainsString('href="{{ basePath }}/test/{{ test.slug }}"', $home);
        self::assertStringContainsString('href="{{ basePath }}/test/{{ test.slug }}"', $catalog);
        self::assertStringContainsString('Бесплатно', $catalog);
        self::assertStringNotContainsString('120 ₽', $home . $catalog);
    }

    public function testEditorialStylesAreRouteSpecificAndSmilResultIsUntouched(): void
    {
        $projectRoot = dirname(__DIR__);
        $styles = (string) file_get_contents($projectRoot . '/public/css/editorial-catalog.css');
        $smilResult = (string) file_get_contents($projectRoot . '/templates/result-page.twig');

        self::assertStringContainsString('.editorial-home-page', $styles);
        self::assertStringContainsString('.tests-list-page', $styles);
        self::assertStringNotContainsString('smil-results', $styles);
        self::assertStringContainsString('smil-profile-classic.js', $smilResult);
    }
}
