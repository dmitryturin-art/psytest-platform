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

    public function testAdditionalScalesSectionHasCategoriesStructure(): void
    {
        $module = new SmilModule();
        $questions = $module->getQuestions();
        $answers = [];
        foreach ($questions as $q) {
            $answers[$q['id']] = 1;
        }
        $results = $module->calculateResults($answers);
        $sections = $module->buildSections($results);

        // Find additional scales section
        $additionalScalesSection = null;
        foreach ($sections as $section) {
            if ($section->title === 'Дополнительные шкалы') {
                $additionalScalesSection = $section;
                break;
            }
        }

        $this->assertNotNull($additionalScalesSection, 'Additional scales section should exist');
        $this->assertArrayHasKey('categories', $additionalScalesSection->data);
        $this->assertIsArray($additionalScalesSection->data['categories']);

        if (!empty($additionalScalesSection->data['categories'])) {
            $firstCategory = $additionalScalesSection->data['categories'][0];
            $this->assertArrayHasKey('name', $firstCategory);
            $this->assertArrayHasKey('items', $firstCategory);
            $this->assertIsArray($firstCategory['items']);

            if (!empty($firstCategory['items'])) {
                $firstItem = $firstCategory['items'][0];
                $this->assertArrayHasKey('code', $firstItem);
                $this->assertArrayHasKey('name', $firstItem);
                $this->assertArrayHasKey('raw', $firstItem);
                $this->assertArrayHasKey('t_score', $firstItem);
                $this->assertArrayHasKey('level', $firstItem);
                $this->assertArrayHasKey('level_name', $firstItem);
            }
        }
    }

    /**
     * Пустые или неполные `calculated_results` не должны валить страницу.
     *
     * Так выглядит сессия без ответов (в том числе синтетическая): расчёт не
     * дошёл до контрольных шкал, и прямой доступ `$validity['is_valid']`
     * поднимал Warning «Undefined array key "is_valid"». Секции обязаны
     * собираться и в этом случае — сам расчёт при этом не меняется.
     */
    public function testBuildSectionsSurvivesEmptyCalculatedResults(): void
    {
        $module = new SmilModule();

        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $sections = $module->buildSections([]);
        } finally {
            restore_error_handler();
        }

        $types = array_map(static fn ($s): string => $s->type, $sections);
        $this->assertContains('validity', $types);
        $this->assertContains('profile_chart', $types);

        $validity = null;
        foreach ($sections as $section) {
            if ($section->type === 'validity') {
                $validity = $section;
                break;
            }
        }

        $this->assertNotNull($validity);
        $this->assertFalse($validity->data['is_valid']);
    }

    public function testBuildSectionsSurvivesPartialCalculatedResults(): void
    {
        $module = new SmilModule();

        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $sections = $module->buildSections([
                'validity' => [],
                'profile' => [],
                'raw_scores' => ['K' => 10],
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertNotSame([], $sections);
    }
}
