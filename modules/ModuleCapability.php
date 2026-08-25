<?php

declare(strict_types=1);

namespace PsyTest\Modules;

/**
 * Declarative module capabilities (Module API v2, stage 03).
 *
 * A module declares what it supports; the shared layer must consult
 * capabilities instead of branching on test slugs.
 */
final class ModuleCapability
{
    public const PAIR = 'pair';
    public const CHART = 'chart';
    public const PDF = 'pdf';
    public const PAID_INTERPRETATION = 'paid_interpretation';
    public const CLINICAL_SIGNAL = 'clinical_signal';

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::PAIR,
            self::CHART,
            self::PDF,
            self::PAID_INTERPRETATION,
            self::CLINICAL_SIGNAL,
        ];
    }
}
