<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\AnswerNormalizer;
use PsyTest\Core\AnswerValidator;
use PsyTest\Core\InvitedCasePresenter;
use PsyTest\Modules\AdhdAdult\AdhdAdultModule;
use PsyTest\Modules\ResultSection;

final class AdhdAdultModuleTest extends TestCase
{
    /**
     * Базовый набор: все признаки «иногда» (2), детство «да», две сферы,
     * «заметно» мешают. Сам по себе даёт «признаков нет» (счёт 0 и 0).
     *
     * @param array<int, int|list<string>> $override
     *
     * @return array<int, int|list<string>>
     */
    private static function answers(array $override = []): array
    {
        $answers = array_fill(1, 18, 2) + [19 => 1, 20 => ['work', 'home'], 21 => 2];

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
        self::assertSame(21, $metadata['question_count']);
        self::assertSame(5, $metadata['estimated_time']);
        self::assertCount(21, $module->getQuestions());
        self::assertStringContainsString('21 вопрос, около 5 минут', $metadata['description']);
        self::assertSame(['gender' => false, 'age' => false], array_intersect_key($module->getDemographicsRequirements(), ['gender' => 1, 'age' => 1]));
        self::assertStringNotContainsString('ASRS', $metadata['author'] . $metadata['description']);
    }

    public function testSampleAnswersPassValidation(): void
    {
        $module = new AdhdAdultModule();
        self::assertSame([], AnswerValidator::validate($module, self::answers(), true));
        self::assertSame([], AnswerValidator::validate($module, self::answers([20 => ['none']]), true));
        self::assertSame(['invalid_answer'], AnswerValidator::validate($module, [1 => '5'], false));
        self::assertSame(['invalid_answer'], AnswerValidator::validate($module, [21 => '4'], false));
    }

    public function testLifeAreasAreOneMultiChoiceQuestionWithAnExclusiveNowhere(): void
    {
        $module = new AdhdAdultModule();
        $question = $module->getQuestions()[19];

        self::assertSame(20, $question['id']);
        self::assertSame('multi', $question['type']);
        self::assertSame('Где эти трудности проявляются сейчас?', $question['text']);
        self::assertSame(
            ['Работа или учёба', 'Дом и быт', 'Отношения с людьми', 'Деньги и документы', 'Другое', 'Нигде не проявляются'],
            array_column($question['options'], 'text'),
        );
        self::assertSame(['work', 'home', 'relations', 'money', 'other', 'none'], array_column($question['options'], 'value'));
        self::assertSame([5 => true], array_filter(array_map(static fn (array $o): bool => $o['exclusive'] ?? false, $question['options'])));
        self::assertSame('Насколько эти трудности мешают вам жить?', $module->getQuestions()[20]['text']);

        // Сервер отклоняет то, чего экран не допускает.
        self::assertSame(['invalid_answer'], AnswerValidator::validate($module, self::answers([20 => ['work', 'none']]), true));
        self::assertSame(['invalid_answer'], AnswerValidator::validate($module, self::answers([20 => ['garden']]), true));
        self::assertSame(['invalid_answer'], AnswerValidator::validate($module, self::answers([20 => 1]), true));
        self::assertSame(['incomplete_answers'], AnswerValidator::validate($module, self::answers([20 => []]), true));
        self::assertSame(
            [20 => ['work', 'other']],
            AnswerNormalizer::fromForm($module, ['20' => ['other', 'work', 'other']]),
        );
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
        self::assertSame('none', $this->conclusion([19 => 0, 20 => ['none'], 21 => 0]));
        self::assertSame('none', $this->conclusion([19 => 1, 20 => ['work', 'home', 'relations', 'money', 'other'], 21 => 3]));
    }

    #[DataProvider('failedClarificationProvider')]
    public function testEachClarificationAloneMovesExpressedToPartial(array $override): void
    {
        self::assertSame('partial', $this->conclusion(self::often(1, 5) + $override));
    }

    /** @return array<string, array{array<int, int|list<string>>}> */
    public static function failedClarificationProvider(): array
    {
        return [
            'childhood no' => [[19 => 0]],
            'childhood do not remember' => [[19 => 2]],
            'one sphere only' => [[20 => ['work']]],
            'only other' => [[20 => ['other']]],
            'nowhere' => [[20 => ['none']]],
            'interference small' => [[21 => 1]],
            'interference none' => [[21 => 0]],
        ];
    }

    public function testBoundaryInterferenceValuesAreEnough(): void
    {
        self::assertSame('expressed', $this->conclusion(self::often(1, 5) + [21 => 2]));
        self::assertSame('expressed', $this->conclusion(self::often(1, 5) + [21 => 3]));
    }

