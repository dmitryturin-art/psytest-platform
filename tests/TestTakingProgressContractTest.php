<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

final class TestTakingProgressContractTest extends TestCase
{
    public function testSavingTheLastAnswerRefreshesProgressWithoutNavigation(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__) . '/public/js/test-taking.js');

        self::assertMatchesRegularExpression(
            '/function saveAnswer\(input\)\s*\{.*?answers\[questionId\[1\]\] = value;.*?updateProgress\(\);.*?\n\s*\}/s',
            $script,
            'Progress must refresh when an answer is saved because the last question does not navigate further.'
        );
    }

    public function testNavigationBarIsHiddenWhenTheFirstQuestionHasNoAvailableAction(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__) . '/public/js/test-taking.js');

        self::assertStringContainsString(
            "testNavigation.style.display = hasVisibleAction ? 'flex' : 'none';",
            $script,
            'The sticky navigation must not reserve an empty bar on the first question.'
        );
    }
}
