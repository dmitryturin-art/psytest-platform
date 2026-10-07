<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

/** 04.D3a: ширина внутри раскрывающихся блоков, кнопка «Скопировать», центровка вариантов СМИЛ. */
final class CaseWorkspacePolishContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    private function file(string $path): string
    {
        return (string) file_get_contents($this->root . '/' . $path);
    }

    public function testCopyButtonMarkupScriptAndIcons(): void
    {
        $icons = $this->file('templates/blocks/icons.twig');
        self::assertStringContainsString('id="i-copy"', $icons);
        self::assertStringContainsString('id="i-check"', $icons);

        foreach (['templates/owner-dashboard.twig', 'templates/owner-client.twig'] as $tpl) {
            $twig = $this->file($tpl);
            self::assertStringContainsString('data-copy-target="owner-invite-url-field"', $twig, $tpl);
            self::assertStringContainsString('btn btn-outline btn-sm owner-copy', $twig, $tpl);
            self::assertMatchesRegularExpression('/owner-copy[^>]*\shidden>/', $twig, $tpl);
            self::assertStringContainsString('js/owner-copy.js', $twig, $tpl);
        }

        $js = $this->file('public/js/owner-copy.js');
        foreach (['navigator.clipboard.writeText', "execCommand('copy')", 'Скопировано', '#i-check'] as $needle) {
            self::assertStringContainsString($needle, $js, $needle);
        }
    }

    public function testQuestionnaireCardsAreNotCappedAndReportsAreWide(): void
    {
        $cabinet = $this->file('public/css/cabinet.css');
        self::assertMatchesRegularExpression('/\.case-fold__body \.owner-answer-list\s*\{\s*max-width:\s*none;/', $cabinet);
        self::assertStringContainsString('.case-reader {', $cabinet);
        self::assertStringContainsString('var(--measure-wide)', $cabinet);
        self::assertStringContainsString('--measure-wide: 80ch', $this->file('public/css/main.css'));
    }

    public function testTernaryOptionsAreCenteredOnDesktopOnly(): void
    {
        $css = $this->file('public/css/main.css');
        self::assertMatchesRegularExpression('/\.answer-options\.has-ternary\.answer-options--center\s*\{\s*justify-content:\s*center;/', $css);
        self::assertMatchesRegularExpression('/@media[^{]*max-width:\s*600px[^{]*\{.*\.answer-options\.has-ternary\.answer-options--center\s*\{\s*justify-content:\s*flex-start;/s', $css);
        self::assertStringContainsString('answer-options--center', $this->file('templates/test-wrapper.twig'));
    }
}
