<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\ClinicalSafetyNotice;
use PsyTest\Core\ClinicalSafetySignal;

final class ClinicalSafetyNoticeTest extends TestCase
{
    public function testBdiItemNineSignalReturnsOnlyTheApprovedGenericMessage(): void
    {
        self::assertSame([
            'message' => ClinicalSafetyNotice::MESSAGE,
        ], ClinicalSafetyNotice::fromResults([
            'safety_signals' => [[
                'code' => ClinicalSafetySignal::BDI_ITEM_NINE,
                'severity' => 3,
                'source' => ['question_id' => 9, 'value' => 3],
            ]],
        ]));
    }

    public function testNoNoticeAppearsWithoutTheBdiItemNineSignal(): void
    {
        self::assertNull(ClinicalSafetyNotice::fromResults([]));
        self::assertNull(ClinicalSafetyNotice::fromResults(['safety_signals' => []]));
        self::assertNull(ClinicalSafetyNotice::fromResults(['safety_signals' => [['code' => 'other']]]));
        self::assertNull(ClinicalSafetyNotice::fromResults(['safety_signals' => 'not-an-array']));
    }

    public function testApprovedMessageContainsNoPublishedContactsOrGeoTargeting(): void
    {
        foreach (['http://', 'https://', 'tel:', 'GeoIP', 'IP-адрес', '+7'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, ClinicalSafetyNotice::MESSAGE);
        }
    }
}
