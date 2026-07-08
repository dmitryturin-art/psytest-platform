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
            $this->assertLessThanOrEqual(100, $t);
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

    public function testMaxRawScoresNeverExceed100Male(): void
    {
        // Maximum possible raw scores based on actual question counts
        $maxRawScores = [
            'L' => 15,
            'F' => 65,
            'K' => 30,
            '1' => 33,
            '2' => 60,
            '3' => 59,
            '4' => 50,
            '5' => 60,
            '6' => 40,
            '7' => 47,
            '8' => 78,
            '9' => 46,
            '0' => 70,
        ];

        $tScores = $this->calc->calculate($maxRawScores, 'male');

        foreach ($tScores as $scale => $tScore) {
            $this->assertLessThanOrEqual(
                100.0,
                $tScore,
                "T-score for scale $scale exceeds 100: $tScore (raw: {$maxRawScores[$scale]})"
            );
            $this->assertGreaterThanOrEqual(
                20.0,
                $tScore,
                "T-score for scale $scale below 20: $tScore (raw: {$maxRawScores[$scale]})"
            );
        }
    }

    public function testMaxRawScoresNeverExceed100Female(): void
    {
        // Maximum possible raw scores based on actual question counts
        $maxRawScores = [
            'L' => 15,
            'F' => 65,
            'K' => 30,
            '1' => 33,
            '2' => 60,
            '3' => 59,
            '4' => 50,
            '5' => 60,
            '6' => 40,
            '7' => 47,
            '8' => 78,
            '9' => 46,
            '0' => 70,
        ];

        $tScores = $this->calc->calculate($maxRawScores, 'female');

        foreach ($tScores as $scale => $tScore) {
            $this->assertLessThanOrEqual(
                100.0,
                $tScore,
                "T-score for scale $scale exceeds 100: $tScore (raw: {$maxRawScores[$scale]})"
            );
            $this->assertGreaterThanOrEqual(
                20.0,
                $tScore,
                "T-score for scale $scale below 20: $tScore (raw: {$maxRawScores[$scale]})"
            );
        }
    }

    public function testKCorrectedScalesWithMaxK(): void
    {
        // Test K-corrected scales with extreme K value
        $rawScores = [
            'L' => 0,
            'F' => 0,
            'K' => 30, // Max K
            '1' => 33, // Max for scale 1 (K-corrected with factor 0.5)
            '2' => 60, // Max for scale 2 (no K-correction)
            '3' => 59, // Max for scale 3 (K-corrected with factor 0.3)
            '4' => 50, // Max for scale 4 (K-corrected with factor 0.4)
            '5' => 60,
            '6' => 40, // K-corrected with factor 0.3
            '7' => 47, // Max for scale 7 (K-corrected with factor 1.0)
            '8' => 78, // Max for scale 8 (K-corrected with factor 1.0)
            '9' => 46, // Max for scale 9 (K-corrected with factor 0.2)
            '0' => 70,
        ];

        $tScores = $this->calc->calculate($rawScores, 'male');

        foreach ($tScores as $scale => $tScore) {
            $this->assertLessThanOrEqual(
                100.0,
                $tScore,
                "T-score for scale $scale exceeds 100: $tScore (raw: {$rawScores[$scale]}, K: {$rawScores['K']})"
            );
            $this->assertGreaterThanOrEqual(
                20.0,
                $tScore,
                "T-score for scale $scale below 20: $tScore (raw: {$rawScores[$scale]}, K: {$rawScores['K']})"
            );
        }
    }
}
