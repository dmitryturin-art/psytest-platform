<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Ai\AiReportContextBuilder;
use PsyTest\Core\AnswerMerger;
use PsyTest\Core\AnswerNormalizer;
use PsyTest\Core\AnswerValidator;
use PsyTest\Core\CaseExportDocx;
use PsyTest\Core\InvitedCasePresenter;
use PsyTest\Core\ModuleLoader;
use PsyTest\Core\TemplateFunctions;
use PsyTest\Core\TestInviteService;
use PsyTest\Modules\BeckAnxiety\BeckAnxietyModule;
use PsyTest\Modules\Lazarus\LazarusModule;
use PsyTest\Modules\Smil\SmilModule;
use PsyTest\Tests\Fixtures\DemoMulti\DemoMultiChoiceModule;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require_once __DIR__ . '/fixtures/demo-multi-choice/DemoMultiChoiceModule.php';

/**
 * Вопрос «несколько вариантов» (`"type": "multi"`): ответ — список значений
 * выбранных вариантов. Проверяется сервер как источник истины (приведение,
 * отказ, слияние), показ ответа (анкета кейса, выгрузки, пункты для ИИ) и то,
 * что остальные типы вопросов ведут себя по-прежнему.
 */
final class MultiChoiceQuestionTest extends TestCase
{
    use DocxInspection;

    private DemoMultiChoiceModule $module;

    protected function setUp(): void
    {
        $this->module = new DemoMultiChoiceModule();
    }

    // ------------------------------------------------------------- валидатор

    public function testAcceptsAListOfDeclaredOptions(): void
    {
        self::assertSame([], $this->validate([1 => 2, 2 => ['work', 'home'], 3 => 1]));
        self::assertSame([], $this->validate([1 => 2, 2 => ['none'], 3 => 1]));
        self::assertSame([], $this->validate([1 => 2, 2 => ['work', 'home', 'leisure'], 3 => 1]));
    }

    /** @return array<string, array{mixed}> */
    public static function rejectedMultiValues(): array
    {
        return [
            'скаляр вместо списка' => ['work'],
            'число' => [1],
            'признак да/нет' => [true],
            'неизвестный вариант' => [['work', 'office']],
            'исключающий вместе с другим' => [['work', 'none']],
            'исключающий вместе с остальными' => [['work', 'home', 'leisure', 'none']],
            'вложенный массив' => [[['work']]],
            'вложенный массив рядом с допустимым' => [['work', ['home']]],
            'ассоциативный массив' => [['a' => 'work']],
            'не строка и не число' => [[null]],
            'дробное число' => [[1.5]],
            'слишком длинный список' => [array_fill(0, AnswerNormalizer::MAX_MULTI_ITEMS + 1, 'work')],
        ];
    }

    #[DataProvider('rejectedMultiValues')]
    public function testRejectsEverythingThatIsNotAListOfDeclaredOptions(mixed $value): void
    {
        self::assertSame(['invalid_answer'], $this->validate([1 => 2, 2 => $value, 3 => 1]));
        self::assertSame(['invalid_answer'], AnswerValidator::validatePartial($this->module, [2 => $value]));
        // Приведение к хранимому виду не превращает недопустимое в допустимое.
        self::assertSame(
            ['invalid_answer'],
            AnswerValidator::validatePartial($this->module, AnswerNormalizer::fromJson($this->module, [2 => $value])),
        );
    }

    public function testEmptyChoiceIsIncompleteOnSubmitAndAllowedOnlyAsAClearedDraft(): void
    {
        self::assertSame(['incomplete_answers'], $this->validate([1 => 2, 2 => [], 3 => 1]));
        self::assertSame(['incomplete_answers'], $this->validate([1 => 2, 3 => 1]));
        self::assertSame([], AnswerValidator::validatePartial($this->module, [2 => []]));
    }

    public function testAnArrayOnAnOrdinaryQuestionIsRejectedNotCoerced(): void
    {
        self::assertSame(['invalid_answer'], $this->validate([1 => [2], 2 => ['work'], 3 => 1]));
        self::assertSame(['invalid_answer'], AnswerValidator::validatePartial($this->module, [1 => []]));
        self::assertSame(['invalid_answer'], AnswerValidator::validatePartial(new BeckAnxietyModule(), [1 => [0]]));
        self::assertSame(['invalid_answer'], AnswerValidator::validatePartial(new SmilModule(), [1 => [1]]));
        self::assertSame(['invalid_answer'], AnswerValidator::validatePartial(new LazarusModule(), ['1_self' => [7]]));
    }

    // ----------------------------------------------------------- приведение

