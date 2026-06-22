<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\Hads\HadsModule;
use PsyTest\Modules\ResultSection;

final class HadsModuleTest extends TestCase
{
    public function testModuleLoadsMetadata(): void
    {
        $module = new HadsModule();
        $metadata = $module->getMetadata();

        $this->assertSame('hads', $metadata['slug']);
        $this->assertSame('sum', $metadata['scoring_type']);
        $this->assertSame(42, $metadata['max_score']);
        $this->assertSame(14, $metadata['question_count']);
    }

    public function testModuleLoadsQuestions(): void
    {
        $module = new HadsModule();
        $questions = $module->getQuestions();

        $this->assertCount(14, $questions);
        $this->assertSame(1, $questions[0]['id']);
        $this->assertArrayHasKey('text', $questions[0]);
        $this->assertArrayHasKey('options', $questions[0]);
        $this->assertArrayHasKey('scale', $questions[0]);
        $this->assertCount(4, $questions[0]['options']);
        $this->assertSame(0, $questions[0]['options'][0]['value']);
        $this->assertSame(3, $questions[0]['options'][3]['value']);
    }

    public function testAnxietyItemsAreOdd(): void
    {
        $module = new HadsModule();
        $questions = $module->getQuestions();

        foreach ($questions as $q) {
            if ($q['id'] % 2 === 1) {
                $this->assertSame('HADS-A', $q['scale'], "Question {$q['id']} should be HADS-A");
            }
        }
    }

    public function testDepressionItemsAreEven(): void
    {
        $module = new HadsModule();
        $questions = $module->getQuestions();

        foreach ($questions as $q) {
            if ($q['id'] % 2 === 0) {
                $this->assertSame('HADS-D', $q['scale'], "Question {$q['id']} should be HADS-D");
            }
        }
    }

    public function testCalculateResultsNormalScores(): void
    {
        $module = new HadsModule();
        $questions = $module->getQuestions();
        $answers = [];
        foreach ($questions as $q) {
            $answers[$q['id']] = 0;
        }

        $results = $module->calculateResults($answers);

        $this->assertSame(0, $results['anxiety_score']);
        $this->assertSame(0, $results['depression_score']);
        $this->assertSame(0, $results['total_score']);
        $this->assertSame(42, $results['max_score']);
        $this->assertSame('normal', $results['anxiety_level']);
        $this->assertSame('normal', $results['depression_level']);
        $this->assertSame('Норма', $results['anxiety_level_name']);
        $this->assertSame('Норма', $results['depression_level_name']);
        $this->assertSame(14, $results['answered_count']);
        $this->assertSame(14, $results['total_questions']);
    }

    public function testCalculateResultsSubclinicalAnxiety(): void
    {
        $module = new HadsModule();
        $questions = $module->getQuestions();
        $answers = [];
        foreach ($questions as $q) {
            if ($q['id'] % 2 === 1) {
                // Anxiety items: 7 items × 1.15 ≈ 8, so score 1 on all anxiety
                $answers[$q['id']] = 1;
            } else {
                $answers[$q['id']] = 0;
            }
        }

        $results = $module->calculateResults($answers);

        $this->assertSame(7, $results['anxiety_score']);
        $this->assertSame(0, $results['depression_score']);
        // 7 is at the boundary of normal (0-7), so it should be normal
        $this->assertSame('normal', $results['anxiety_level']);
        $this->assertSame('normal', $results['depression_level']);
    }

    public function testCalculateResultsClinicalAnxiety(): void
    {
        $module = new HadsModule();
        $questions = $module->getQuestions();
        $answers = [];
        foreach ($questions as $q) {
            if ($q['id'] % 2 === 1) {
                // Anxiety items: 7 × 2 = 14 (clinical)
                $answers[$q['id']] = 2;
            } else {
                $answers[$q['id']] = 0;
            }
        }

        $results = $module->calculateResults($answers);

        $this->assertSame(14, $results['anxiety_score']);
        $this->assertSame(0, $results['depression_score']);
        $this->assertSame('clinical', $results['anxiety_level']);
        $this->assertSame('Клинический уровень', $results['anxiety_level_name']);
    }

    public function testCalculateResultsSubclinicalDepression(): void
    {
        $module = new HadsModule();
        $questions = $module->getQuestions();
        $answers = [];
        foreach ($questions as $q) {
            if ($q['id'] % 2 === 0) {
                // Depression items: 7 × 1.15 ≈ 8, so score 1 on some, 2 on one
                $answers[$q['id']] = $q['id'] === 2 ? 2 : 1;
            } else {
                $answers[$q['id']] = 0;
            }
        }

        $results = $module->calculateResults($answers);

        // Depression: 2 (q2) + 1*6 = 8
        $this->assertSame(0, $results['anxiety_score']);
        $this->assertSame(8, $results['depression_score']);
        $this->assertSame('normal', $results['anxiety_level']);
        $this->assertSame('subclinical', $results['depression_level']);
        $this->assertSame('Субклинический уровень', $results['depression_level_name']);
    }

    public function testCalculateResultsClinicalDepression(): void
    {
        $module = new HadsModule();
        $questions = $module->getQuestions();
        $answers = [];
        foreach ($questions as $q) {
            if ($q['id'] % 2 === 0) {
                // Depression items: 7 × 2 = 14 (clinical)
                $answers[$q['id']] = 2;
            } else {
                $answers[$q['id']] = 0;
            }
        }

        $results = $module->calculateResults($answers);

        $this->assertSame(14, $results['depression_score']);
        $this->assertSame('clinical', $results['depression_level']);
    }

