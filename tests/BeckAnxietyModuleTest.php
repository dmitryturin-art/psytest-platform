<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\BeckAnxiety\BeckAnxietyModule;
use PsyTest\Modules\ResultSection;

final class BeckAnxietyModuleTest extends TestCase
{
    public function testBuildSectionsReturnsCorrectStructure(): void
    {
        $module = new BeckAnxietyModule();
        $results = [
            'total_score' => 15,
            'max_score' => 63,
            'percentage' => 24,
            'level' => 'minimal',
            'level_name' => 'Незначительная тревога',
            'interpretation' => 'Значение до 21 балла...',
            'recommendations' => ['Рекомендация 1', 'Рекомендация 2'],
            'answered_count' => 21,
            'total_questions' => 21,
            'symptom_scores' => [],
        ];

        $sections = $module->buildSections($results);

        $this->assertCount(2, $sections);
        $this->assertInstanceOf(ResultSection::class, $sections[0]);
        $this->assertSame(ResultSection::TYPE_SCORE_BADGE, $sections[0]->type);
        $this->assertSame('Уровень тревоги', $sections[0]->title);
        $this->assertSame(15, $sections[0]->data['score']);
        $this->assertSame(63, $sections[0]->data['max']);
        $this->assertSame('minimal', $sections[0]->data['level']);
        $this->assertSame('Незначительная тревога', $sections[0]->data['level_label']);
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
        $module = new BeckAnxietyModule();
        $sections = $module->buildSections([]);

        $this->assertCount(2, $sections);
        $this->assertSame(0, $sections[0]->data['score']);
        $this->assertSame('minimal', $sections[0]->data['level']);
        $this->assertSame([], $sections[1]->data['items']);
    }

    public function testBuildSectionsHighLevel(): void
    {
        $module = new BeckAnxietyModule();
        $results = [
            'total_score' => 40,
            'max_score' => 63,
            'level' => 'high',
            'level_name' => 'Высокая тревога',
            'interpretation' => '...',
            'recommendations' => ['Обратитесь к специалисту'],
        ];

        $sections = $module->buildSections($results);

        $this->assertSame('high', $sections[0]->data['level']);
        $this->assertSame('Высокая тревога', $sections[0]->data['level_label']);
    }
}