    public function testFormAnswersBecomeAListWithoutDuplicatesInOptionOrder(): void
    {
        $normalized = AnswerNormalizer::fromForm($this->module, [
            '1' => '2',
            '2' => ['leisure', 'work', 'leisure', 'work'],
            '3' => '1',
        ]);

        self::assertSame([1 => 2, 2 => ['work', 'leisure'], 3 => 1], $normalized);
        self::assertSame([], AnswerValidator::validate($this->module, $normalized, true));
    }

    public function testMultiValuesKeepTheTypeDeclaredByTheOption(): void
    {
        $module = new class () extends \PsyTest\Modules\BaseTestModule {
            protected function initialize(): void
            {
                $this->metadata = ['slug' => 'inline', 'name' => 'inline', 'question_count' => 1, 'estimated_time' => 1];
                $this->questions = [[
                    'id' => 1,
                    'type' => 'multi',
                    'text' => 'Вопрос',
                    'options' => [['value' => 1, 'text' => 'А'], ['value' => 2, 'text' => 'Б'], ['value' => 3, 'text' => 'В']],
                ]];
            }

            public function calculateResults(array $answers): array
            {
                return [];
            }

            public function generateInterpretation(array $scores): array
            {
                return [];
            }
        };

        self::assertSame([1 => [1, 3]], AnswerNormalizer::fromForm($module, ['1' => ['3', '1', '3']]));
        self::assertSame([1 => [1, 3]], AnswerNormalizer::fromJson($module, [1 => [3, '1']]));
    }

    public function testJsonDraftIsBroughtToTheSameStoredForm(): void
    {
        self::assertSame(
            ['1' => '2', '2' => ['home', 'leisure']],
            AnswerNormalizer::fromJson($this->module, ['1' => '2', '2' => ['leisure', 'home', 'home']]),
        );
        self::assertSame(['2' => []], AnswerNormalizer::fromJson($this->module, ['2' => []]));
    }

    public function testOrdinaryFormAnswersAreNormalizedExactlyAsBefore(): void
    {
        $post = ['1' => '0', '2' => '3', '7' => 'true', '8' => 'false', '9' => '', '1_self' => '10'];

        // Прежний код обеих отправок (submit и парной), перенесённый в нормализатор.
        $legacy = [];
        foreach ($post as $questionId => $answer) {
            $legacy[$questionId] = is_numeric($answer) ? (int) $answer : ($answer === 'true');
        }

        foreach ([new BeckAnxietyModule(), new SmilModule(), new LazarusModule(), $this->module] as $module) {
            $input = $module === $this->module ? array_diff_key($post, ['2' => true]) : $post;
            $expected = $module === $this->module ? array_diff_key($legacy, ['2' => true]) : $legacy;
            self::assertSame($expected, AnswerNormalizer::fromForm($module, $input));
        }
        self::assertSame([], AnswerNormalizer::fromForm($this->module, 'not-an-array'));
    }

    public function testBothSubmitPathsAndTheDraftSaveUseTheSharedNormalizer(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/controllers/TestController.php');

        self::assertSame(2, substr_count($controller, "AnswerNormalizer::fromForm(\$module, \$_POST['answers'] ?? [])"));
        self::assertSame(1, substr_count($controller, 'AnswerNormalizer::fromJson('));
        self::assertStringNotContainsString('is_numeric($answer)', $controller);
    }

    // -------------------------------------------------------------- слияние

    public function testResumeMergeReplacesTheWholeChoiceAndAnEmptiedChoiceForgetsIt(): void
    {
        $saved = [1 => 2, 2 => ['work', 'home']];

        self::assertSame([1 => 2, 2 => ['none']], AnswerMerger::overlay($saved, [2 => ['none']]));
        self::assertSame([1 => 2], AnswerMerger::overlay($saved, [2 => []]));
        self::assertSame([1 => 0, 2 => ['work', 'home'], 3 => 1], AnswerMerger::overlay($saved, [1 => 0, 3 => 1]));
    }

    public function testAnsweredCountTreatsANonEmptyChoiceAsOneAnswer(): void
    {
        self::assertSame(2, TestInviteService::answeredCount([1 => 2, 2 => ['work', 'home']]));
        self::assertSame(1, TestInviteService::answeredCount([1 => 2, 2 => []]));
        self::assertSame(1, TestInviteService::answeredCount(['1_self' => 7, '1_partner' => 6]));
    }

    public function testTheModuleReadsTheChoiceAsAList(): void
    {
        self::assertSame(2, $this->module->calculateResults([1 => 2, 2 => ['work', 'home'], 3 => 1])['areas']);
        self::assertSame(0, $this->module->calculateResults([1 => 2, 2 => ['none'], 3 => 1])['areas']);
    }

    // ---------------------------------------------------------- показ ответа

