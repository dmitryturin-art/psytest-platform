<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\AnswerValidator;
use PsyTest\Modules\BeckAnxiety\BeckAnxietyModule;
use PsyTest\Modules\Lazarus\LazarusModule;
use PsyTest\Modules\Smil\SmilModule;

final class AnswerValidatorTest extends TestCase
{
    public function testRejectsInvalidAndIncompleteSmilAnswers(): void
    {
        $module = new SmilModule();
        $answers = [];
        foreach ($module->getQuestions() as $question) {
            $answers[$question['id']] = 1;
        }
        $answers['gender'] = 'female';
        // С 26.08 СМИЛ требует возраст: он нужен для клинического прочтения
        // профиля и не участвует в подсчёте (AgeCollectionContractTest).
        $answers['age'] = 39;

        self::assertSame([], AnswerValidator::validate($module, $answers, true));
        $answers[1] = 3;
        self::assertSame(['invalid_answer'], AnswerValidator::validate($module, $answers, true));
        unset($answers[1]);
        self::assertContains('incomplete_answers', AnswerValidator::validate($module, $answers, true));
    }

    public function testAcceptsOnlyDeclaredOptionValues(): void
    {
        $module = new BeckAnxietyModule();
        $answers = [];
        foreach ($module->getQuestions() as $question) {
            $answers[$question['id']] = $question['options'][0]['value'];
        }

        self::assertSame([], AnswerValidator::validate($module, $answers, true));
        $answers[1] = 99;
        self::assertSame(['invalid_answer'], AnswerValidator::validate($module, $answers, false));
    }

    public function testRequiresBothLazarusRatingsPerQuestion(): void
    {
        $module = new LazarusModule();
        $answers = [];
        foreach ($module->getQuestions() as $question) {
            $answers[$question['id'] . '_self'] = 7;
            $answers[$question['id'] . '_partner'] = 6;
        }

        self::assertSame([], AnswerValidator::validate($module, $answers, true));
        $answers['1_partner'] = 11;
        self::assertSame(['invalid_answer'], AnswerValidator::validate($module, $answers, false));
        unset($answers['1_partner']);
        self::assertContains('incomplete_answers', AnswerValidator::validate($module, $answers, true));
    }
}
