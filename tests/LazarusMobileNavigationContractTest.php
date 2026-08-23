<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

final class LazarusMobileNavigationContractTest extends TestCase
{
    public function testQuestionChangeDoesNotJumpBackToThePageHeader(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__) . '/public/js/test-taking.js');
        $showQuestion = substr($script, (int) strpos($script, 'function showQuestion'), (int) strpos($script, 'function goToPreviousQuestion'));

        self::assertStringNotContainsString('window.scrollTo', $showQuestion);
    }

    public function testLazarusMobileChoicesUseTouchSafeGridAndClearPreviousLabel(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/public/css/main.css');
        $template = (string) file_get_contents(dirname(__DIR__) . '/templates/test-wrapper.twig');

        self::assertStringContainsString('grid-template-columns: repeat(5, minmax(44px, 1fr))', $css);
        self::assertStringContainsString('min-height: 44px', $css);
        self::assertStringContainsString('Предыдущий вопрос', $template);
    }
}
