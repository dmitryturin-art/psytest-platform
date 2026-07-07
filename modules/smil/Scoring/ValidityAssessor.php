<?php

declare(strict_types=1);

namespace PsyTest\Modules\Smil\Scoring;

final class ValidityAssessor
{
    public const CONTROL_QUESTIONS = [
        14, 33, 48, 63, 66, 69, 121, 123, 133, 151, 168, 182, 184,
        197, 200, 205, 266, 275, 293, 334, 349, 350, 462, 464, 474, 542, 551,
    ];

    /**
     * Assess protocol validity from T-scores, answers, and control questions.
     *
     * @param array<string, float> $tScores T-scores keyed by scale code (L, F, K, ...).
     * @param array<int, int>      $answers question_id => answer (0/1/2).
     *
     * @return array{is_valid: bool, warnings: list<string>, L_score: int, F_score: int, K_score: int, FK_index: int, unknown_count: int, control_score: int}
     */
    public function assess(array $tScores, array $answers): array
    {
        $L = (int) ($tScores['L'] ?? 50);
        $F = (int) ($tScores['F'] ?? 50);
        $K = (int) ($tScores['K'] ?? 50);
        $valid = true;
        $warnings = [];

        $unknownCount = $this->countUnknown($answers);
        $controlScore = $this->countControlCorrect($answers);

        if ($controlScore < 20) {
            $valid = false;
            $warnings[] = "Протокол недостоверен: низкая внимательность (QC = {$controlScore} < 20)";
        }

        if ($unknownCount > 70) {
            $valid = false;
            $warnings[] = "Протокол недостоверен: слишком много ответов \"Не знаю\" ({$unknownCount} > 70)";
        } elseif ($unknownCount > 60) {
            $warnings[] = "Сомнительная достоверность: много ответов \"Не знаю\" ({$unknownCount})";
        } elseif ($unknownCount > 40) {
            $warnings[] = "Настороженность: повышенное количество ответов \"Не знаю\" ({$unknownCount})";
        }

        if ($L >= 65) {
            $valid = false;
            $warnings[] = 'Высокая социальная желательность — результаты могут быть недостоверны';
        }

        if ($F >= 70) {
            $valid = false;
            $warnings[] = 'Высокий показатель F — возможны случайные ответы или преувеличение проблем';
        } elseif ($F >= 65) {
            $warnings[] = 'Повышенный показатель F — возможна тенденция к преувеличению';
        }

        if ($K >= 65) {
            $warnings[] = 'Высокая защитная позиция — клинические шкалы могут быть занижены';
        } elseif ($K <= 35) {
            $warnings[] = 'Низкая защитная позиция — возможна излишняя откровенность';
        }

        $fkIndex = $F - $K;
        if ($fkIndex > 20) {
            $warnings[] = 'Индекс F-K повышен — возможна симуляция';
        } elseif ($fkIndex < -15) {
            $warnings[] = 'Индекс F-K понижен — возможна диссимуляция';
        }

        return [
            'is_valid' => $valid,
            'warnings' => $warnings,
            'L_score' => $L, 'F_score' => $F, 'K_score' => $K,
            'FK_index' => $fkIndex,
            'unknown_count' => $unknownCount,
            'control_score' => $controlScore,
        ];
    }

    /**
     * @param array<int|string, int> $answers question_id => answer.
     */
    private function countUnknown(array $answers): int
    {
        $count = 0;
        foreach ($answers as $qid => $answer) {
            if (!is_numeric($qid)) {
                continue;
            }
            if ((int) $answer === 2) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @param array<int|string, int> $answers question_id => answer.
     */
    private function countControlCorrect(array $answers): int
    {
        $correct = 0;
        foreach (self::CONTROL_QUESTIONS as $cq) {
            if (isset($answers[$cq]) && (int) $answers[$cq] === 1) {
                $correct++;
            }
        }
        return $correct;
    }
}
