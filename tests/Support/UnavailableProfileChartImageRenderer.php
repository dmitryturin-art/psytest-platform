<?php

declare(strict_types=1);

namespace PsyTest\Tests\Support;

use PsyTest\Modules\ProfileChartImageRenderer;

/**
 * Рендерер профиля в окружении без нужных расширений (например, без GD).
 */
final class UnavailableProfileChartImageRenderer implements ProfileChartImageRenderer
{
    public static bool $renderCalled = false;

    public static function isAvailable(): bool
    {
        return false;
    }

    public function renderPng(array $data): string
    {
        self::$renderCalled = true;

        return '';
    }
}
