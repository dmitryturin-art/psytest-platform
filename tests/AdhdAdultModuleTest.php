<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\AnswerValidator;
use PsyTest\Modules\AdhdAdult\AdhdAdultModule;
use PsyTest\Modules\ResultSection;

final class AdhdAdultModuleTest extends TestCase
{
    /**
     * Базовый набор: все признаки «иногда» (2), детство «да», две сферы,
     * «заметно» мешают. Сам по себе даёт «признаков нет» (счёт 0 и 0).
     *
     * @param array<int, int> $override
     *
     * @return array<int, int>
     */
    private static function answers(array $override = []): array
    {
        $answers = array_fill(1, 18, 2) + [19 => 1, 20 => 1, 21 => 1, 22 => 0, 23 => 0, 24 => 0, 25 => 2];

        return array_replace($answers, $override);
    }

    /** @return array<int, int> первые $count пунктов диапазона «часто» (3). */
    private static function often(int $from, int $count, int $value = 3): array
    {
        return array_fill_keys(range($from, $from + $count - 1), $value);
    }

    private function conclusion(array $override): string
    {
        return (new AdhdAdultModule())->calculateResults(self::answers($override))['conclusion'];
    }

    public function testMetadataAndQuestions(): void
    {
        $module = new AdhdAdultModule();
        $metadata = $module->getMetadata();

        self::assertSame('adhd-adult', $metadata['slug']);
        self::assertSame('Тест на СДВГ у взрослых', $metadata['name']);
        self::assertSame(25, $metadata['question_count']);
        self::assertSame(5, $metadata['estimated_time']);
        self::assertCount(25, $module->getQuestions());
        self::assertSame(['gender' => false, 'age' => false], array_intersect_key($module->getDemographicsRequirements(), ['gender' => 1, 'age' => 1]));
        self::assertStringNotContainsString('ASRS', $metadata['author'] . $metadata['description']);
    }

    public function testSampleAnswersPassValidation(): void
    {
        $module = new AdhdAdultModule();
        self::assertSame([], AnswerValidator::validate($module, array_map('strval', self::answers()), true));
        self::assertSame(['invalid_answer'], AnswerValidator::validate($module, [1 => '5'], false));
        self::assertSame(['invalid_answer'], AnswerValidator::validate($module, [25 => '4'], false));
    }

    #[DataProvider('boundaryProvider')]
    public function testGroupBoundary(int $from, string $key, int $count, bool $significant): void
    {
        $results = (new AdhdAdultModule())->calculateResults(self::answers(self::often($from, $count)));

        self::assertSame($count, $results[$key]);
        self::assertSame($significant, $results[$key === 'inattention_count' ? 'inattention_significant' : 'hyperactivity_significant']);
    }

    /** @return array<string, array{int, string, int, bool}> */
    public static function boundaryProvider(): array
    {
        return [
            'inattention 4' => [1, 'inattention_count', 4, false],
            'inattention 5' => [1, 'inattention_count', 5, true],
            'hyperactivity 4' => [10, 'hyperactivity_count', 4, false],
            'hyperactivity 5' => [10, 'hyperactivity_count', 5, true],
        ];
    }

    public function testSometimesIsNotCountedButOftenAndVeryOftenAre(): void
    {
        $module = new AdhdAdultModule();

        self::assertSame(0, $module->calculateResults(self::answers(self::often(1, 9, 2)))['inattention_count']);
        self::assertSame(9, $module->calculateResults(self::answers(self::often(1, 9, 3)))['inattention_count']);
        self::assertSame(9, $module->calculateResults(self::answers(self::often(1, 9, 4)))['inattention_count']);
        // Один пункт «иногда» не добирает до порога.
        self::assertSame(4, $module->calculateResults(self::answers(self::often(1, 4, 4) + [5 => 2]))['inattention_count']);
    }

    public function testThreeConclusions(): void
    {
        self::assertSame('none', $this->conclusion(self::often(1, 4) + self::often(10, 4)));
        self::assertSame('expressed', $this->conclusion(self::often(1, 5)));
        self::assertSame('expressed', $this->conclusion(self::often(10, 5)));
        self::assertSame('partial', $this->conclusion(self::often(1, 5) + [19 => 0]));
    }

    public function testNoneIgnoresClarifications(): void
    {
        self::assertSame('none', $this->conclusion([19 => 0, 20 => 0, 21 => 0, 25 => 0]));
    }

