<?php

declare(strict_types=1);

namespace PsyTest\Tests\Support;

use PsyTest\Modules\ProfileChartImageRenderer;

/**
 * Растровый рендерер профиля, который всегда падает.
 *
 * Проверяет откат PDF к статической HTML-подмене: сбой картинки не должен
 * ронять документ (07.WP7b).
 */
final class FailingProfileChartImageRenderer implements ProfileChartImageRenderer
{
    public static function isAvailable(): bool
    {
        return true;
    }

    public function renderPng(array $data): string
    {
        throw new \RuntimeException('synthetic renderer failure');
    }
}
