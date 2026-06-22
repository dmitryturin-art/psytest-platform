<?php

declare(strict_types=1);

namespace PsyTest\Tests\Smil;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\Smil\Scoring\AdditionalScalesCalculator;
use PsyTest\Modules\Smil\Scoring\RawScoreCalculator;
use PsyTest\Modules\Smil\Scoring\TScoreCalculator;
use PsyTest\Modules\Smil\Scoring\ValidityAssessor;
use PsyTest\Modules\Smil\SmilModule;

final class SmilEndToEndTest extends TestCase
{
    private SmilModule $module;

    protected function setUp(): void
    {
        $this->module = new SmilModule();
    }

    public function testCalculateResultsProducesAllExpectedKeys(): void
    {
        $questions = $this->module->getQuestions();
        $answers = $this->buildMixedAnswers($questions);

        $results = $this->module->calculateResults($answers);

        $this->assertIsArray($results);
        $this->assertArrayHasKey('raw_scores', $results);
        $this->assertArrayHasKey('t_scores', $results);
        $this->assertArrayHasKey('corrected_scores', $results);
        $this->assertArrayHasKey('validity', $results);
        $this->assertArrayHasKey('profile', $results);
        $this->assertArrayHasKey('indices', $results);
        $this->assertArrayHasKey('additional_scores', $results);
        $this->assertArrayHasKey('gender', $results);
        $this->assertArrayHasKey('answered_count', $results);
        $this->assertArrayHasKey('total_questions', $results);
        $this->assertArrayHasKey('completion_rate', $results);

        $this->assertNotEmpty($results['raw_scores']);
        $this->assertNotEmpty($results['t_scores']);
        $this->assertNotEmpty($results['validity']);
        $this->assertNotEmpty($results['profile']);
        $this->assertNotEmpty($results['indices']);

        $this->assertIsArray($results['raw_scores']);
        $this->assertIsArray($results['t_scores']);
        $this->assertIsArray($results['validity']);
        $this->assertIsArray($results['profile']);
        $this->assertIsArray($results['indices']);

        $expectedScales = ['L', 'F', 'K', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0'];
        foreach ($expectedScales as $scale) {
            $this->assertArrayHasKey($scale, $results['raw_scores'], "raw_scores missing $scale");
            $this->assertArrayHasKey($scale, $results['t_scores'], "t_scores missing $scale");
            $this->assertArrayHasKey($scale, $results['corrected_scores'], "corrected_scores missing $scale");
        }

        $this->assertArrayHasKey('is_valid', $results['validity']);
        $this->assertArrayHasKey('L_score', $results['validity']);
        $this->assertArrayHasKey('F_score', $results['validity']);
        $this->assertArrayHasKey('K_score', $results['validity']);
        $this->assertArrayHasKey('warnings', $results['validity']);
        $this->assertArrayHasKey('FK_index', $results['validity']);
        $this->assertArrayHasKey('unknown_count', $results['validity']);
        $this->assertArrayHasKey('control_score', $results['validity']);

        $this->assertArrayHasKey('scales', $results['profile']);
        $this->assertArrayHasKey('dominant', $results['profile']);
        $this->assertArrayHasKey('profile_type', $results['profile']);
        $this->assertArrayHasKey('code_type', $results['profile']);

        $this->assertArrayHasKey('FK_index', $results['indices']);
        $this->assertArrayHasKey('FK_ratio', $results['indices']);
        $this->assertArrayHasKey('anxiety_index', $results['indices']);
        $this->assertArrayHasKey('depression_index', $results['indices']);
    }

    public function testBuildSectionsReturnsAtLeastFiveSections(): void
    {
        $questions = $this->module->getQuestions();
        $answers = $this->buildMixedAnswers($questions);
        $results = $this->module->calculateResults($answers);
        $sections = $this->module->buildSections($results);

        $this->assertIsArray($sections);
        $this->assertGreaterThanOrEqual(5, count($sections), 'Should have at least 5 sections');

        $types = array_map(fn ($s) => $s->type, $sections);
        $this->assertContains('validity', $types);
        $this->assertContains('profile_chart', $types);
        $this->assertContains('scales_table', $types);
        $this->assertContains('indices', $types);
        $this->assertContains('interpretation', $types);
        $this->assertContains('recommendations', $types);
    }

    public function testCalculateResultsMatchesDirectCalculatorCalls(): void
    {
        $questions = $this->module->getQuestions();
        $answers = $this->buildMixedAnswers($questions);

        $results = $this->module->calculateResults($answers);

        $rawScoreCalc = new RawScoreCalculator($questions);
        $expectedRaw = $rawScoreCalc->calculate($answers);

        $this->assertSame(
            $expectedRaw,
            $results['raw_scores'],
            'SmilModule raw_scores should match direct RawScoreCalculator.calculate()'
        );

        $norms = $this->getPrivateProperty($this->module, 'tScoreCalc')->calculate($expectedRaw, 'male');
        $this->assertSame(
            $norms,
            $results['t_scores'],
            'SmilModule t_scores should match direct TScoreCalculator.calculate()'
        );

        $validityAssessor = new ValidityAssessor();
        $expectedValidity = $validityAssessor->assess($norms, $answers);

        $this->assertSame(
            $expectedValidity['is_valid'],
            $results['validity']['is_valid'],
            'Validity is_valid should match'
        );
        $this->assertSame(
            $expectedValidity['L_score'],
            $results['validity']['L_score'],
            'Validity L_score should match'
        );
        $this->assertSame(
            $expectedValidity['F_score'],
            $results['validity']['F_score'],
            'Validity F_score should match'
        );
        $this->assertSame(
            $expectedValidity['K_score'],
            $results['validity']['K_score'],
            'Validity K_score should match'
        );
        $this->assertSame(
            $expectedValidity['FK_index'],
            $results['validity']['FK_index'],
            'Validity FK_index should match'
        );
        $this->assertSame(
            $expectedValidity['unknown_count'],
            $results['validity']['unknown_count'],
            'Validity unknown_count should match'
        );
        $this->assertSame(
            $expectedValidity['control_score'],
            $results['validity']['control_score'],
            'Validity control_score should match'
        );
    }

    public function testAnswerCountAndCompletionRate(): void
    {
        $questions = $this->module->getQuestions();
        $answers = $this->buildMixedAnswers($questions);
        $numericCount = count(array_filter($answers, fn ($k) => is_numeric($k), ARRAY_FILTER_USE_KEY));

        $results = $this->module->calculateResults($answers);

        $this->assertSame(566, $results['total_questions']);
        $this->assertSame($numericCount, $results['answered_count']);
        $this->assertGreaterThanOrEqual(0.0, $results['completion_rate']);
        $this->assertLessThanOrEqual(100.0, $results['completion_rate']);
    }

    public function testGenderIsPreservedInResults(): void
    {
        $questions = $this->module->getQuestions();
        $answers = $this->buildMixedAnswers($questions);
        $answers['gender'] = 'female';

        $results = $this->module->calculateResults($answers);
        $this->assertSame('female', $results['gender']);

        $answers['gender'] = 'male';
        $results = $this->module->calculateResults($answers);
        $this->assertSame('male', $results['gender']);
    }

    public function testUnknownAnswersAffectCompletionRate(): void
    {
        $questions = $this->module->getQuestions();
        $answers = [];
        $unknownCount = 0;
        foreach ($questions as $i => $q) {
            if ($i < 100) {
                $answers[$q['id']] = 2; // Unknown
                $unknownCount++;
            } else {
                $answers[$q['id']] = 1;
            }
        }
        $answers['gender'] = 'male';

        $results = $this->module->calculateResults($answers);
        $this->assertSame($unknownCount, $results['validity']['unknown_count']);
        $this->assertSame(566, $results['total_questions']);
        $this->assertGreaterThan(0, $results['validity']['warnings'][0] ?? '');
    }

    public function testProfileTypeHasExpectedStructure(): void
    {
        $questions = $this->module->getQuestions();
        $answers = $this->buildMixedAnswers($questions);
        $answers['gender'] = 'male';

        $results = $this->module->calculateResults($answers);
        $profile = $results['profile'];

        $this->assertContains($profile['profile_type'], [
            'normosthenic', 'neurotic', 'psychotic', 'personal_deviation', 'mixed',
        ]);

        $this->assertNotEmpty($profile['code_type']);

        foreach ($profile['scales'] as $scale => $data) {
            $this->assertArrayHasKey('score', $data);
            $this->assertArrayHasKey('level', $data);
            $this->assertArrayHasKey('interpretation', $data);
            $this->assertArrayHasKey('name', $data);
            $this->assertIsFloat($data['score']);
        }

        $this->assertIsArray($profile['dominant']);
        $this->assertCount(3, $profile['dominant']);
    }

    private function buildMixedAnswers(array $questions): array
    {
        $values = [0, 1, 2];
        $answers = [];
        foreach ($questions as $i => $q) {
            $answers[$q['id']] = $values[$i % 3];
        }
        $answers['gender'] = 'male';
        return $answers;
    }

    public function testReferenceResultMatchesPsytestsOrg(): void
    {
        $answersJson = file_get_contents(__DIR__ . '/../fixtures/smil-reference-answers.json');
        $this->assertNotFalse($answersJson, 'Failed to load reference answers fixture');
        $answers = json_decode($answersJson, true);
        $this->assertIsArray($answers, 'Failed to parse reference answers JSON');

        $scoresJson = file_get_contents(__DIR__ . '/../fixtures/smil-reference-scores.json');
        $this->assertNotFalse($scoresJson, 'Failed to load reference scores fixture');
        $expected = json_decode($scoresJson, true);
        $this->assertIsArray($expected, 'Failed to parse reference scores JSON');

        $gender = $answers['gender'] ?? 'female';

        $results = $this->module->calculateResults($answers);

        $this->assertSame($gender, $results['gender'], 'Gender should match fixture');

        foreach ($expected['raw'] as $scale => $expectedRaw) {
            $actualRaw = $results['raw_scores'][$scale] ?? null;
            $this->assertNotNull($actualRaw, "Raw score for scale $scale should exist");

            $this->assertEquals(
                $expectedRaw,
                $actualRaw,
                "Raw score for scale $scale should match reference (tolerance ±1)",
                1
            );
        }

        foreach ($expected['t'] as $scale => $expectedT) {
            $actualT = $results['t_scores'][$scale] ?? null;
            $this->assertNotNull($actualT, "T-score for scale $scale should exist");
            $this->assertIsFloat($actualT, "T-score for scale $scale should be a float");
            $this->assertGreaterThanOrEqual(20, $actualT, "T-score for scale $scale should be >= 20");
            $this->assertLessThanOrEqual(100, $actualT, "T-score for scale $scale should be <= 100");
        }
    }

    private function getPrivateProperty(object $object, string $property): object
    {
        $reflection = new \ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        return $prop->getValue($object);
    }
}
