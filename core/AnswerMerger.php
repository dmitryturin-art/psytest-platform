<?php

declare(strict_types=1);

namespace PsyTest\Core;

/**
 * Combines saved and form-submitted answers without changing question IDs.
 *
 * PHP's array_merge() renumbers integer keys, but test question identifiers
 * are numeric (for example, BDI uses 1 through 21). array_replace() keeps
 * those identifiers intact while allowing the newer submitted values to win.
 */
final class AnswerMerger
{
    /**
     * @param array<int|string, mixed> $saved
     * @param array<int|string, mixed> $submitted
     * @return array<int|string, mixed>
     */
    public static function overlay(array $saved, array $submitted): array
    {
        return array_replace($saved, $submitted);
    }
}
