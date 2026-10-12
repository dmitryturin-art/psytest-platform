<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Ширина контейнеров и текста (04.D8, 04.D9): контейнеры результата тянутся на всю
 * колонку карточки, текст внутри заполняет блок от края до края (без --measure и
 * --measure-wide) на публичных страницах и в кабинете. Продолжение AiReportLayoutContractTest.
 */
final class PublicContainerWidthContractTest extends TestCase
{
    private function css(string $name): string
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/public/css/' . $name);

        return (string) preg_replace('~/\*.*?\*/~s', '', $css);
    }

    /** @return list<array{string, string}> пары «селекторы», «объявления» */
    private function rules(string $css): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $m): array => [trim($m[1]), $m[2]], $matches);
    }

    /** @return list<string> */
    private function capped(string $css, string $variable): array
    {
        $selectors = [];
        foreach ($this->rules($css) as [$selector, $body]) {
            if (preg_match('/max-width:\s*var\(--' . $variable . '\)/', $body) === 1) {
                foreach (array_map('trim', explode(',', $selector)) as $one) {
                    $selectors[] = $one;
                }
            }
        }

        return $selectors;
    }

    public function testContainersAreNotCappedByReadingMeasure(): void
    {
        $main = $this->css('main.css');
        $capped = array_merge($this->capped($main, 'measure'), $this->capped($main, 'measure-wide'));

        foreach (['.recommendations-list', '.validity-warnings', '.results-disclaimer', '.owner-notice'] as $container) {
            self::assertNotContains($container, $capped, $container . ' — контейнер: во всю колонку');
        }
    }

    public function testResultAndReportTextIsNotCapped(): void
    {
        $main = $this->css('main.css');
        $capped = array_merge($this->capped($main, 'measure'), $this->capped($main, 'measure-wide'));

        foreach ([
            '.recommendations-list__item',
            '.validity-warnings .warning',
            '.profile-type-description',
            '.interpretation-item__text',
            '.results-disclaimer p',
            '.interpretation-text',
            '.ai-report__intro',
            '.trait-profile__norms',
            '.trait-row__text',
        ] as $paragraph) {
            self::assertNotContains($paragraph, $capped, $paragraph . ' заполняет блок: без колонки чтения');
        }
        foreach ($capped as $selector) {
            self::assertStringNotContainsString('.ai-report', $selector);
        }
    }

    public function testReadingColumnRemainsOnlyOutsideResultCard(): void
    {
        $capped = $this->capped($this->css('main.css'), 'measure-wide');

        // Инструкция перед тестом и узкая карточка кабинета посетителя — не карточка результата.
        self::assertSame(['.test-instruction__body', '.account-wrapper p'], $capped);
    }

    public function testNarrowMeasureIsNotUsedForPublicText(): void
    {
        $narrow = $this->capped($this->css('main.css'), 'measure');

        // Исключения: список ответов кабинета и центрированная вводная шапки теста.
        self::assertSame(['.owner-answer-list', '.test-description'], $narrow);
    }

    public function testCabinetDoesNotDuplicateTheCommonRules(): void
    {
        $cabinet = $this->css('cabinet.css');

        foreach (['.recommendations-list', '.validity-warnings', '.profile-type-description', '.interpretation-text', '.results-disclaimer'] as $class) {
            self::assertStringNotContainsString($class, $cabinet, $class . ' — общее правило main.css');
        }
        self::assertDoesNotMatchRegularExpression('/\.owner-dashboard-page\s+\.owner-notice\s*\{/', $cabinet);
    }
}
