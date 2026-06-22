<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\BeckDepression\BeckDepressionModule;
use PsyTest\Modules\ResultSection;

final class BeckDepressionModuleTest extends TestCase
{
    public function testModuleLoadsMetadata(): void
    {
        $module = new BeckDepressionModule();
        $metadata = $module->getMetadata();

        $this->assertSame('bdi', $metadata['slug']);
        $this->assertSame('sum', $metadata['scoring_type']);
        $this->assertSame(63, $metadata['max_score']);
        $this->assertSame(21, $metadata['question_count']);
    }

    public function testModuleLoadsQuestions(): void
    {
        $module = new BeckDepressionModule();
        $questions = $module->getQuestions();

        $this->assertCount(21, $questions);
        $this->assertSame(1, $questions[0]['id']);
        $this->assertArrayHasKey('text', $questions[0]);
        $this->assertArrayHasKey('options', $questions[0]);
        $this->assertCount(4, $questions[0]['options']);
        $this->assertSame(0, $questions[0]['options'][0]['value']);
        $this->assertSame(3, $questions[0]['options'][3]['value']);
    }

    public function testCalculateResultsMinimalScore(): void
    {
        $module = new BeckDepressionModule();
        $questions = $module->getQuestions();
        $answers = [];
        foreach ($questions as $q) {
            $answers[$q['id']] = 0;
        }

        $results = $module->calculateResults($answers);

        $this->assertSame(0, $results['total_score']);
        $this->assertSame('minimal', $results['level']);
        $this->assertSame('Минимальная депрессия', $results['level_name']);
    }

    public function testCalculateResultsMildScore(): void
    {
        $module = new BeckDepressionModule();
        $questions = $module->getQuestions();
        $answers = [];
        foreach ($questions as $q) {
            $answers[$q['id']] = $q['id'] <= 14 ? 1 : 0;
        }

        $results = $module->calculateResults($answers);

        $this->assertSame(14, $results['total_score']);
        $this->assertSame('mild', $results['level']);
        $this->assertSame('Лёгкая депрессия', $results['level_name']);
    }

    public function testCalculateResultsModerateScore(): void
    {
        $module = new BeckDepressionModule();
        $questions = $module->getQuestions();
        $answers = [];
        foreach ($questions as $q) {
            $answers[$q['id']] = $q['id'] <= 10 ? 2 : (($q['id'] <= 18) ? 1 : 0);
        }

        $results = $module->calculateResults($answers);

        $this->assertSame(28, $results['total_score']);
        $this->assertSame('moderate', $results['level']);
    }

    public function testCalculateResultsSevereScore(): void
    {
        $module = new BeckDepressionModule();
        $questions = $module->getQuestions();
        $answers = [];
        foreach ($questions as $q) {
            $answers[$q['id']] = $q['id'] <= 17 ? 2 : 1;
        }

        $results = $module->calculateResults($answers);

        $this->assertSame(38, $results['total_score']);
        $this->assertSame('severe', $results['level']);
    }

    public function testGenerateInterpretationReturnsCorrectStructure(): void
    {
        $module = new BeckDepressionModule();
        $scores = [
            'total_score' => 15,
            'max_score' => 63,
            'level' => 'mild',
            'level_name' => 'Лёгкая депрессия',
            'interpretation' => '...',
            'recommendations' => ['Рекомендация 1'],
        ];

        $interpretation = $module->generateInterpretation($scores);

        $this->assertArrayHasKey('summary', $interpretation);
        $this->assertArrayHasKey('total_score', $interpretation);
        $this->assertArrayHasKey('level', $interpretation);
        $this->assertArrayHasKey('level_name', $interpretation);
        $this->assertArrayHasKey('recommendations', $interpretation);
        $this->assertArrayHasKey('disclaimer', $interpretation);
    }

    public function testBuildSectionsReturnsCorrectStructure(): void
    {
        $module = new BeckDepressionModule();
        $results = [
            'total_score' => 15,
            'max_score' => 63,
            'level' => 'mild',
            'level_name' => 'Лёгкая депрессия',
            'interpretation' => '...',
            'recommendations' => ['Рекомендация 1', 'Рекомендация 2'],
            'answered_count' => 21,
            'total_questions' => 21,
            'symptom_scores' => [],
        ];

        $sections = $module->buildSections($results);

        $this->assertCount(2, $sections);
        $this->assertInstanceOf(ResultSection::class, $sections[0]);
        $this->assertSame(ResultSection::TYPE_SCORE_BADGE, $sections[0]->type);
        $this->assertSame('Уровень депрессии', $sections[0]->title);
        $this->assertSame(15, $sections[0]->data['score']);
        $this->assertSame(63, $sections[0]->data['max']);
        $this->assertSame('mild', $sections[0]->data['level']);
        $this->assertSame('Лёгкая депрессия', $sections[0]->data['level_label']);
        $this->assertSame('blocks/score-badge.twig', $sections[0]->block);
        $this->assertSame(10, $sections[0]->order);

        $this->assertSame(ResultSection::TYPE_RECOMMENDATIONS, $sections[1]->type);
        $this->assertSame('Рекомендации', $sections[1]->title);
        $this->assertSame(['Рекомендация 1', 'Рекомендация 2'], $sections[1]->data['items']);
        $this->assertSame('blocks/recommendations.twig', $sections[1]->block);
        $this->assertSame(20, $sections[1]->order);
    }

    public function testBuildSectionsHandlesEmptyResults(): void
    {
        $module = new BeckDepressionModule();
        $sections = $module->buildSections([]);

        $this->assertCount(2, $sections);
        $this->assertSame(0, $sections[0]->data['score']);
        $this->assertSame('minimal', $sections[0]->data['level']);
        $this->assertSame([], $sections[1]->data['items']);
    }

    public function testSupportsPairModeReturnsFalse(): void
    {
        $module = new BeckDepressionModule();
        $this->assertFalse($module->supportsPairMode());
    }
}
