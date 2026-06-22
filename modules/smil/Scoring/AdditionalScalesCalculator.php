<?php

declare(strict_types=1);

namespace PsyTest\Modules\Smil\Scoring;

final class AdditionalScalesCalculator
{
    private array $norms;

    public function __construct(array $norms)
    {
        $this->norms = $norms;
    }

    /**
     * Calculate additional scales raw scores and T-scores.
     *
     * @param array $answers question_id => answer
     * @param string $gender 'male' or 'female'
     * @return array<string, array>
     */
    public function calculate(array $answers, string $gender): array
    {
        $results = [];

        foreach ($this->norms as $category => $scales) {
            foreach ($scales as $code => $info) {
                if (!isset($info['key']) || !isset($info['norms'])) {
                    continue;
                }

                $rawScore = 0;
                $key = $info['key'];

                foreach ($key['true'] ?? [] as $questionId) {
                    if (isset($answers[$questionId])) {
                        $answer = $answers[$questionId];
                        if ($answer === 1 || $answer === '1' || $answer === true || $answer === 'true') {
                            $rawScore++;
                        }
                    }
                }

                foreach ($key['false'] ?? [] as $questionId) {
                    if (isset($answers[$questionId])) {
                        $answer = $answers[$questionId];
                        if ($answer === 0 || $answer === '0' || $answer === false || $answer === 'false') {
                            $rawScore++;
                        }
                    }
                }

                $norms = $info['norms'][$gender] ?? $info['norms']['male'] ?? [];
                $M = $norms['M'] ?? 0;
                $delta = $norms['delta'] ?? 1;

                if ($delta == 0) {
                    $tScore = 50;
                } else {
                    $tScore = round(50 + 10 * ($rawScore - $M) / $delta);
                }

                $tScore = max(20, min(120, $tScore));

                $results[$code] = [
                    'name' => $info['name'] ?? $code,
                    'raw' => $rawScore,
                    't' => $tScore,
                    'M' => $M,
                    'delta' => $delta,
                    'interpretation' => 'Интерпретация отсутствует',
                ];
            }
        }

        return $results;
    }
}
