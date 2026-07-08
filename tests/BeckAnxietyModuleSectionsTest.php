<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\BeckAnxiety\BeckAnxietyModule;

final class BeckAnxietyModuleSectionsTest extends TestCase
{
    public function testBuildSectionsReturnsRecognizedTypes(): void
    {
        $module = new BeckAnxietyModule();
        $questions = $module->getQuestions();
        $answers = [];
        foreach ($questions as $q) {
            $answers[$q['id']] = 0;
        }
        $results = $module->calculateResults($answers);
        $sections = $module->buildSections($results);
        $this->assertGreaterThanOrEqual(2, count($sections));
        $types = array_map(fn ($s) => $s->type, $sections);
        $this->assertContains('score_badge', $types);
        $this->assertContains('recommendations', $types);
    }

    public function testScoreBadgeSectionHasCorrectData(): void
    {
        $module = new BeckAnxietyModule();
        $questions = $module->getQuestions();
        $answers = [];
        foreach ($questions as $q) {
            $answers[$q['id']] = 0;
        }
        $results = $module->calculateResults($answers);
        $sections = $module->buildSections($results);
        $badge = array_values(array_filter($sections, fn ($s) => $s->type === 'score_badge'))[0];
        $this->assertSame(0, $badge->data['score']);
        $this->assertSame(63, $badge->data['max']);
        $this->assertSame('minimal', $badge->data['level']);
    }
}