    public function testCalculateResultsBothClinical(): void
    {
        $module = new HadsModule();
        $questions = $module->getQuestions();
        $answers = [];
        foreach ($questions as $q) {
            $answers[$q['id']] = 2;
        }

        $results = $module->calculateResults($answers);

        $this->assertSame(14, $results['anxiety_score']);
        $this->assertSame(14, $results['depression_score']);
        $this->assertSame(28, $results['total_score']);
        $this->assertSame('clinical', $results['anxiety_level']);
        $this->assertSame('clinical', $results['depression_level']);
        $this->assertSame('clinical', $results['level']);
    }

    public function testGenerateInterpretationReturnsCorrectStructure(): void
    {
        $module = new HadsModule();
        $scores = [
            'total_score' => 15,
            'max_score' => 42,
            'anxiety_score' => 8,
            'depression_score' => 7,
            'anxiety_level' => 'subclinical',
            'depression_level' => 'normal',
            'anxiety_level_name' => 'Субклинический уровень',
            'depression_level_name' => 'Норма',
            'anxiety_interpretation' => 'Субклинически выраженная тревога (8-10 баллов)',
            'depression_interpretation' => 'Уровень депрессии находится в пределах нормы (0-7 баллов)',
            'recommendations' => ['Рекомендация 1', 'Рекомендация 2'],
        ];

        $interpretation = $module->generateInterpretation($scores);

        $this->assertArrayHasKey('summary', $interpretation);
        $this->assertArrayHasKey('total_score', $interpretation);
        $this->assertArrayHasKey('anxiety_score', $interpretation);
        $this->assertArrayHasKey('depression_score', $interpretation);
        $this->assertArrayHasKey('anxiety_level', $interpretation);
        $this->assertArrayHasKey('depression_level', $interpretation);
        $this->assertArrayHasKey('anxiety_level_name', $interpretation);
        $this->assertArrayHasKey('depression_level_name', $interpretation);
        $this->assertArrayHasKey('recommendations', $interpretation);
        $this->assertArrayHasKey('disclaimer', $interpretation);
        $this->assertStringContainsString('8 из 21', $interpretation['summary']);
        $this->assertStringContainsString('7 из 21', $interpretation['summary']);
    }

    public function testBuildSectionsReturnsCorrectStructure(): void
    {
        $module = new HadsModule();
        $results = [
            'anxiety_score' => 8,
            'depression_score' => 7,
            'anxiety_level' => 'subclinical',
            'depression_level' => 'normal',
            'anxiety_level_name' => 'Субклинический уровень',
            'depression_level_name' => 'Норма',
            'anxiety_interpretation' => 'Субклинически выраженная тревога (8-10 баллов)',
            'depression_interpretation' => 'Уровень депрессии находится в пределах нормы (0-7 баллов)',
            'recommendations' => ['Рекомендация 1', 'Рекомендация 2'],
        ];

        $sections = $module->buildSections($results);

        $this->assertCount(4, $sections);

        // Anxiety score badge
        $this->assertInstanceOf(ResultSection::class, $sections[0]);
        $this->assertSame(ResultSection::TYPE_SCORE_BADGE, $sections[0]->type);
        $this->assertSame('Уровень тревоги', $sections[0]->title);
        $this->assertSame(8, $sections[0]->data['score']);
        $this->assertSame(21, $sections[0]->data['max']);
        $this->assertSame('subclinical', $sections[0]->data['level']);
        $this->assertSame('Субклинический уровень', $sections[0]->data['level_label']);
        $this->assertSame('blocks/score-badge.twig', $sections[0]->block);
        $this->assertSame(10, $sections[0]->order);

        // Depression score badge
        $this->assertInstanceOf(ResultSection::class, $sections[1]);
        $this->assertSame(ResultSection::TYPE_SCORE_BADGE, $sections[1]->type);
        $this->assertSame('Уровень депрессии', $sections[1]->title);
        $this->assertSame(7, $sections[1]->data['score']);
        $this->assertSame(21, $sections[1]->data['max']);
        $this->assertSame('normal', $sections[1]->data['level']);
        $this->assertSame('Норма', $sections[1]->data['level_label']);
        $this->assertSame('blocks/score-badge.twig', $sections[1]->block);
        $this->assertSame(15, $sections[1]->order);

        // Interpretation
        $this->assertInstanceOf(ResultSection::class, $sections[2]);
        $this->assertSame(ResultSection::TYPE_INTERPRETATION, $sections[2]->type);
        $this->assertSame('Интерпретация', $sections[2]->title);
        $this->assertSame(20, $sections[2]->order);

        // Recommendations
        $this->assertInstanceOf(ResultSection::class, $sections[3]);
        $this->assertSame(ResultSection::TYPE_RECOMMENDATIONS, $sections[3]->type);
        $this->assertSame('Рекомендации', $sections[3]->title);
        $this->assertSame(['Рекомендация 1', 'Рекомендация 2'], $sections[3]->data['items']);
        $this->assertSame('blocks/recommendations.twig', $sections[3]->block);
        $this->assertSame(30, $sections[3]->order);
    }

    public function testBuildSectionsHandlesEmptyResults(): void
    {
        $module = new HadsModule();
        $sections = $module->buildSections([]);

        $this->assertCount(4, $sections);
        $this->assertSame(0, $sections[0]->data['score']);
        $this->assertSame('normal', $sections[0]->data['level']);
        $this->assertSame('Норма', $sections[0]->data['level_label']);
        $this->assertSame(0, $sections[1]->data['score']);
        $this->assertSame('normal', $sections[1]->data['level']);
        $this->assertSame([], $sections[3]->data['items']);
    }

    public function testSupportsPairModeReturnsFalse(): void
    {
        $module = new HadsModule();
        $this->assertFalse($module->supportsPairMode());
    }
}
