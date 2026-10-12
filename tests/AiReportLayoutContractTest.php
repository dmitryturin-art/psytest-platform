<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Ширина текста ИИ-разбора (04.D7): одно правило для публичной страницы результата,
 * кабинета посетителя и кабинета специалиста. Правило лежит в main.css, который
 * подключён на всех страницах; в cabinet.css его дубля быть не должно.
 */
final class AiReportLayoutContractTest extends TestCase
{
    private function css(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/public/css/' . $name);
    }

    public function testBodyContainerIsNotCappedOnPublicPages(): void
    {
        $main = $this->css('main.css');

        self::assertMatchesRegularExpression('/\n\.ai-report__body\s*\{\s*max-width:\s*none;/', $main);

        // Группа «колонка чтения для длинного текста» не должна снова включать контейнер разбора.
        $bare = (string) preg_replace('~/\*.*?\*/~s', '', $main);
        preg_match_all('/([^{}]+)\{([^{}]*max-width:\s*var\(--measure\)[^{}]*)\}/', $bare, $groups, PREG_SET_ORDER);
        foreach ($groups as $group) {
            self::assertStringNotContainsString('.ai-report__body', $group[1], 'Контейнер разбора не должен получать --measure');
            self::assertStringNotContainsString('.ai-report__intro', $group[1], 'Вводная разбора берёт --measure-wide');
        }
    }

    public function testParagraphMeasureIsDefinedOnceInMainCss(): void
    {
        $main = $this->css('main.css');
        $cabinet = $this->css('cabinet.css');

        self::assertSame(
            1,
            preg_match_all('/\.ai-report__body\s*>\s*:is\(p, ul, ol, blockquote, h2, h3, h4, h5\)\s*\{\s*max-width:\s*var\(--measure-wide\);/', $main),
        );
        self::assertSame(0, preg_match_all('/\.ai-report__body\s*>\s*:is\(/', $cabinet), 'Кабинет не дублирует общее правило');
        self::assertMatchesRegularExpression('/\.ai-report__intro\s*\{\s*max-width:\s*var\(--measure-wide\);/', $main);
    }

    public function testMainCssIsLoadedOnEveryPage(): void
    {
        $layout = (string) file_get_contents(dirname(__DIR__) . '/templates/layout.twig');

        self::assertStringContainsString("<link rel=\"stylesheet\" href=\"{{ asset('css/main.css') }}\">", $layout);
        self::assertStringNotContainsString('main.css', preg_replace('/<link[^>]*main\.css[^>]*>/', '', $layout) ?? '');
    }
}
