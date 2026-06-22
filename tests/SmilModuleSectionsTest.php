<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\Smil\SmilModule;

final class SmilModuleSectionsTest extends TestCase
{
    public function testBuildSectionsReturnsAllRequiredTypes(): void
    {
        $module = new SmilModule();
        $questions = $module->getQuestions();
        $answers = [];
        foreach ($questions as $q) {
            $answers[$q['id']] = 1;
        }
        $results = $module->calculateResults($answers);
        $sections = $module->buildSections($results);
        $types = array_map(fn ($s) => $s->type, $sections);
        $this->assertContains('validity', $types);
        $this->assertContains('profile_chart', $types);
        $this->assertContains('scales_table', $types);
        $this->assertContains('interpretation', $types);
    }

    public function testSectionsAreOrdered(): void
    {
        $module = new SmilModule();
        $questions = $module->getQuestions();
        $answers = [];
        foreach ($questions as $q) {
            $answers[$q['id']] = 1;
        }
        $results = $module->calculateResults($answers);
        $sections = $module->buildSections($results);
        $orders = array_map(fn ($s) => $s->order, $sections);
        $sorted = $orders;
        sort($sorted);
        $this->assertSame($sorted, $orders, 'Sections should be sorted by order');
    }
}