    public function testCaseQuestionnaireShowsChosenLabelsInOptionOrder(): void
    {
        $rows = (new InvitedCasePresenter())->answers($this->module, ['1' => 2, '2' => ['leisure', 'work'], '3' => 1]);

        self::assertSame('Часто', $rows[0]['answer']);
        self::assertSame('2', $rows[0]['score']);
        self::assertSame('На работе, На отдыхе', $rows[1]['answer']);
        self::assertNull($rows[1]['score'], 'У списка вариантов нет балла.');
        self::assertSame('Немного', $rows[2]['answer']);

        $empty = (new InvitedCasePresenter())->answers($this->module, ['1' => 2]);
        self::assertSame('Нет ответа', $empty[1]['answer']);
        $exclusive = (new InvitedCasePresenter())->answers($this->module, [2 => ['none']]);
        self::assertSame('Нигде', $exclusive[1]['answer']);
    }

    public function testPdfPrintAndCasePageTemplatesPrintTheLabelsWithoutAScore(): void
    {
        $rows = (new InvitedCasePresenter())->answers($this->module, ['1' => 2, '2' => ['work', 'home'], '3' => 1]);
        $document = $this->document(['answers' => $rows]);
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/templates'), ['cache' => false]);
        TemplateFunctions::register($twig);

        $pdf = $twig->render('owner-case-export-pdf.twig', [
            'document' => $document,
            'pair_html' => '',
            'sections_html' => '',
        ]);
        self::assertStringContainsString('На работе, Дома', $pdf);
        self::assertStringNotContainsString('На работе, Дома ·', $pdf);
        self::assertStringContainsString('Часто · 2 балл.', $pdf);

        foreach (['owner-case-export.twig', 'owner-invited-case.twig'] as $template) {
            $source = (string) file_get_contents(dirname(__DIR__) . '/templates/' . $template);
            self::assertStringContainsString('{{ row.answer }}{% if row.score is not null %}', $source, $template);
        }
    }

    public function testWordExportPrintsTheLabels(): void
    {
        $rows = (new InvitedCasePresenter())->answers($this->module, ['1' => 2, '2' => ['work', 'home'], '3' => 1]);
        $docx = (new CaseExportDocx(static fn (string $template, array $data): string => ''))
            ->render($this->document(['answers' => $rows]));

        $text = $this->plainText($this->xmlPart($this->unzip($docx)['word/document.xml']));
        self::assertStringContainsString('Где усталость заметна сильнее всего?', $text);
        self::assertStringContainsString('На работе, Дома', $text);
    }

    public function testAiItemsCarryChosenLabelsAndValues(): void
    {
        $items = $this->module->aiReportItems(['1' => 2, '2' => ['leisure', 'work'], '3' => 1]);

        self::assertSame(
            ['number' => 2, 'text' => 'Где усталость заметна сильнее всего?', 'answer_label' => 'На работе, На отдыхе', 'value' => 'work,leisure'],
            $items[1],
        );
        self::assertSame(['number' => 1, 'text' => 'Как часто вы устаёте к вечеру?', 'answer_label' => 'Часто', 'value' => 2], $items[0]);

        $unanswered = $this->module->aiReportItems(['1' => 2]);
        self::assertSame('Нет ответа', $unanswered[1]['answer_label']);
        self::assertNull($unanswered[1]['value']);

        $context = AiReportContextBuilder::withItems($this->module, ['test' => 'demo-multi-choice'], 'individual', ['2' => ['none']]);
        self::assertSame('Нигде', $context['items'][1]['answer_label']);
        self::assertIsString(json_encode($context, JSON_THROW_ON_ERROR));
    }

    // ------------------------------------------------------ экран прохождения

    public function testTakingScreenRendersNativeCheckboxesInAFieldset(): void
    {
        $html = $this->renderWrapper($this->module, 'demo-multi-choice');

        self::assertSame(1, substr_count($html, '<fieldset class="question-multi">'));
        self::assertStringContainsString('<legend class="question-text">2. Где усталость заметна сильнее всего?</legend>', $html);
        self::assertStringContainsString('Можно выбрать несколько', $html);
        self::assertSame(4, substr_count($html, '<input type="checkbox" name="answers[2][]"'));
        self::assertStringContainsString('<input type="checkbox" name="answers[2][]" value="none" data-exclusive>', $html);
        self::assertSame(1, substr_count($html, 'data-exclusive'));
        self::assertStringContainsString('question-card--multi', $html);
        self::assertStringContainsString('id="nextBtn"', $html);
        // Обычные вопросы того же теста остаются переключателями.
        self::assertSame(3, substr_count($html, '<input type="radio" name="answers[1]"'));
        self::assertSame(3, substr_count($html, '<input type="radio" name="answers[3]"'));
    }