    #[DataProvider('failedClarificationProvider')]
    public function testEachClarificationAloneMovesExpressedToPartial(array $override): void
    {
        self::assertSame('partial', $this->conclusion(self::often(1, 5) + $override));
    }

    /** @return array<string, array{array<int, int>}> */
    public static function failedClarificationProvider(): array
    {
        return [
            'childhood no' => [[19 => 0]],
            'childhood do not remember' => [[19 => 2]],
            'one sphere only' => [[21 => 0]],
            'no spheres' => [[20 => 0, 21 => 0]],
            'interference small' => [[25 => 1]],
            'interference none' => [[25 => 0]],
        ];
    }

    public function testBoundaryInterferenceValuesAreEnough(): void
    {
        self::assertSame('expressed', $this->conclusion(self::often(1, 5) + [25 => 2]));
        self::assertSame('expressed', $this->conclusion(self::often(1, 5) + [25 => 3]));
    }

    public function testOtherCountsAsASphere(): void
    {
        $results = (new AdhdAdultModule())->calculateResults(self::answers(self::often(1, 5) + [20 => 0, 21 => 0, 22 => 1, 23 => 0, 24 => 1]));

        self::assertSame(2, $results['spheres_count']);
        self::assertSame([22, 24], $results['spheres']);
        self::assertSame('expressed', $results['conclusion']);
    }

    public function testNowhereIsNotASphere(): void
    {
        // «Нигде не проявляются» = ни одной из пяти сфер: счёт сфер нулевой.
        $results = (new AdhdAdultModule())->calculateResults(self::answers(self::often(1, 5) + [20 => 0, 21 => 0, 22 => 0, 23 => 0, 24 => 0]));

        self::assertSame(0, $results['spheres_count']);
        self::assertSame('partial', $results['conclusion']);
    }

    public function testUnansweredItemsAreNotCountedAndNothingCrashes(): void
    {
        $results = (new AdhdAdultModule())->calculateResults([]);

        self::assertSame(0, $results['inattention_count']);
        self::assertSame('none', $results['conclusion']);
        self::assertSame(0, $results['answered_count']);
    }

    public function testStringAnswersFromSessionAreAccepted(): void
    {
        $answers = array_map('strval', self::often(1, 5, 4));
        $results = (new AdhdAdultModule())->calculateResults($answers);

        self::assertSame(5, $results['inattention_count']);
    }

    #[DataProvider('conclusionTextProvider')]
    public function testResultSectionsPerConclusion(array $override, string $title, string $textStart): void
    {
        $module = new AdhdAdultModule();
        $results = $module->calculateResults(self::answers($override));
        $sections = $module->buildSections($results);

        self::assertContainsOnlyInstancesOf(ResultSection::class, $sections);
        self::assertSame($title, $sections[0]->title);
        self::assertStringStartsWith($textStart, $sections[0]->data['text']);
        self::assertStringContainsString('Авторский опросник PsyTest', $sections[3]->data['text']);
        self::assertStringContainsString('Результат — не диагноз', $sections[3]->data['text']);
        self::assertSame('Невнимательность', $sections[1]->data['indices'][0]['name']);
        self::assertMatchesRegularExpression('/^\d из 9$/', $sections[1]->data['indices'][1]['value']);
    }

    /** @return array<string, array{array<int, int>, string, string}> */
    public static function conclusionTextProvider(): array
    {
        return [
            'expressed' => [self::often(1, 5), 'Признаки выражены', 'По вашим ответам набирается число признаков'],
            'partial' => [self::often(1, 5) + [19 => 2], 'Признаки есть, картина неполная', 'Трудности заметны'],
            'none' => [[], 'Выраженных признаков нет', 'По вашим ответам число признаков ниже'],
        ];
    }

    public function testAiContextIsStructuredAndAnonymous(): void
    {
        $module = new AdhdAdultModule();
        $results = $module->calculateResults(self::answers(self::often(1, 5)));
        $context = $module->aiReportContext($results, 'individual');

        self::assertNotNull($context);
        self::assertSame('adhd-adult', $context['test']);
        self::assertSame(5, $context['subscales'][0]['score']);
        self::assertSame(9, $context['subscales'][0]['max']);
        self::assertSame('expressed', $context['level']);
        self::assertSame(['работа или учёба', 'дом и быт'], $context['clarifications']['spheres']);
        self::assertNull($module->aiReportContext($results, 'pair'));
    }
}
