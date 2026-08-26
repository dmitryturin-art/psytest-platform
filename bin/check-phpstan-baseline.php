<?php

declare(strict_types=1);

/**
 * Prevents accidental expansion of the temporary PHPStan baseline.
 *
 * The baseline is deliberately capped while existing type issues are removed
 * incrementally. Raise the limit only in a reviewed, documented decision.
 */

$projectRoot = dirname(__DIR__);
$baselinePath = $projectRoot . '/phpstan-baseline.neon';
$expectedEntryCount = 147;

if (!is_file($baselinePath)) {
    fwrite(STDERR, "PHPStan baseline is missing: {$baselinePath}\n");
    exit(1);
}

$contents = file_get_contents($baselinePath);

if ($contents === false) {
    fwrite(STDERR, "Cannot read PHPStan baseline: {$baselinePath}\n");
    exit(1);
}

$messageCount = preg_match_all('/^\s*message:\s*\S/m', $contents);
$countMatches = [];
$countEntryCount = preg_match_all('/^\s*count:\s*(\d+)\s*$/m', $contents, $countMatches);
$pathCount = preg_match_all('/^\s*path:\s*\S/m', $contents);

$hasInvalidCount = false;

foreach ($countMatches[1] ?? [] as $count) {
    if ((int) $count < 1) {
        $hasInvalidCount = true;
        break;
    }
}

if (
    $messageCount !== $expectedEntryCount
    || $countEntryCount !== $expectedEntryCount
    || $pathCount !== $expectedEntryCount
    || $hasInvalidCount
) {
    fwrite(
        STDERR,
        sprintf(
            "PHPStan baseline integrity check failed: expected %d complete entries; found messages=%d, counts=%d, paths=%d.\n",
            $expectedEntryCount,
            $messageCount,
            $countEntryCount,
            $pathCount,
        ),
    );
    exit(1);
}

printf("PHPStan baseline integrity check passed: %d entries (cap %d).\n", $messageCount, $expectedEntryCount);
