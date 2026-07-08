<?php

declare(strict_types=1);

namespace PsyTest\Modules\Smil\Scoring;

final class TScoreCalculator
{
    private const T_MIN = 20;
    private const T_MAX = 100;

    /** @var array<string, array{male?: array{M: int|float, delta: int|float}, female?: array{M: int|float, delta: int|float}, kCorrectionFactor?: int|float}> */
    private array $norms;

    /**
     * @param array<string, mixed> $norms Basic-scale norms (Собчик), keyed by scale code.
     */
    public function __construct(array $norms)
    {
        $this->norms = $norms;
    }

    /**
     * Convert raw scores to T-scores with K-correction.
     *
     * Formula: T = 50 + 10 * (X - M) / σ, clamped to [20, 100]
     * K-correction: X' = X + round(K * factor)
     *
     * @param array<string, int> $rawScores Raw scores keyed by scale code (L, F, K, 1-9, 0).
     * @param string             $gender    'male' or 'female'.
     *
     * @return array<string, float>
     */
    public function calculate(array $rawScores, string $gender): array
    {
        $tScores = [];

        foreach ($rawScores as $scale => $rawScore) {
            if (!isset($this->norms[$scale])) {
                $tScores[$scale] = 50.0;
                continue;
            }

            $scaleNorms = $this->norms[$scale][$gender] ?? $this->norms[$scale]['male'];
            $M = (float) $scaleNorms['M'];
            $delta = (float) $scaleNorms['delta'];

            $correctedRaw = (float) $rawScore;
            $kFactor = $this->norms[$scale]['kCorrectionFactor'] ?? null;

            if ($kFactor !== null && isset($rawScores['K'])) {
                $kCorrection = (int) round((float) $rawScores['K'] * (float) $kFactor);
                $correctedRaw = (float) $rawScore + $kCorrection;
            }

            if ($delta == 0.0) {
                $tScores[$scale] = 50.0;
            } else {
                $tScore = 50.0 + 10.0 * ($correctedRaw - $M) / $delta;
                $tScores[$scale] = (float) max(self::T_MIN, min(self::T_MAX, round($tScore)));
            }
        }

        return $tScores;
    }
}
