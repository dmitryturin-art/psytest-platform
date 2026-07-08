<?php

declare(strict_types=1);

namespace PsyTest\Modules\Smil\Scoring;

final class RawScoreCalculator
{
    public const ANSWER_YES = 1;
    public const ANSWER_NO = 0;
    public const ANSWER_UNKNOWN = 2;

    private const SCALE_MAP_5F_5M = [
        '5F' => '5',
        '5M' => '5',
    ];

    private const GENDER_SUFFIXES = [
        'male' => 'M',
        'female' => 'F',
    ];

    /** @var array<int, list<array{scale: string, direction: int}>> */
    private array $questionMap;

    /**
     * @param array<int, array{id: int, scales?: list<array{scale: string, direction: int}>}> $questions Question list from questions-566-full.json.
     */
    public function __construct(array $questions)
    {
        $this->questionMap = [];
        foreach ($questions as $q) {
            $scales = $q['scales'] ?? [];
            if (empty($scales)) {
                continue;
            }
            $entries = [];
            foreach ($scales as $entry) {
                $scale = (string) $entry['scale'];
                $direction = (int) $entry['direction'];
                $entries[] = [
                    'scale' => $scale,
                    'direction' => $direction,
                ];
            }
            $this->questionMap[(int) $q['id']] = $entries;
        }
    }

    /**
     * Calculate raw scores from answers.
     *
     * @param array<int, int|string> $answers question_id => answer
     * @param string $gender 'male' or 'female' (default: 'male')
     * @return array<string, int> scale_code => raw_score
     */
    public function calculate(array $answers, string $gender = 'male'): array
    {
        $rawScores = [
            'L' => 0, 'F' => 0, 'K' => 0,
            '1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0,
            '6' => 0, '7' => 0, '8' => 0, '9' => 0, '0' => 0,
        ];

        $genderSuffix = self::GENDER_SUFFIXES[$gender] ?? 'M';

        foreach ($answers as $questionId => $answer) {
            $qid = (int) $questionId;
            if (!isset($this->questionMap[$qid])) {
                continue;
            }

            $answerInt = (int) $answer;
            if ($answerInt === self::ANSWER_UNKNOWN) {
                continue;
            }

            foreach ($this->questionMap[$qid] as $entry) {
                $scale = $entry['scale'];

                // Gender-specific filtering: skip 5M for females, 5F for males
                if ($scale === '5M' && $genderSuffix === 'F') {
                    continue;
                }
                if ($scale === '5F' && $genderSuffix === 'M') {
                    continue;
                }

                $normalizedScale = $this->normalizeScale($scale);

                if (!isset($rawScores[$normalizedScale])) {
                    continue;
                }

                $isYes = ($answerInt === self::ANSWER_YES);

                if ($entry['direction'] === 1) {
                    $rawScores[$normalizedScale] += $isYes ? 1 : 0;
                } else {
                    $rawScores[$normalizedScale] += $isYes ? 0 : 1;
                }
            }
        }

        return $rawScores;
    }

    /**
     * @param array<int|string, int|bool|string> $answers question_id => answer.
     */
    public function countUnknown(array $answers): int
    {
        $count = 0;
        foreach ($answers as $qid => $answer) {
            if (!is_numeric($qid)) {
                continue;
            }
            if ((int) $answer === self::ANSWER_UNKNOWN) {
                $count++;
            }
        }
        return $count;
    }

    private function normalizeScale(string $scale): string
    {
        return self::SCALE_MAP_5F_5M[$scale] ?? $scale;
    }
}
