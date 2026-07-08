<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\Smil\SmilModule;

/**
 * End-to-end validation test against psytests.org reference result.
 *
 * Validates the complete SMIL calculation pipeline (answers → raw scores → T-scores)
 * against real-world reference data from psytests.org. Both raw scores and T-scores
 * must match the reference within tight tolerances (±1 raw, ±2 T), confirming the
 * scoring keys (Solomin), the linear T-formula with K-correction, and the Собчик
 * norms all agree with an independent reference implementation.
 */
final class SmilEndToEndTest extends TestCase
{
    private SmilModule $module;

    protected function setUp(): void
    {
        $this->module = new SmilModule();
    }

    /**
     * Validates SMIL calculation pipeline against psytests.org reference result.
     *
     * Reference: a female respondent's 566 answers with known raw and T scores
     * for all 13 basic scales (L, F, K, 1-9, 0) published on psytests.org.
     */
    public function testReferenceResultMatchesPsytestsOrg(): void
    {
        $answersJson = file_get_contents(__DIR__ . '/../fixtures/smil-reference-answers.json');
        $this->assertNotFalse($answersJson, 'Failed to load reference answers fixture');
        $answers = json_decode($answersJson, true);
        $this->assertIsArray($answers, 'Failed to parse reference answers JSON');

        $scoresJson = file_get_contents(__DIR__ . '/../fixtures/smil-reference-scores.json');
        $this->assertNotFalse($scoresJson, 'Failed to load reference scores fixture');
        $expected = json_decode($scoresJson, true);
        $this->assertIsArray($expected, 'Failed to parse reference scores JSON');

        $gender = $answers['gender'] ?? 'female';

        $results = $this->module->calculateResults($answers);

        // Validate gender preservation
        $this->assertSame($gender, $results['gender'], 'Gender should match fixture');

        // Validate raw scores (must match reference exactly ±1).
        // Scale 5 is gender-filtered: female respondents count only 5F items.
        foreach ($expected['raw'] as $scale => $expectedRaw) {
            $actualRaw = $results['raw_scores'][$scale] ?? null;
            $this->assertNotNull($actualRaw, "Raw score for scale $scale should exist");
            $this->assertEquals(
                $expectedRaw,
                $actualRaw,
                "Raw score for scale $scale should match reference (tolerance ±1)",
                1
            );
        }

        // Validate T-scores against the reference (tolerance ±2).
        // The Собчик norms in basic_scales_norms.json agree with psytests.org:
        // on the reference protocol, all 13 scales match within ±0.
        foreach ($expected['t'] as $scale => $expectedT) {
            $actualT = $results['t_scores'][$scale] ?? null;
            $this->assertNotNull($actualT, "T-score for scale $scale should exist");
            $this->assertIsFloat($actualT, "T-score for scale $scale should be a float");

            $this->assertGreaterThanOrEqual(20, $actualT, "T-score for scale $scale should be >= 20");
            $this->assertLessThanOrEqual(100, $actualT, "T-score for scale $scale should be <= 100");

            $this->assertEquals(
                $expectedT,
                $actualT,
                "T-score for scale $scale should match reference (tolerance ±2)",
                2
            );
        }
    }
}
