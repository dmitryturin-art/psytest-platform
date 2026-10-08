<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Ai\AiReportContextBuilder;
use PsyTest\Core\Ai\PromptFixtureContext;
use PsyTest\Core\AnswerValidator;
use PsyTest\Core\CaseExportDocx;
use PsyTest\Core\InvitedCasePresenter;
use PsyTest\Core\ResultSectionRenderer;
use PsyTest\Core\TemplateFunctions;
use PsyTest\Modules\IpipNeo120\IpipNeo120Module;
use PsyTest\Modules\ResultSection;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * IPIP-NEO-120 (09.O1): пункты и ключи, подсчёт по golden-фикстурам,
 * нормы, полосы, контекст ИИ и рендер секций.
 *
 * Фикстуры `tests/fixtures/ipip/*.json` посчитаны независимым скриптом по
 * табл. 1 Johnson (2014), а не этим модулем.
 */
final class IpipNeo120ModuleTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures/ipip';

    private const FORBIDDEN_KEYS = [
        'name', 'email', 'note', 'owner_note', 'client_label', 'client_id',
        'session_id', 'session_token', 'result_token', 'user_email', 'user_name',
        'ip_address', 'user_agent', 'gender', 'age', 'demographics',
    ];

    private IpipNeo120Module $module;

    protected function setUp(): void
    {
        $this->module = new IpipNeo120Module();
    }

    public function testItemsFollowJohnsonOrderFacetsAndKeys(): void
    {
        $questions = $this->module->getQuestions();
        self::assertCount(120, $questions);

        $perFacet = [];
        $minus = 0;
        foreach ($questions as $index => $q) {
            $id = $index + 1;
            self::assertSame($id, $q['id'], 'Порядок и номера пунктов — как в Johnson (2014).');
            // Пункты идут по кругу: N1 E1 O1 A1 C1 N2 E2 … (табл. 1, «IPIP-120 item no.»).
            $facet = 'NEOAC'[($id - 1) % 5] . (intdiv(($id - 1) % 30, 5) + 1);
            self::assertSame($facet, $q['facet'], "Пункт {$id}");
            self::assertSame($facet[0], $q['domain']);
            self::assertContains($q['keyed'], ['+', '-']);
            foreach (['text', 'text_en', 'text_back', 'facet_name'] as $field) {
                self::assertIsString($q[$field]);
                self::assertNotSame('', trim($q[$field]), "Пункт {$id}: {$field}");
            }
            self::assertSame([1, 2, 3, 4, 5], array_column($q['options'], 'value'));
            $perFacet[$facet] = ($perFacet[$facet] ?? 0) + 1;
            $minus += $q['keyed'] === '-' ? 1 : 0;
        }

        self::assertCount(30, $perFacet);
        self::assertSame([4], array_values(array_unique($perFacet)));
        self::assertSame(55, $minus, 'Johnson (2014): 55 обратных пунктов из 120.');
    }

    public function testFacetNamesAreConsistentWithinAFacet(): void
    {
        $names = [];
        foreach ($this->module->getQuestions() as $q) {
            $names[$q['facet']][$q['facet_name']] = true;
        }
        foreach ($names as $facet => $variants) {
            self::assertCount(1, $variants, "У грани {$facet} одно русское название");
        }
    }

    /** @return iterable<string, array{string}> */
    public static function goldenCases(): iterable
    {
        foreach (['all-1', 'all-5', 'alternating', 'random-seed-20261008'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('goldenCases')]
    public function testRawScoresMatchIndependentReference(string $name): void
    {
        $case = $this->fixture($name);
        $results = $this->module->calculateResults($case['answers'] + ['gender' => $case['gender'], 'age' => $case['age']]);

        foreach ($case['expected']['facets'] as $facet => $sum) {
            self::assertSame($sum, $results['facets'][$facet]['raw'], "{$name}: {$facet}");
        }
        foreach ($case['expected']['domains'] as $domain => $sum) {
            self::assertSame($sum, $results['domains'][$domain]['raw'], "{$name}: {$domain}");
        }
        self::assertSame(120, $results['answered_count']);
        self::assertSame(120, $results['total_questions']);
    }

    public function testReverseKeyedItemIsScoredSixMinusAnswer(): void
    {
        // N2 «Гневливость»: 6, 36, 66 прямые, 96 «Меня мало что раздражает» — обратный.
        $answers = array_fill_keys(range(1, 120), 3);
        $answers[96] = 5;
        $results = $this->module->calculateResults($answers + ['gender' => 'male', 'age' => 30]);
        self::assertSame(3 + 3 + 3 + 1, $results['facets']['N2']['raw']);

        $answers[96] = 1;
        $results = $this->module->calculateResults($answers + ['gender' => 'male', 'age' => 30]);
        self::assertSame(3 + 3 + 3 + 5, $results['facets']['N2']['raw']);
    }

    public function testBandThresholds(): void
    {
        self::assertSame('low', IpipNeo120Module::band(1));
        self::assertSame('low', IpipNeo120Module::band(30));
        self::assertSame('average', IpipNeo120Module::band(31));
        self::assertSame('average', IpipNeo120Module::band(69));
        self::assertSame('high', IpipNeo120Module::band(70));
        self::assertSame('high', IpipNeo120Module::band(99));
        self::assertSame('Ниже, чем у большинства', IpipNeo120Module::levelName('low'));
    }

    public function testNormsTablesAreCompleteMonotonicAndTraceable(): void
    {
        $norms = $this->module->norms();
        self::assertSame(1, $norms['schema']);
        self::assertStringContainsString('Johnson, 2014', $norms['label']);
        self::assertStringContainsString('на русской выборке не проверено', $norms['label']);
        self::assertStringContainsString('Перевод PsyTest, не валидирован', $norms['label']);
        self::assertSame('https://osf.io/wxvth/', $norms['source']['component']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $norms['source']['file_sha256']);

        $expectedCells = [];
        foreach (['male', 'female'] as $sex) {
            foreach (['18_24', '25_34', '35_49', '50_plus', 'all'] as $age) {
                $expectedCells[] = $sex . '_' . $age;
            }
        }
        $expectedCells[] = 'all';
        self::assertEqualsCanonicalizing($expectedCells, array_keys($norms['cells']));

        $sum = 0;
        foreach ($norms['cells'] as $key => $cell) {
            self::assertGreaterThanOrEqual(1000, $cell['n'], "Ячейка {$key}: N записан и достаточен");
            self::assertNotSame('', $cell['label']);
            if (preg_match('/_(18_24|25_34|35_49|50_plus)\z/', $key) === 1) {
                $sum += $cell['n'];
            }
            self::assertCount(35, $cell['scales']);
            foreach ($cell['scales'] as $scale => $table) {
                $isDomain = strlen($scale) === 1;
                self::assertSame($isDomain ? 24 : 4, $table['min']);
                self::assertSame($isDomain ? 120 : 20, $table['max']);
                self::assertCount($table['max'] - $table['min'] + 1, $table['percentiles'], "{$key}/{$scale}");
                $previous = 0;
                foreach ($table['percentiles'] as $p) {
                    self::assertIsInt($p);
                    self::assertGreaterThanOrEqual(1, $p);
                    self::assertLessThanOrEqual(99, $p);
                    self::assertGreaterThanOrEqual($previous, $p, "{$key}/{$scale}: перцентиль не убывает");
                    $previous = $p;
                }
            }
        }
        self::assertSame($norms['cells']['all']['n'], $sum, 'Восемь ячеек пол × возраст покрывают всю выборку.');
        self::assertSame($norms['counts']['kept'], $sum);
    }

    public function testNormCellSelection(): void
    {
        self::assertSame('female_25_34', $this->module->normCellKey('female', 30));
        self::assertSame('female_18_24', $this->module->normCellKey('female', '18'));
        self::assertSame('male_35_49', $this->module->normCellKey('male', 49));
        self::assertSame('male_50_plus', $this->module->normCellKey('male', 50));
        self::assertSame('male_50_plus', $this->module->normCellKey('male', 99));
        self::assertSame('male_all', $this->module->normCellKey('male', null), 'Без возраста — все взрослые этого пола.');
        self::assertSame('female_all', $this->module->normCellKey('female', 'abc'));
        self::assertSame('all', $this->module->normCellKey(null, 30), 'Без пола — все взрослые.');
    }

    public function testPercentilesAndBandsComeFromTheNormsCell(): void
    {
        $case = $this->fixture('random-seed-20261008');
        $results = $this->module->calculateResults($case['answers'] + ['gender' => 'female', 'age' => 55]);
        $cell = $this->module->norms()['cells']['female_50_plus'];

        self::assertSame('female_50_plus', $results['norm_cell']);
        self::assertSame($cell['n'], $results['norm_cell_n']);
        foreach ($results['domains'] + $results['facets'] as $code => $row) {
            $table = $cell['scales'][$code];
            $expected = $table['percentiles'][$row['raw'] - $table['min']];
            self::assertSame($expected, $row['percentile'], (string) $code);
            self::assertSame(IpipNeo120Module::band($expected), $row['level']);
        }
    }

    public function testIncompleteAnswersGetNoPercentileAndAreRejectedBySchema(): void
    {
        $answers = array_fill_keys(range(1, 119), 3) + ['gender' => 'female', 'age' => '30'];
        $results = $this->module->calculateResults($answers);
        self::assertSame(119, $results['answered_count']);
        self::assertNull($results['domains']['C']['percentile']);
        self::assertNull($results['facets']['C6']['level']);

        self::assertContains('incomplete_answers', AnswerValidator::validate($this->module, $answers, true));
        $full = array_fill_keys(range(1, 120), '3');
        self::assertSame([], AnswerValidator::validate($this->module, $full + ['gender' => 'female', 'age' => '30'], true));
        self::assertContains('invalid_gender', AnswerValidator::validate($this->module, $full + ['age' => '30'], true));
        self::assertContains('invalid_age', AnswerValidator::validate($this->module, $full + ['gender' => 'male'], true));
        self::assertContains('invalid_age', AnswerValidator::validate($this->module, $full + ['gender' => 'male', 'age' => '17'], true), 'Нормы только для взрослых.');
        self::assertContains('invalid_answer', AnswerValidator::validate($this->module, ['1' => '0'], false));
    }

    public function testInstructionAndDemographicsGate(): void
    {
        $instruction = implode(' ', $this->module->getInstruction());
        self::assertStringContainsString('не как хотели бы', $instruction);
        self::assertStringContainsString('Правильных и неправильных ответов нет', $instruction);
        self::assertStringContainsString('15–20 минут', $instruction);

        $gate = $this->module->getDemographicsRequirements();
        self::assertTrue($gate['gender']);
        self::assertTrue($gate['age']);
        self::assertSame(18, $gate['min_age']);

        // Пять вариантов ответа — столбцом (не сеткой «Верно/Неверно»), в каталоге — «Личность».
        $metadata = $this->module->getMetadata();
        self::assertSame('options', $metadata['answer_type']);
        self::assertSame('Личность', $metadata['catalog_category']);
    }

    public function testAiContextCarriesDomainsFacetsAndNormsButNothingPersonal(): void
    {
        $context = PromptFixtureContext::build($this->module, 'individual');

        self::assertSame('ipip-neo-120', $context['test']);
        self::assertSame(['answered' => 120, 'total' => 120], $context['completeness']);
        self::assertCount(5, $context['subscales']);
        self::assertSame(['N', 'E', 'O', 'A', 'C'], array_column($context['subscales'], 'code'));
        foreach ($context['subscales'] as $domain) {
            self::assertIsInt($domain['percentile']);
            self::assertContains($domain['level'], ['low', 'average', 'high']);
            self::assertIsString($domain['interpretation']);
            self::assertSame(120, $domain['max']);
        }
        self::assertCount(30, $context['facets']);
        foreach ($context['facets'] as $facet) {
            self::assertIsInt($facet['percentile']);
            self::assertSame(20, $facet['max']);
        }
        self::assertStringContainsString('Johnson, 2014', $context['norms']['description']);
        self::assertNotSame('', $context['norms']['group']);
        self::assertArrayNotHasKey('items', $context);
        self::assertSame([], array_values(array_intersect(self::FORBIDDEN_KEYS, self::keys($context))));
        self::assertNull($this->module->aiReportContext([], 'individual'));
        self::assertNull($this->module->aiReportContext(['domains' => []], 'pair'));
    }

    public function testAiItemsUseRussianTextAndAnswerLabels(): void
    {
        $context = PromptFixtureContext::build($this->module, 'individual', null, true);
        self::assertCount(120, $context['items']);
        self::assertSame('Беспокоюсь о разных вещах.', $context['items'][0]['text']);
        self::assertSame(1, $context['items'][0]['value']);
        self::assertSame('Совершенно не согласен', $context['items'][0]['answer_label']);

        $withItems = AiReportContextBuilder::withItems($this->module, ['test' => 'ipip-neo-120'], 'individual', ['1' => 5]);
        self::assertSame('Полностью согласен', $withItems['items'][0]['answer_label']);
        self::assertSame('Нет ответа', $withItems['items'][1]['answer_label']);
    }

    public function testGuestSeesProfileAndSpecialistAlsoGetsRawTable(): void
    {
        $results = $this->results();
        $guest = $this->module->buildSections($results);
        self::assertSame([ResultSection::TYPE_TRAIT_PROFILE], array_map(static fn (ResultSection $s): string => $s->type, $guest));

        $case = (new InvitedCasePresenter())->resultSections($this->module, $results);
        self::assertSame(
            [ResultSection::TYPE_TRAIT_PROFILE, ResultSection::TYPE_SCALES_TABLE],
            array_map(static fn (ResultSection $s): string => $s->type, $case),
        );
        $table = $case[1]->data;
        self::assertSame('Перцентиль', $table['score_label']);
        self::assertCount(5, $table['categories']);
        foreach ($table['categories'] as $category) {
            self::assertCount(7, $category['items'], 'Домен и шесть граней');
        }
    }

    public function testWebBlockShowsFiveDomainsWithCollapsedFacetsAndTheNormsLabel(): void
    {
        $section = $this->module->buildSections($this->results())[0];
        $html = $this->twig()->render((string) $section->block, $section->data + ['_section_type' => $section->type]);

        foreach (['Нейротизм', 'Экстраверсия', 'Открытость опыту', 'Доброжелательность', 'Добросовестность'] as $name) {
            self::assertStringContainsString($name, $html);
        }
        self::assertSame(5, substr_count($html, '<details'), 'Грани каждого домена — свёрнутый блок');
        self::assertStringNotContainsString('<details class="ai-report__details" open', $html);
        self::assertSame(5, substr_count($html, '6 граней'));
        self::assertStringContainsString('Тревожность', $html);
        self::assertStringContainsString('Нормы: международная выборка IPIP-NEO (Johnson, 2014)', $html);
        self::assertStringContainsString('Перевод PsyTest, не валидирован', $html);
        self::assertStringContainsString('женщины 50 и старше', $html);
        self::assertSame(5, substr_count($html, 'class="trait-bar"'));
        // Нейтральная шкала: клинических цветов уровней в блоке нет.
        self::assertStringNotContainsString('--level-', $html);
        self::assertStringNotContainsString('score-badge--', $html);
    }

    public function testPdfRendersTheSameValuesAsTablesWithoutDisclosures(): void
    {
        $sections = $this->module->buildSections($this->results() + ['is_pdf' => true]);
        $twig = $this->twig();
        $renderer = new ResultSectionRenderer(static fn (string $template, array $data): string => $twig->render($template . '.twig', $data));
        $html = $renderer->renderToHtml($sections);

        self::assertStringNotContainsString('<details', $html);
        self::assertStringNotContainsString('trait-bar', $html);
        self::assertStringContainsString('Добросовестность', $html);
        self::assertStringContainsString('Осмотрительность', $html);
        self::assertStringContainsString('на русской выборке не проверено', $html);
        self::assertSame(6, substr_count($html, '<table'), 'Сводка доменов и пять таблиц граней');
    }

    public function testCaseWordExportCarriesDomainsFacetsAndNormsLabel(): void
    {
        $twig = $this->twig();
        $sections = (new InvitedCasePresenter())->resultSections($this->module, $this->results());
        $docx = (new CaseExportDocx(
            static fn (string $template, array $data): string => $twig->render($template . '.twig', $data),
        ))->render([
            'header' => [
                'test_name' => 'IPIP-NEO-120',
                'client_label' => 'Образец',
                'completed_at' => '2026-10-08 12:30:00',
                'prepared_by' => 'Подготовил: специалист',
                'confidential' => 'Конфиденциально',
            ],
            'sections' => $sections,
            'pair' => null,
            'answers' => [],
            'professional' => null,
            'clear' => null,
            'note' => null,
            'generated_at' => '08.10.2026 10:00',
            'disclaimer' => 'Результаты носят ознакомительный характер.',
            'options' => ['include_professional' => false, 'include_clear' => false, 'include_answers' => false, 'include_note' => false],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'ipip-docx');
        self::assertIsString($path);
        file_put_contents($path, $docx);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path) === true);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($path);

        foreach (['Нейротизм', 'Осмотрительность', 'Сырые баллы и перцентили', 'на русской выборке не проверено'] as $text) {
            self::assertStringContainsString($text, $xml);
        }
    }

    public function testTranslationReviewTableListsEveryItem(): void
    {
        $review = (string) file_get_contents(dirname(__DIR__) . '/docs/ipip-translation-review.md');
        foreach ($this->module->getQuestions() as $q) {
            self::assertStringContainsString('| ' . $q['id'] . ' | ' . $q['facet'] . ' ', $review);
            self::assertStringContainsString($q['text'], $review);
            self::assertStringContainsString($q['text_en'], $review);
        }
        self::assertStringContainsString('не валидирован', $review);
    }

    /** @return array<string, mixed> */
    private function results(): array
    {
        $case = $this->fixture('random-seed-20261008');

        return $this->module->calculateResults($case['answers'] + ['gender' => $case['gender'], 'age' => $case['age']]);
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        $decoded = json_decode((string) file_get_contents(self::FIXTURES . '/' . $name . '.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function twig(): Environment
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/templates'), [
            'cache' => false,
            'strict_variables' => true,
        ]);
        TemplateFunctions::register($twig);

        return $twig;
    }

    /**
     * @param array<mixed> $data
     * @return list<string>
     */
    private static function keys(array $data): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            $keys[] = (string) $key;
            if (is_array($value)) {
                $keys = array_merge($keys, self::keys($value));
            }
        }

        return $keys;
    }
}
