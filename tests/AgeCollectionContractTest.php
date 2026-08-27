<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\AnswerValidator;
use PsyTest\Core\ModuleLoader;
use PsyTest\Modules\Smil\SmilModule;

/**
 * Возраст при прохождении не спрашивается (D-040).
 *
 * Форму методики задаёт сам выбор респондента, а возраст и прочие сведения о себе
 * человек или специалист сообщает при заказе расширенного разбора. Общий слой при
 * этом обязан уметь проверить возраст, если методика когда-нибудь его потребует:
 * форма прохождения умеет показывать это поле, и без серверной проверки оно было бы
 * принято без единого ограничения.
 */
final class AgeCollectionContractTest extends TestCase
{
    private function smil(): SmilModule
    {
        return new SmilModule();
    }

    /** @return array<int|string, mixed> */
    private function answers(mixed $age = 39): array
    {
        $answers = ['gender' => 'female'];
        foreach ($this->smil()->getQuestions() as $i => $q) {
            $answers[$q['id']] = $i % 2;
        }
        if ($age !== null) {
            $answers['age'] = $age;
        }

        return $answers;
    }

    public function testNoModuleAsksForAgeWhileTakingTheTest(): void
    {
        // D-040: возраст не влияет ни на подсчёт, ни на выбор формы методики,
        // поэтому лишний вопрос респонденту не задаётся.
        $loader = (new ModuleLoader(null, null))->discover();

        foreach (array_keys($loader->getAllModules()) as $slug) {
            $module = $loader->getModule($slug);

            self::assertFalse($module->getAnswerSchema()['requires_age'] ?? false, "Методика {$slug} требует возраст.");
            self::assertFalse(
                $module->getMetadata()['requires_demographics']['age'] ?? false,
                "Форма прохождения {$slug} показывает поле возраста.",
            );
        }
    }

    public function testValidatorStillGuardsAgeForAnyModuleThatEverDeclaresIt(): void
    {
        // Шаблон прохождения умеет показывать поле возраста по метаданным.
        // Если методика его включит, общий слой обязан проверить значение —
        // до этой правки такое поле принималось вообще без ограничений.
        $module = new class () extends \PsyTest\Modules\BaseTestModule {
            public function getQuestions(): array
            {
                return [['id' => 1, 'text' => 'q', 'options' => [['value' => 0, 'text' => 'a']]]];
            }

            public function getAnswerSchema(): array
            {
                return array_merge(parent::getAnswerSchema(), [
                    'requires_age' => true,
                    'age_range' => ['min' => 13, 'max' => 15],
                ]);
            }

            public function calculateResults(array $answers): array
            {
                return [];
            }

            public function generateInterpretation(array $scores): array
            {
                return ['summary' => '', 'recommendations' => []];
            }

            public function buildSections(array $results): array
            {
                return [];
            }
        };

        self::assertContains('invalid_age', AnswerValidator::validate($module, [1 => 0], true));
        self::assertContains('invalid_age', AnswerValidator::validate($module, [1 => 0, 'age' => 12], true));
        self::assertContains('invalid_age', AnswerValidator::validate($module, [1 => 0, 'age' => 16], true));
        self::assertContains('invalid_age', AnswerValidator::validate($module, [1 => 0, 'age' => 'четырнадцать'], true));
        self::assertNotContains('invalid_age', AnswerValidator::validate($module, [1 => 0, 'age' => '14'], true));
    }

    public function testAgeDoesNotChangeASingleScore(): void
    {
        // Неподвижное ограничение: проверенное scoring core СМИЛ не меняется.
        $module = $this->smil();

        $young = $module->calculateResults($this->answers(16));
        $old = $module->calculateResults($this->answers(100));
        $without = $module->calculateResults($this->answers(null));

        foreach (['raw_scores', 't_scores', 'validity', 'profile', 'indices', 'additional_scores'] as $key) {
            self::assertSame($young[$key], $old[$key], "Возраст изменил «{$key}» — scoring обязан быть неизменным.");
            self::assertSame($young[$key], $without[$key], "Отсутствие возраста изменило «{$key}».");
        }
    }

}
