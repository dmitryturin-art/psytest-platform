<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\AnswerValidator;
use PsyTest\Modules\BeckAnxiety\BeckAnxietyModule;
use PsyTest\Modules\BeckDepression\BeckDepressionModule;
use PsyTest\Modules\Hads\HadsModule;
use PsyTest\Modules\Lazarus\LazarusModule;
use PsyTest\Modules\Smil\SmilModule;
use PsyTest\Modules\TestModuleInterface;

/**
 * Contract tests for the declarative answer schema (Module API v2, WP3).
 */
final class AnswerSchemaContractTest extends TestCase
{
    public static function moduleProvider(): array
    {
        return [
            'smil' => [SmilModule::class],
            'lazarus' => [LazarusModule::class],
            'beck-anxiety' => [BeckAnxietyModule::class],
            'beck-depression' => [BeckDepressionModule::class],
            'hads' => [HadsModule::class],
        ];
    }

    #[DataProvider('moduleProvider')]
    public function testSchemaShapeIsValid(string $moduleClass): void
    {
        $module = new $moduleClass();
        $schema = $module->getAnswerSchema();

        self::assertContains($schema['answer_type'] ?? null, ['ternary', 'scale10', 'options']);
        self::assertContains($schema['key_template'] ?? null, ['plain', 'dual']);
        self::assertIsBool($schema['requires_gender'] ?? null);
        self::assertIsArray($schema['extra_keys'] ?? null);
    }

    #[DataProvider('moduleProvider')]
    public function testSchemaCoherence(string $moduleClass): void
    {
        $module = new $moduleClass();
        $schema = $module->getAnswerSchema();

        self::assertSame(
            $schema['answer_type'] === 'scale10',
            $schema['key_template'] === 'dual',
            'Only scale10 (Lazarus) uses dual keys.'
        );
        self::assertFalse(
            $schema['requires_gender'] && $schema['answer_type'] !== 'ternary',
            'Gender requirement is a ternary (SMIL) concern.'
        );
    }

    #[DataProvider('moduleProvider')]
    public function testValidAnswersPassIncompleteValidation(string $moduleClass): void
    {
        $module = new $moduleClass();
        $schema = $module->getAnswerSchema();

        $valid = self::sampleValidAnswers($module);
        $errors = AnswerValidator::validate($module, $valid, false);

        self::assertSame([], $errors, "{$moduleClass} rejected its own sample answers.");
    }

    #[DataProvider('moduleProvider')]
    public function testOutOfRangeAnswersAreRejected(string $moduleClass): void
    {
        $module = new $moduleClass();
        $schema = $module->getAnswerSchema();
        $valid = self::sampleValidAnswers($module);
        $badValue = $schema['answer_type'] === 'options' ? '9' : '99';

        $firstKey = array_key_first($valid);
        $bad = $valid;
        $bad[$firstKey] = $badValue;

        self::assertContains('invalid_answer', AnswerValidator::validate($module, $bad, false));
    }

    public function testLazarusRejectsPlainKeysAndSmilRejectsMissingGender(): void
    {
        $lazarus = new LazarusModule();
        self::assertContains(
            'invalid_answer',
            AnswerValidator::validate($lazarus, ['1' => '5'], false),
            'Lazarus requires dual keys (qid_self/qid_partner).'
        );

        $smil = new SmilModule();
        self::assertContains(
            'invalid_gender',
            AnswerValidator::validate($smil, ['1' => '0'], true),
            'SMIL requires a valid gender for scoring.'
        );
    }

    /**
     * @return array<int|string, int|string>
     */
    private static function sampleValidAnswers(TestModuleInterface $module): array
    {
        $schema = $module->getAnswerSchema();
        $answers = [];
        foreach ($module->getQuestions() as $i => $q) {
            $id = $q['id'];
            if ($schema['key_template'] === 'dual') {
                $answers[$id . '_self'] = ($i % 10) + 1;
                $answers[$id . '_partner'] = 10 - ($i % 10);
            } elseif ($schema['answer_type'] === 'ternary') {
                $answers[$id] = (string) ($i % 3);
            } else {
                $answers[$id] = (string) ($i % 4);
            }
        }
        if (($schema['requires_gender'] ?? false) || $schema['answer_type'] === 'scale10') {
            $answers['gender'] = 'male';
        }

        return $answers;
    }
}
