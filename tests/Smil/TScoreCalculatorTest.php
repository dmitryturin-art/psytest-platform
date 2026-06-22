<?php
declare(strict_types=1);

namespace PsyTest\Tests\Smil;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\Smil\Scoring\TScoreCalculator;

final class TScoreCalculatorTest extends TestCase
{
    private TScoreCalculator $calc;

    protected function setUp(): void
    {
        $norms = json_decode(
            file_get_contents(__DIR__ . '/../../modules/smil/basic_scales_norms.json'),
            true
        )['scales'];
        $this->calc = new TScoreCalculator($norms);
    }

    public function testReferenceValuesFromPsytestsOrg(): void
    {
        $raw = [
            'L' => 4, 'F' => 7, 'K' => 19,
            '1' => 9, '2' => 16, '3' => 29, '4' => 20, '5' => 31,
            '6' => 11, '7' => 12, '8' => 14, '9' => 18, '0' => 14,
        ];

        $tScores = $this->calc->calculate($raw, 'female');

        $this->assertEqualsWithDelta(49, $tScores['L'], 1.0, 'L: raw=4 → T=49');
        $this->assertEqualsWithDelta(58, $tScores['F'], 1.0, 'F: raw=7 → T=58');
        $this->assertEqualsWithDelta(63, $tScores['K'], 1.0, 'K: raw=19 → T=63');
        $this->assertEqualsWithDelta(63, $tScores['1'], 1.0, 'Hs: raw=9 +0.5K→19 → T=63');
    }

    public function testTScoreRangeClamping(): void
    {
        $raw = array_fill_keys(['L','F','K','1','2','3','4','5','6','7','8','9','0'], 1000);
        $tScores = $this->calc->calculate($raw, 'male');
        foreach ($tScores as $t) {
            $this->assertLessThanOrEqual(120, $t);
        }
    }

    public function testZeroRawGivesValidTRange(): void
    {
        $raw = array_fill_keys(['L','F','K','1','2','3','4','5','6','7','8','9','0'], 0);
        $tScores = $this->calc->calculate($raw, 'male');
        foreach ($tScores as $t) {
            $this->assertGreaterThanOrEqual(20, $t);
            $this->assertLessThanOrEqual(50, $t, 'Zero raw should give low T');
        }
    }
}
