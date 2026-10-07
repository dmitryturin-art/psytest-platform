<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

/**
 * 04.T1: the test-taking script relies on hooks exposed by the wrapper template.
 * If a hook is renamed the keyboard/estimate features silently stop working.
 */
final class TestTakingKeysContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    public function testWrapperExposesHooksUsedByScript(): void
    {
        $twig = (string) file_get_contents($this->root . '/templates/test-wrapper.twig');
        $js = (string) file_get_contents($this->root . '/public/js/test-taking.js');

        foreach (['id="progressEstimate"', 'data-estimated-minutes=', 'id="keyHint"', 'aria-live="polite"', 'class="answer-option', 'class="question-card', 'question-card--dual', 'test-taking.css'] as $hook) {
            self::assertStringContainsString($hook, $twig, $hook);
        }
        foreach (['progressEstimate', 'keyHint', 'estimatedMinutes', 'question-card--dual', '.answer-option'] as $used) {
            self::assertStringContainsString($used, $js, $used);
        }
    }

    public function testBadgesAreDecorativeAndHiddenOnTouch(): void
    {
        $js = (string) file_get_contents($this->root . '/public/js/test-taking.js');
        $css = (string) file_get_contents($this->root . '/public/css/test-taking.css');

        self::assertStringContainsString("setAttribute('aria-hidden', 'true')", $js);
        self::assertMatchesRegularExpression('/@media \(hover: none\)\s*\{[^}]*answer-option__key[^}]*test-key-hint/s', $css);
        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $css, 'Only design tokens, no new colours');
    }

    public function testHintStorageIsGuarded(): void
    {
        $js = (string) file_get_contents($this->root . '/public/js/test-taking.js');

        self::assertMatchesRegularExpression('/try\s*\{\s*return window\.localStorage\.getItem/', $js);
        self::assertMatchesRegularExpression('/try\s*\{\s*window\.localStorage\.setItem/', $js);
    }

    public function testOwnerTemplatesAreNotTouchedByThisPackage(): void
    {
        $css = (string) file_get_contents($this->root . '/public/css/test-taking.css');
        $twig = (string) file_get_contents($this->root . '/templates/test-wrapper.twig');

        self::assertStringNotContainsString('owner-', $css);
        self::assertStringNotContainsString('owner-', $twig);
        self::assertStringNotContainsString('cabinet.css', $twig);
    }
}