    public function testOtherCountsAsASphere(): void
    {
        $results = (new AdhdAdultModule())->calculateResults(self::answers(self::often(1, 5) + [20 => ['relations', 'other']]));

        self::assertSame(2, $results['spheres_count']);
        self::assertSame(['relations', 'other'], $results['spheres']);
        self::assertSame('expressed', $results['conclusion']);
    }

    public function testSpheresBoundaryIsTwo(): void
    {
        $module = new AdhdAdultModule();
        $one = $module->calculateResults(self::answers(self::often(1, 5) + [20 => ['money']]));
        $two = $module->calculateResults(self::answers(self::often(1, 5) + [20 => ['money', 'other']]));
        $all = $module->calculateResults(self::answers(self::often(1, 5) + [20 => ['work', 'home', 'relations', 'money', 'other']]));

        self::assertSame([1, false, 'partial'], [$one['spheres_count'], $one['conditions']['spheres'], $one['conclusion']]);
        self::assertSame([2, true, 'expressed'], [$two['spheres_count'], $two['conditions']['spheres'], $two['conclusion']]);
        self::assertSame([5, true, 'expressed'], [$all['spheres_count'], $all['conditions']['spheres'], $all['conclusion']]);
    }

    public function testSpheresAreReportedInQuestionOrderWhateverTheStoredOrder(): void
    {
        $results = (new AdhdAdultModule())->calculateResults(self::answers([20 => ['other', 'work', 'work']]));

        self::assertSame(['work', 'other'], $results['spheres']);
        self::assertSame(2, $results['spheres_count']);
    }

    public function testNowhereIsNotASphere(): void
    {
        // «Нигде не проявляются» — исключающий вариант, сферой не считается.
        $module = new AdhdAdultModule();
        $results = $module->calculateResults(self::answers(self::often(1, 5) + [20 => ['none']]));

        self::assertSame(0, $results['spheres_count']);
        self::assertSame([], $results['spheres']);
        self::assertSame('partial', $results['conclusion']);
        self::assertSame(21, $results['answered_count'], 'Выбор «нигде» — это ответ на вопрос.');
        self::assertSame('Нигде не проявляются', $module->buildSections($results)[2]->data['indices'][1]['value']);
    }

    public function testChosenSpheresAreShownOnTheResultPage(): void
    {
        $module = new AdhdAdultModule();
        $results = $module->calculateResults(self::answers([20 => ['home', 'money', 'other']]));

        self::assertSame('Дом и быт, деньги и документы, другое', $module->buildSections($results)[2]->data['indices'][1]['value']);
    }

    public function testCaseQuestionnaireAndAiItemsShowChosenSpheresAsLabels(): void
    {
        $module = new AdhdAdultModule();
        $answers = self::answers([20 => ['work', 'relations']]);

        $rows = (new InvitedCasePresenter())->answers($module, $answers);
        self::assertCount(21, $rows);
        self::assertSame('Где эти трудности проявляются сейчас?', $rows[19]['question']);
        self::assertSame('Работа или учёба, Отношения с людьми', $rows[19]['answer']);
        self::assertNull($rows[19]['score']);
        self::assertSame('Заметно', $rows[20]['answer']);

        $items = $module->aiReportItems($answers);
        self::assertSame('Работа или учёба, Отношения с людьми', $items[19]['answer_label']);
        self::assertSame('work,relations', $items[19]['value']);
    }

    public function testUnansweredItemsAreNotCountedAndNothingCrashes(): void
    {
        $module = new AdhdAdultModule();
        $results = $module->calculateResults([]);

        self::assertSame(0, $results['inattention_count']);
        self::assertSame('none', $results['conclusion']);
        self::assertSame(0, $results['answered_count']);
        self::assertSame(21, $results['total_questions']);
        self::assertSame(21, $module->calculateResults(self::answers())['answered_count']);
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

    /** @return array<string, array{array<int, int|list<string>>, string, string}> */
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
        self::assertSame(
            ['childhood_onset' => true, 'several_spheres' => true, 'noticeable_interference' => true],
            $context['clarifications'],
        );
        // Сами ответы на уточняющие вопросы без галочки «ответы по пунктам» наружу не уходят.
        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach (['работа или учёба', 'дом и быт', 'Не помню', '"spheres"', '"childhood"', '"interference"'] as $answerLabel) {
            self::assertStringNotContainsStringIgnoringCase($answerLabel, $encoded);
        }
        self::assertNull($module->aiReportContext($results, 'pair'));
    }

    public function testJsonTrueCountsAsValueOneLikeTheValidator(): void
    {
        $module = new AdhdAdultModule();
        $results = $module->calculateResults(self::answers([19 => true]));

        self::assertTrue($results['conditions']['childhood']);
    }
}
