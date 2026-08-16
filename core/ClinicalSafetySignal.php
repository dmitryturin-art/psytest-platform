<?php

declare(strict_types=1);

namespace PsyTest\Core;

/**
 * Structured, text-free signal extracted after validated test answers.
 *
 * It does not diagnose, select resources, or decide UI wording. Those are
 * separate clinical and presentation concerns.
 */
final class ClinicalSafetySignal
{
    public const BDI_ITEM_NINE = 'bdi_item_9';

    private function __construct(private readonly int $itemScore)
    {
    }

    /** @param array<int|string, mixed> $answers */
    public static function fromBdiAnswers(array $answers): ?self
    {
        $value = $answers[9] ?? $answers['9'] ?? null;
        if (!is_int($value) || $value < 1 || $value > 3) {
            return null;
        }

        return new self($value);
    }

    /** @return array{code: string, severity: int, source: array{question_id: int, value: int}} */
    public function toArray(): array
    {
        return [
            'code' => self::BDI_ITEM_NINE,
            'severity' => $this->itemScore,
            'source' => [
                'question_id' => 9,
                'value' => $this->itemScore,
            ],
        ];
    }
}
