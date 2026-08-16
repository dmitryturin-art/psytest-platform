<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\RetentionPolicy;

final class RetentionPolicyTest extends TestCase
{
    public function testAnonymousRetentionDefaultsToTheOwnerApproved180Days(): void
    {
        $policy = new RetentionPolicy();

        self::assertSame(180, $policy->anonymousRetentionDays());
        self::assertSame(
            '2026-02-17 12:00:00',
            $policy->anonymousCutoff(new DateTimeImmutable('2026-08-16 12:00:00'))->format('Y-m-d H:i:s'),
        );
    }

    public function testOnlyExplicitRetentionClassesAreKnown(): void
    {
        self::assertTrue(RetentionPolicy::isKnownClass(RetentionPolicy::ANONYMOUS));
        self::assertTrue(RetentionPolicy::isKnownClass(RetentionPolicy::THERAPIST_CASE));
        self::assertFalse(RetentionPolicy::isKnownClass('visitor_email'));
    }

    public function testRejectsZeroDayRetention(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RetentionPolicy(0);
    }
}
