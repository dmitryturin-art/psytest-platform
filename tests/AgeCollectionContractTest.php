<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\AnswerValidator;
use PsyTest\Core\ModuleLoader;
use PsyTest\Modules\Smil\SmilModule;

/**
 * Сбор возраста (решение владельца 26.08).
 *
 * Возраст нужен для клинического прочтения профиля СМИЛ и станет различителем,
 * когда появится подростковая форма методики. Главное ограничение: он **не
 * участвует в подсчёте** — проверенное ядро Собчик остаётся неизменным.
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

    public function testSmilAsksForAgeAndDeclaresItsRange(): void
    {
        $schema = $this->smil()->getAnswerSchema();

        self::assertTrue($schema['requires_age']);
        self::assertSame(16, $schema['age_range']['min'], 'Нижняя граница взрослой формы.');
        self::assertSame(100, $schema['age_range']['max']);
        self::assertContains('age', $schema['extra_keys']);

        $metadata = $this->smil()->getMetadata();
        self::assertTrue($metadata['requires_demographics']['age'], 'Форма прохождения обязана показать поле возраста.');
        self::assertSame(16, $metadata['requires_demographics']['min_age'], 'Форма и валидатор должны знать одну границу.');
        self::assertSame(100, $metadata['requires_demographics']['max_age']);
    }

    public function testServerRejectsMissingAndImplausibleAge(): void
    {
        // До этого пакета возраст числился «лишним ключом» и не проверялся вовсе:
        // на сервер можно было прислать что угодно или не прислать ничего.
        $module = $this->smil();

        self::assertContains('invalid_age', AnswerValidator::validate($module, $this->answers(null), true));
        self::assertContains('invalid_age', AnswerValidator::validate($module, $this->answers(15), true));
        self::assertContains('invalid_age', AnswerValidator::validate($module, $this->answers(101), true));
        self::assertContains('invalid_age', AnswerValidator::validate($module, $this->answers('тридцать'), true));
        self::assertContains('invalid_age', AnswerValidator::validate($module, $this->answers(-5), true));
        self::assertContains('invalid_age', AnswerValidator::validate($module, $this->answers(39.5), true));
    }

    public function testServerAcceptsAgeInsideTheRangeAsNumberOrDigits(): void
    {
        $module = $this->smil();

        // Из HTML-формы возраст приходит строкой.
        self::assertNotContains('invalid_age', AnswerValidator::validate($module, $this->answers(16), true));
        self::assertNotContains('invalid_age', AnswerValidator::validate($module, $this->answers('39'), true));
        self::assertNotContains('invalid_age', AnswerValidator::validate($module, $this->answers(100), true));
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

    public function testOtherModulesStillDoNotAskForAge(): void
    {
        $loader = (new ModuleLoader(null, null))->discover();

        foreach (array_keys($loader->getAllModules()) as $slug) {
            if ($slug === 'smil') {
                continue;
            }

            $schema = $loader->getModule($slug)->getAnswerSchema();
            self::assertFalse(
                $schema['requires_age'] ?? false,
                "Методика {$slug} не объявляла обязательный возраст — лишний вопрос респонденту не задаётся.",
            );
        }
    }
}
