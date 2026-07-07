<?php

declare(strict_types=1);

namespace PsyTest\Tests\Unit\Smil;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\Smil\SmilModule;

class AdditionalScalesTest extends TestCase
{
    public function testBuildAdditionalScalesDataReturnsCategories(): void
    {
        $module = new SmilModule();
        $additionalScores = [
            'A' => ['name' => 'Тревожность', 'raw' => 13, 't' => 45, 'M' => 16.48, 'delta' => 6.94],
            'R' => ['name' => 'Защитная реакция', 'raw' => 8, 't' => 25, 'M' => 17.05, 'delta' => 3.55],
            'ANX' => ['name' => 'Тревога', 'raw' => 13, 't' => 54, 'M' => 12.13, 'delta' => 2.36],
            'DEP' => ['name' => 'Депрессия', 'raw' => 10, 't' => 60, 'M' => 10.0, 'delta' => 2.0],
        ];

        $data = $this->invokeMethod($module, 'buildAdditionalScalesData', [$additionalScores]);

        $this->assertArrayHasKey('categories', $data);
        $this->assertIsArray($data['categories']);
        $this->assertGreaterThan(0, count($data['categories']));

        // Check first category has required structure
        $firstCategory = $data['categories'][0];
        $this->assertArrayHasKey('name', $firstCategory);
        $this->assertArrayHasKey('items', $firstCategory);
        $this->assertIsArray($firstCategory['items']);
        $this->assertGreaterThan(0, count($firstCategory['items']));

        // Check item structure
        $firstItem = $firstCategory['items'][0];
        $this->assertArrayHasKey('code', $firstItem);
        $this->assertArrayHasKey('name', $firstItem);
        $this->assertArrayHasKey('raw', $firstItem);
        $this->assertArrayHasKey('t_score', $firstItem);
        $this->assertArrayHasKey('level', $firstItem);
        $this->assertArrayHasKey('level_name', $firstItem);
        $this->assertArrayHasKey('marker_position', $firstItem);
    }

    public function testBuildAdditionalScalesDataHandlesEmptyScores(): void
    {
        $module = new SmilModule();
        $data = $this->invokeMethod($module, 'buildAdditionalScalesData', [[]]);

        $this->assertArrayHasKey('categories', $data);
        $this->assertIsArray($data['categories']);
        $this->assertCount(0, $data['categories']);
    }

    private function invokeMethod($object, string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        // setAccessible() is a no-op since PHP 8.1 and deprecated in 8.5
        return $method->invokeArgs($object, $parameters);
    }
}
