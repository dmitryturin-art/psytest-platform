<?php
declare(strict_types=1);

namespace PsyTest\Tests\Smil;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\Smil\Scoring\AdditionalScalesCalculator;

final class AdditionalScalesCalculatorTest extends TestCase
{
    private AdditionalScalesCalculator $calc;

    protected function setUp(): void
    {
        $norms = json_decode(
            file_get_contents(__DIR__ . '/../../modules/smil/additional-scales-norms.json'),
            true
        )['scales'];
        $this->calc = new AdditionalScalesCalculator($norms);
    }

    public function testReturnsNonEmptyResultsWithRealNorms(): void
    {
        $answers = [];
        for ($i = 1; $i <= 566; $i++) {
            $answers[$i] = 1;
        }

        $results = $this->calc->calculate($answers, 'male');

        $this->assertNotEmpty($results, 'Should return non-empty results');
        $this->assertIsArray($results);
    }

    public function testScaleContainsExpectedFields(): void
    {
        $answers = [];
        for ($i = 1; $i <= 566; $i++) {
            $answers[$i] = 1;
        }

        $results = $this->calc->calculate($answers, 'male');
        $first = reset($results);

        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('raw', $first);
        $this->assertArrayHasKey('t', $first);
        $this->assertArrayHasKey('M', $first);
        $this->assertArrayHasKey('delta', $first);
        $this->assertSame('Интерпретация отсутствует', $first['interpretation']);
    }

    public function testKnownFactorScalesArePresent(): void
    {
        $answers = [];
        for ($i = 1; $i <= 566; $i++) {
            $answers[$i] = 1;
        }

        $results = $this->calc->calculate($answers, 'male');

        $this->assertArrayHasKey('A', $results, 'Factor scale A should be present');
        $this->assertArrayHasKey('R', $results, 'Factor scale R should be present');
        $this->assertArrayHasKey('Es', $results, 'Special scale Es should be present');
    }

    public function testTScoresAreClamped(): void
    {
        $answers = [];
        for ($i = 1; $i <= 566; $i++) {
            $answers[$i] = 1;
        }

        $results = $this->calc->calculate($answers, 'male');

        foreach ($results as $code => $data) {
            $this->assertGreaterThanOrEqual(20, $data['t'], "$code T-score >= 20");
            $this->assertLessThanOrEqual(120, $data['t'], "$code T-score <= 120");
        }
    }
}
