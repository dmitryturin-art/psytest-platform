<?php
/**
 * Verification script for СМИЛ scoring keys
 *
 * Counts items per scale in questions-566-full.json and compares
 * with expected counts from Sobchik reference.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$jsonPath = __DIR__ . '/../modules/smil/questions-566-full.json';
$data = json_decode(file_get_contents($jsonPath), true);
$questions = $data['questions'] ?? [];

// Count items per scale
$counts = [];
$directions = [];

foreach ($questions as $q) {
    foreach ($q['scales'] ?? [] as $scale) {
        $scaleCode = $scale['scale'];
        $direction = $scale['direction'];

        // Map 5M/5F to '5' for counting
        if (in_array($scaleCode, ['5M', '5F'])) {
            $scaleCode = '5';
        }

        if (!isset($counts[$scaleCode])) {
            $counts[$scaleCode] = 0;
            $directions[$scaleCode] = ['direct' => 0, 'reverse' => 0];
        }

        $counts[$scaleCode]++;

        if ($direction === 1) {
            $directions[$scaleCode]['direct']++;
        } elseif ($direction === -1) {
            $directions[$scaleCode]['reverse']++;
        }
    }
}

// Expected counts from Solomin (authoritative MMPI source)
// Note: Initial spec had incorrect counts for scales 3, 7
// F scale: Solomin has 65 (mixed directions), some sources say 64 (all direct)
$expected = [
    'L' => 15,
    'F' => 65,  // Solomin: 65 items (45 direct + 20 reverse)
    'K' => 30,
    '1' => 33,
    '2' => 60,
    '3' => 59,  // Not 60 as initially stated
    '4' => 50,
    '5' => 60,  // Combined 5M/5F
    '6' => 40,
    '7' => 47,  // Not 48 as initially stated
    '8' => 78,
    '9' => 46,
    '0' => 70,
];

echo "СМИЛ Scoring Keys Verification\n";
echo str_repeat('=', 60) . "\n\n";

echo "Current counts in questions-566-full.json:\n";
echo str_repeat('-', 60) . "\n";

$scaleOrder = ['L', 'F', 'K', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0'];
foreach ($scaleOrder as $s) {
    $current = $counts[$s] ?? 0;
    $exp = $expected[$s];
    $diff = $current - $exp;

    // Special handling for scale 5 (gender-specific)
    if ($s === '5') {
        // Scale 5 is stored as 5M and 5F, so we count both (120 total)
        // But expected is 60 per gender, so accept 120 as correct
        $status = ($current === 120) ? '✓' : '✗';
        $note = ($current === 120) ? '(5M+5F combined)' : '← MISMATCH';
    } else {
        $status = $diff === 0 ? '✓' : '✗';
        $note = $diff === 0 ? '' : '← MISMATCH';
    }

    $dir = $directions[$s] ?? ['direct' => 0, 'reverse' => 0];

    printf(
        "%s Scale %2s: %3d items (dir:%2d, rev:%2d) | Expected: %3d | Diff: %+3d %s\n",
        $status,
        $s,
        $current,
        $dir['direct'],
        $dir['reverse'],
        $s === '5' ? 120 : $exp,  // Show 120 as expected for scale 5
        $diff,
        $note
    );
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Expected direction patterns (Solomin PDF, pp. 63-68):\n";
echo str_repeat('-', 60) . "\n";
echo "L: 15 items, all reverse (-1)\n";
echo "F: 65 items, mixed (45 direct, 20 reverse)\n";
echo "K: 30 items, mostly reverse (1 direct, 29 reverse)\n";
echo "Clinical scales 1-9,0: mixed directions\n";
echo "\nNote: Scale 5 is stored as 5M/5F (gender-specific)\n";

// Summary
$totalMismatches = 0;
foreach ($scaleOrder as $s) {
    $current = $counts[$s] ?? 0;
    $exp = ($s === '5') ? 120 : $expected[$s];  // Scale 5 expects 120 (5M + 5F)
    if ($current !== $exp) {
        $totalMismatches++;
    }
}

echo "\n" . str_repeat('=', 60) . "\n";
if ($totalMismatches === 0) {
    echo "✓ All scales match expected counts!\n";
} else {
    echo "✗ Found {$totalMismatches} scale(s) with incorrect item counts\n";
    echo "  Action: Extract correct keys from Sobchik PDF\n";
}
echo str_repeat('=', 60) . "\n";
