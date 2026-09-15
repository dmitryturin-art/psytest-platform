<?php

declare(strict_types=1);

namespace PsyTest\Modules\Smil\Scoring;

/**
 * Дополнительные шкалы СМИЛ по приложению Собчик (2003).
 *
 * Определения приходят из modules/smil/additional-scales-v2.json, который
 * собирается скриптом bin/smil-build-batch.php прямо из транскрипции S2.
 * Клинических текстов калькулятор не производит: только raw, T, нормы пола
 * и происхождение шкалы.
 */
final class AdditionalScalesCalculator
{
    /** Ответ «верно». */
    private const ANSWER_YES = 1;
    /** Ответ «неверно». */
    private const ANSWER_NO = 0;
    /** Ответ «не знаю» — в сыром балле не участвует. */
    private const ANSWER_UNKNOWN = 2;

    private const T_MIN = 20;
    private const T_MAX = 100;

    /** T-баллы, с которых имеет смысл нейтральная подпись. */
    private const T_HIGH = 65;
    private const T_LOW = 35;

    /** @var list<array<string, mixed>> */
    private array $scales;

    /**
     * @param list<array<string, mixed>> $scales Определения шкал из additional-scales-v2.json → scales.
     */
    public function __construct(array $scales)
    {
        $this->scales = array_values($scales);
    }

    /**
     * Рассчитать дополнительные шкалы.
     *
     * raw = совпадения «верно» по key.true плюс «неверно» по key.false.
     * T   = 50 + 10 * (raw - M) / sigma по нормам пола респондента,
     *       округление до целого и зажим [20, 100] — как в базовых шкалах.
     *
     * @param array<int|string, int|bool|string> $answers question_id => ответ (0/1/2).
     * @param string                             $gender  'male' или 'female'.
     *
     * @return array<string, array{id: string, code: string, name: string, raw: int, t: float, M: int|float, sigma: int|float, max_raw: int, answered: int, level: string, level_name: string, source: array<string, mixed>, status: string, note: string}>
     */
    public function calculate(array $answers, string $gender): array
    {
        $sex = $gender === 'female' ? 'female' : 'male';
        $results = [];

        foreach ($this->scales as $scale) {
            $code = (string) ($scale['code'] ?? '');
            if ($code === '' || !isset($scale['key'], $scale['norms'])) {
                continue;
            }

            $raw = 0;
            $answered = 0;

            foreach ((array) ($scale['key']['true'] ?? []) as $questionId) {
                $answer = $this->answerFor($answers, (int) $questionId);
                if ($answer === null) {
                    continue;
                }
                $answered++;
                if ($answer === self::ANSWER_YES) {
                    $raw++;
                }
            }

            foreach ((array) ($scale['key']['false'] ?? []) as $questionId) {
                $answer = $this->answerFor($answers, (int) $questionId);
                if ($answer === null) {
                    continue;
                }
                $answered++;
                if ($answer === self::ANSWER_NO) {
                    $raw++;
                }
            }

            // Нормы даны для обоих полов у каждой шкалы партии; fallback на
            // мужские остаётся только страховкой от неполного определения.
            $norms = $scale['norms'][$sex] ?? $scale['norms']['male'] ?? [];
            $mean = $norms['M'] ?? 0;
            $sigma = $norms['sigma'] ?? 0;

            $t = ((float) $sigma) == 0.0
                ? 50.0
                : round(50 + 10 * ($raw - (float) $mean) / (float) $sigma);
            $t = (float) max(self::T_MIN, min(self::T_MAX, $t));

            $results[$code] = [
                'id' => (string) ($scale['id'] ?? $code),
                'code' => $code,
                'name' => (string) ($scale['name'] ?? $code),
                'raw' => $raw,
                't' => $t,
                'M' => $mean,
                'sigma' => $sigma,
                'max_raw' => (int) ($scale['max_raw'] ?? 0),
                'answered' => $answered,
                'level' => $this->level($t),
                'level_name' => $this->levelName($t),
                'source' => (array) ($scale['source'] ?? []),
                'status' => (string) ($scale['status'] ?? 'unverified'),
                // Оговорка владельца по записи источника (status verified-with-note).
                // Пустая строка у шкал без оговорки: форма результата одинакова.
                'note' => (string) ($scale['note'] ?? ''),
            ];
        }

        return $results;
    }

    /**
     * Ответ на пункт как 0/1, либо null — если пункт пропущен или «не знаю».
     *
     * @param array<int|string, int|bool|string> $answers
     */
    private function answerFor(array $answers, int $questionId): ?int
    {
        if (!isset($answers[$questionId])) {
            return null;
        }

        $answer = $answers[$questionId];
        if (is_bool($answer)) {
            return $answer ? self::ANSWER_YES : self::ANSWER_NO;
        }
        if (!is_numeric($answer)) {
            return null;
        }

        $value = (int) $answer;
        if ($value === self::ANSWER_UNKNOWN) {
            return null;
        }

        return $value === self::ANSWER_YES || $value === self::ANSWER_NO ? $value : null;
    }

    /** Нейтральный уровень без клинических формулировок. */
    private function level(float $t): string
    {
        if ($t >= self::T_HIGH) {
            return 'above';
        }
        if ($t <= self::T_LOW) {
            return 'below';
        }

        return 'normal';
    }

    private function levelName(float $t): string
    {
        return match ($this->level($t)) {
            'above' => 'выше нормы',
            'below' => 'ниже нормы',
            default => 'в пределах нормы',
        };
    }
}
