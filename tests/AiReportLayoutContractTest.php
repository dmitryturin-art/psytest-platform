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

    public function testReportTextFillsTheBlockWithoutReadingColumn(): void
    {
        $main = (string) preg_replace('~/\*.*?\*/~s', '', $this->css('main.css'));
        $cabinet = (string) preg_replace('~/\*.*?\*/~s', '', $this->css('cabinet.css'));

        // 04.D9: у текста разбора нет колонки чтения ни на публичных страницах, ни в кабинете.
        self::assertDoesNotMatchRegularExpression('/\.ai-report__body\s*>\s*:is\(/', $main);
        self::assertDoesNotMatchRegularExpression('/\.ai-report__intro\s*\{[^}]*max-width/', $main);
        self::assertDoesNotMatchRegularExpression('/\.ai-report[^{]*\{[^}]*max-width:\s*var\(--measure/', $main);
        self::assertDoesNotMatchRegularExpression('/\.ai-report[^{]*\{[^}]*max-width:\s*var\(--measure/', $cabinet);
    }

    public function testJustifyRuleIsDefinedOnceAndOnlyOnDesktop(): void
    {
        $main = (string) preg_replace('~/\*.*?\*/~s', '', $this->css('main.css'));
        $cabinet = (string) preg_replace('~/\*.*?\*/~s', '', $this->css('cabinet.css'));

        self::assertSame(1, substr_count($main, 'text-align: justify'));
        self::assertSame(0, substr_count($cabinet, 'text-align: justify'));
        self::assertSame(1, preg_match('/@media \(min-width: 601px\)\s*\{([^@]*?text-align: justify[^@]*?)\}\s*\}/s', $main, $m), 'justify только от 601 px');
        self::assertStringContainsString('.ai-report__body :is(p, li)', $m[1]);
        self::assertStringContainsString('hyphens: auto', $m[1]);
        // Заголовки, ячейки таблиц и подписи не выравниваются по ширине.
        self::assertDoesNotMatchRegularExpression('/\b(h[1-6]|td|th|caption|figcaption)\b/', $m[1]);
    }

    public function testHtmlLanguageIsRussianForHyphenation(): void
    {
        $layout = (string) file_get_contents(dirname(__DIR__) . '/templates/layout.twig');

        self::assertStringContainsString('<html lang="ru">', $layout);
    }

    public function testMainCssIsLoadedOnEveryPage(): void
    {
        $layout = (string) file_get_contents(dirname(__DIR__) . '/templates/layout.twig');

        self::assertStringContainsString("<link rel=\"stylesheet\" href=\"{{ asset('css/main.css') }}\">", $layout);
        self::assertStringNotContainsString('main.css', preg_replace('/<link[^>]*main\.css[^>]*>/', '', $layout) ?? '');
    }

    public function testHeadingNamesTheModelOnlyWhenTheModelWrote(): void
    {
        $twig = (string) file_get_contents(dirname(__DIR__) . '/templates/blocks/ai-report.twig');

        self::assertSame(1, substr_count($twig, 'Расширенный разбор с нейросетью'));
        self::assertStringContainsString('Подготовлен языковой моделью по рассчитанным показателям.', $twig);
        self::assertStringNotContainsString('Подробный разбор результата', $twig);
        // Ветка специалиста (restricted) идёт первой и называется нейтрально.
        self::assertLessThan(
            strpos($twig, 'Расширенный разбор с нейросетью'),
            strpos($twig, '<h2 class="ai-report__title">Расширенный разбор</h2>'),
        );
    }
}
