<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\AnswerMerger;
use PsyTest\Core\AnswerValidator;
use PsyTest\Modules\BeckDepression\BeckDepressionModule;

final class AnswerMergerTest extends TestCase
{
    public function testOverlayPreservesBdiNumericQuestionIdsForCompleteSubmission(): void
    {
        $submitted = array_fill(1, 21, 0);

        $answers = AnswerMerger::overlay([], $submitted);

        self::assertSame(range(1, 21), array_keys($answers));
        self::assertSame([], AnswerValidator::validate(new BeckDepressionModule(), $answers, true));
    }

    public function testOverlayKeepsSavedAnswersAndLetsSubmittedValueWin(): void
    {
        $answers = AnswerMerger::overlay([1 => 1, 2 => 0], [1 => 3, 'gender' => 'female']);

        self::assertSame([1 => 3, 2 => 0, 'gender' => 'female'], $answers);
    }
}
