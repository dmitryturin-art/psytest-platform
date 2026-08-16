<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\ClinicalSafetySignal;

final class ClinicalSafetySignalTest extends TestCase
{
    public function testBdiItemNineDoesNotCreateASignalForZero(): void
    {
        self::assertNull(ClinicalSafetySignal::fromBdiAnswers([9 => 0]));
    }

    public function testBdiItemNineCreatesASeverityPreservingSignalForEveryPositiveAnswer(): void
    {
        foreach ([1, 2, 3] as $score) {
            $signal = ClinicalSafetySignal::fromBdiAnswers(['9' => $score]);

            self::assertNotNull($signal);
            self::assertSame([
                'code' => ClinicalSafetySignal::BDI_ITEM_NINE,
                'severity' => $score,
                'source' => [
                    'question_id' => 9,
                    'value' => $score,
                ],
            ], $signal->toArray());
        }
    }

    public function testMissingOrInvalidInputNeverCreatesASafetySignal(): void
    {
        self::assertNull(ClinicalSafetySignal::fromBdiAnswers([]));
        self::assertNull(ClinicalSafetySignal::fromBdiAnswers([9 => '3']));
        self::assertNull(ClinicalSafetySignal::fromBdiAnswers([9 => 4]));
    }
}