    public function testOrdinaryTestsRenderNoCheckboxMarkup(): void
    {
        foreach (['beck-anxiety' => new BeckAnxietyModule(), 'smil' => new SmilModule(), 'lazarus' => new LazarusModule()] as $slug => $module) {
            $html = $this->renderWrapper($module, $slug);

            self::assertStringNotContainsString('type="checkbox"', $html, $slug);
            self::assertStringNotContainsString('<fieldset', $html, $slug);
            self::assertStringNotContainsString('question-card--multi', $html, $slug);
            self::assertStringNotContainsString('Можно выбрать несколько', $html, $slug);
        }
    }

    public function testScriptHandlesTheMultiCardHooksExposedByTheTemplate(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/public/js/test-taking.js');
        $css = (string) file_get_contents(dirname(__DIR__) . '/public/css/test-taking.css');

        foreach (['question-card--multi', 'data-exclusive', "getElementById('nextBtn')", 'saveMultiAnswer', 'nextBtn.disabled = multiEmpty'] as $hook) {
            self::assertStringContainsString($hook, $js, $hook);
        }
        foreach (['.question-multi', '.answer-option--check', '.question-hint'] as $class) {
            self::assertStringContainsString($class, $css, $class);
        }
    }

    // ------------------------------------------------------- контракт схемы

    public function testEveryMultiQuestionInEveryModuleFollowsTheSchema(): void
    {
        $loader = (new ModuleLoader(null, null))->discover();
        $modules = ['demo-multi-choice' => $this->module];
        foreach (array_keys($loader->getAllModules()) as $slug) {
            $modules[(string) $slug] = $loader->getModule((string) $slug);
        }
        self::assertGreaterThanOrEqual(7, count($modules));
        $checked = 0;

        foreach ($modules as $slug => $module) {
            $schema = $module->getAnswerSchema();
            foreach ($module->getQuestions() as $question) {
                if (!array_key_exists('type', $question)) {
                    continue;
                }
                $where = $slug . ' #' . $question['id'];
                self::assertSame('multi', $question['type'], $where . ': единственный объявляемый тип вопроса — multi.');
                self::assertSame('plain', $schema['key_template'], $where);
                self::assertSame('options', $schema['answer_type'], $where);
                self::assertIsString($question['text'] ?? null, $where);
                self::assertArrayNotHasKey('dual', $question, $where);

                $options = $question['options'] ?? null;
                self::assertIsArray($options, $where);
                self::assertGreaterThanOrEqual(2, count($options), $where);
                self::assertLessThanOrEqual(AnswerNormalizer::MAX_MULTI_ITEMS, count($options), $where);

                $values = [];
                foreach ($options as $option) {
                    self::assertTrue(is_int($option['value'] ?? null) || is_string($option['value'] ?? null), $where);
                    self::assertIsString($option['text'] ?? null, $where);
                    self::assertNotSame('', trim($option['text']), $where);
                    if (array_key_exists('exclusive', $option)) {
                        self::assertIsBool($option['exclusive'], $where);
                    }
                    // Значения через запятую уходят в пункты для ИИ: запятой в значении быть не должно.
                    self::assertStringNotContainsString(',', (string) $option['value'], $where);
                    $values[] = (string) $option['value'];
                }
                self::assertSame($values, array_values(array_unique($values)), $where . ': значения вариантов уникальны.');
                $checked++;
            }
        }

        self::assertGreaterThanOrEqual(1, $checked);
    }

    // -------------------------------------------------------------- helpers

    /**
     * @param array<int|string, mixed> $answers
     * @return list<string>
     */
    private function validate(array $answers): array
    {
        return AnswerValidator::validate($this->module, $answers, true);
    }

    private function renderWrapper(\PsyTest\Modules\TestModuleInterface $module, string $slug): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/templates'), ['cache' => false]);
        TemplateFunctions::register($twig);

        return $twig->render('test-wrapper.twig', [
            'test' => array_merge(['slug' => $slug, 'id' => 1], $module->getMetadata()),
            'session' => ['id' => 's', 'session_token' => 'tok'],
            'questions' => array_slice($module->getQuestions(), 0, 3),
            'module' => $module,
            'csrf_token' => 'csrf',
            'instruction' => $module->getInstruction(),
        ]);
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function document(array $override): array
    {
        return $override + [
            'header' => [
                'test_name' => 'Синтетическая методика',
                'client_label' => 'Образец',
                'completed_at' => '2026-10-01 12:30:00',
                'prepared_by' => 'Подготовил: специалист',
                'confidential' => 'Конфиденциально',
            ],
            'sections' => [],
            'pair' => null,
            'answers' => [],
            'professional' => null,
            'clear' => null,
            'note' => null,
            'generated_at' => '05.10.2026 10:00',
            'disclaimer' => 'Результаты носят ознакомительный характер.',
            'options' => [
                'include_professional' => false,
                'include_clear' => false,
                'include_answers' => true,
                'include_note' => false,
            ],
        ];
    }
}
