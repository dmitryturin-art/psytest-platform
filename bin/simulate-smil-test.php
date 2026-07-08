<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PsyTest\Modules\Smil\SmilModule;
use PsyTest\Modules\BeckDepression\BeckDepressionModule;
use PsyTest\Modules\Hads\HadsModule;

const GREEN = "\033[32m";
const RED = "\033[31m";
const YELLOW = "\033[33m";
const CYAN = "\033[36m";
const BOLD = "\033[1m";
const DIM = "\033[2m";
const RESET = "\033[0m";

$rootDir = dirname(__DIR__);
$fixturesDir = $rootDir . '/tests/fixtures';
$allPass = true;

function pass(string $msg): void
{
    echo GREEN . "  PASS" . RESET . " $msg\n";
}

function fail(string $msg): void
{
    global $allPass;
    $allPass = false;
    echo RED . "  FAIL" . RESET . " $msg\n";
}

function warn(string $msg): void
{
    echo YELLOW . "  WARN" . RESET . " $msg\n";
}

function printHeader(string $msg): void
{
    echo "\n" . BOLD . CYAN . "═══ $msg ═══" . RESET . "\n";
}

function subheader(string $msg): void
{
    echo "\n" . BOLD . "$msg" . RESET . "\n";
}

function dim(string $msg): void
{
    echo DIM . $msg . RESET . "\n";
}

// ============================================================
// 1. SMIL TEST
// ============================================================

printHeader("SMIL/MMPI-566 Test Simulation");

$answersFile = $fixturesDir . '/smil-reference-answers.json';
$refScoresFile = $fixturesDir . '/smil-reference-scores.json';
$refAdditionalFile = $fixturesDir . '/smil-additional-reference-scores.json';

if (!file_exists($answersFile)) {
    echo RED . "ERROR: Missing $answersFile" . RESET . "\n";
    exit(1);
}
if (!file_exists($refScoresFile)) {
    echo RED . "ERROR: Missing $refScoresFile" . RESET . "\n";
    exit(1);
}

$answers = json_decode(file_get_contents($answersFile), true);
$refScores = json_decode(file_get_contents($refScoresFile), true);
$refAdditional = file_exists($refAdditionalFile)
    ? json_decode(file_get_contents($refAdditionalFile), true)
    : null;

dim("Loaded " . count(array_filter($answers, fn ($k) => is_numeric($k), ARRAY_FILTER_USE_KEY)) . " answers (gender: " . ($answers['gender'] ?? 'N/A') . ")");

try {
    $smil = new SmilModule();
} catch (\Throwable $e) {
    echo RED . "ERROR: Failed to initialize SmilModule: " . $e->getMessage() . RESET . "\n";
    exit(1);
}

$results = $smil->calculateResults($answers);

// --- Gender & Completion ---
subheader("Basic Info");
echo "  Gender:          " . BOLD . $results['gender'] . RESET . "\n";
echo "  Answered:        " . $results['answered_count'] . " / " . $results['total_questions'] . "\n";
echo "  Completion:      " . $results['completion_rate'] . "%\n";

// --- All 13 Basic Scales ---
subheader("13 Basic Scales");

$scaleOrder = ['L', 'F', 'K', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0'];
$scaleNames = [
    'L' => 'Lie (L)',
    'F' => 'Infrequency (F)',
    'K' => 'Correction (K)',
    '1' => 'Hypochondriasis (Hs)',
    '2' => 'Depression (D)',
    '3' => 'Hysteria (Hy)',
    '4' => 'Psychopathic Deviate (Pd)',
    '5' => 'Masculinity/Femininity (Mf)',
    '6' => 'Paranoia (Pa)',
    '7' => 'Psychasthenia (Pt)',
    '8' => 'Schizophrenia (Sc)',
    '9' => 'Hypomania (Ma)',
    '0' => 'Social Introversion (Si)',
];

$rawScores = $results['raw_scores'];
$tScores = $results['t_scores'];

$kCorrections = [
    '1' => 0.5, '3' => 0.3, '4' => 0.4, '6' => 0.3,
    '7' => 1.0, '8' => 0.2, '9' => 0.2,
];

printf("  %-4s %-32s %6s %10s %8s %10s\n", "Code", "Name", "Raw", "Corrected", "T-score", "Level");
echo "  " . str_repeat("─", 82) . "\n";

foreach ($scaleOrder as $code) {
    $raw = $rawScores[$code] ?? 0;
    $t = $tScores[$code] ?? 50;
    $K = $rawScores['K'] ?? 0;

    if (isset($kCorrections[$code])) {
        $correction = round($K * $kCorrections[$code]);
    } else {
        $correction = 0;
    }
    $correctedRaw = $raw + $correction;

    $level = 'normal';
    if ($t >= 75) $level = 'very_high';
    elseif ($t >= 65) $level = 'high';
    elseif ($t >= 55) $level = 'elevated';
    elseif ($t < 45) $level = 'low';

    $levelLabels = [
        'low' => 'Low',
        'normal' => 'Normal',
        'elevated' => 'Elevated',
        'high' => 'High',
        'very_high' => 'Very High',
    ];

    $color = $level === 'normal' ? GREEN : ($level === 'low' ? DIM : YELLOW);
    printf(
        "  %-4s %-32s %6d %10d " . $color . "%8d %-10s" . RESET . "\n",
        $code,
        $scaleNames[$code] ?? $code,
        $raw,
        $correctedRaw,
        (int) $t,
        $levelLabels[$level] ?? $level
    );
}

// --- Validity ---
subheader("Validity Assessment");

$validity = $results['validity'];
echo "  L T-score:  " . ($validity['L_score'] ?? $tScores['L'] ?? '?') . "\n";
echo "  F T-score:  " . ($validity['F_score'] ?? $tScores['F'] ?? '?') . "\n";
echo "  K T-score:  " . ($validity['K_score'] ?? $tScores['K'] ?? '?') . "\n";
echo "  FK Index:   " . ($results['indices']['FK_index'] ?? '?') . "\n";
echo "  FK Ratio:   " . ($results['indices']['FK_ratio'] ?? '?') . "\n";
echo "  Unknown:    " . ($validity['unknown_count'] ?? 0) . "\n";
echo "  Valid:      " . ($validity['is_valid'] ? GREEN . "YES" : RED . "NO") . RESET . "\n";

if (!empty($validity['warnings'])) {
    echo "  Warnings:\n";
    foreach ($validity['warnings'] as $w) {
        echo "    - " . YELLOW . $w . RESET . "\n";
    }
}

// --- Profile Type ---
subheader("Profile Type");
$profile = $results['profile'];
$profileType = $profile['profile_type'] ?? 'unknown';
$codeType = $profile['code_type'] ?? '';

$typeNames = [
    'normosthenic' => 'Normosthenic',
    'neurotic' => 'Neurotic',
    'psychotic' => 'Psychotic',
    'personal_deviation' => 'Personal Deviation',
    'mixed' => 'Mixed',
];

echo "  Profile Type: " . BOLD . ($typeNames[$profileType] ?? $profileType) . RESET . "\n";
echo "  Code Type:    " . BOLD . $codeType . RESET . "\n";

if (!empty($profile['dominant'])) {
    echo "  Dominant Scales:\n";
    foreach ($profile['dominant'] as $d) {
        $nameEn = $d['name'] ?? '';
        echo "    - " . $nameEn . " (T=" . (int)($d['score'] ?? 0) . ")\n";
    }
}

// --- Indices ---
subheader("Indices");
$indices = $results['indices'];
$indexLabels = [
    'FK_index' => 'F-K Index',
    'FK_ratio' => 'F-K Ratio',
    'anxiety_index' => 'Anxiety Index',
    'depression_index' => 'Depression Index',
];
foreach ($indexLabels as $key => $label) {
    echo "  $label: " . ($indices[$key] ?? '?') . "\n";
}

// --- Additional Scales ---
subheader("Additional Scales");
$additionalScores = $results['additional_scores'];
$categoryNames = [
    'factor' => 'Factor Scales',
    'special' => 'Special Scales',
    'content' => 'Content Scales',
];

if (empty($additionalScores)) {
    echo "  " . DIM . "(no additional scales returned)" . RESET . "\n";
} else {
    $grouped = [];
    foreach ($additionalScores as $code => $scoreData) {
        $cat = $scoreData['category'] ?? 'content';
        $grouped[$cat][$code] = $scoreData;
    }

    foreach ($categoryNames as $cat => $catLabel) {
        $items = $grouped[$cat] ?? [];
        if (empty($items)) continue;

        echo "\n  " . BOLD . "$catLabel (" . count($items) . " scales)" . RESET . "\n";
        printf("    %-6s %-30s %8s %8s\n", "Code", "Name", "Raw", "T-score");
        echo "    " . str_repeat("─", 56) . "\n";

        foreach ($items as $code => $sd) {
            $raw = $sd['raw'] ?? 0;
            $t = $sd['t'] ?? 50;
            $name = $sd['name'] ?? $code;
            printf("    %-6s %-30s %8d %8d\n", $code, $name, $raw, (int) $t);
        }
    }
}

// --- Validate against reference ---
subheader("Validation: Basic Scale T-scores vs Reference (tolerance: ±2)");

$refT = $refScores['t'] ?? [];
$smilPass = true;

foreach ($scaleOrder as $code) {
    if (!isset($refT[$code])) {
        warn("Scale $code: no reference value, skipping");
        continue;
    }

    $expected = (float) $refT[$code];
    $actual = (float) ($tScores[$code] ?? 50);
    $diff = abs($actual - $expected);

    if ($diff <= 2) {
        pass("Scale $code: expected T=$expected, got T=" . (int)$actual . " (diff=$diff)");
    } else {
        $smilPass = false;
        fail("Scale $code: expected T=$expected, got T=" . (int)$actual . " (diff=$diff, exceeds ±2)");
    }
}

if (isset($refScores['bug'])) {
    echo "\n  " . DIM . "Note from reference: " . $refScores['bug'] . RESET . "\n";
}

// --- Validate additional scales ---
if ($refAdditional !== null && !empty($additionalScores)) {
    subheader("Validation: Additional Scale T-scores vs Reference (tolerance: ±2)");

    // Support both old format (additional_scales[cat][code]) and new flat format (scales[code])
    $refAdditionalScales = $refAdditional['scales'] ?? [];
    if (empty($refAdditionalScales) && isset($refAdditional['additional_scales'])) {
        foreach ($refAdditional['additional_scales'] as $cat => $scales) {
            foreach ($scales as $code => $data) {
                $refAdditionalScales[$code] = $data;
            }
        }
    }

    foreach ($refAdditionalScales as $code => $refData) {
        $refT = (float) ($refData['t'] ?? $refData['t_score'] ?? 50);
        $actualData = $additionalScores[$code] ?? null;

        if ($actualData === null) {
            warn("$code: not found in results, skipping");
            continue;
        }

        $actualT = (float) ($actualData['t'] ?? 50);
        $diff = abs($actualT - $refT);

        $name = $refData['name'] ?? $code;
        if ($diff <= 2) {
            pass("$name ($code): expected T=$refT, got T=" . (int)$actualT . " (diff=$diff)");
        } else {
            $smilPass = false;
            fail("$name ($code): expected T=$refT, got T=" . (int)$actualT . " (diff=$diff, exceeds ±2)");
        }
    }
}

// ============================================================
// 2. BDI TEST
// ============================================================

printHeader("BDI-II Test Simulation");

$bdiRefFile = $fixturesDir . '/bdi-reference-results.json';

if (file_exists($bdiRefFile)) {
    $bdiRef = json_decode(file_get_contents($bdiRefFile), true);

    try {
        $bdiModule = new BeckDepressionModule();
    } catch (\Throwable $e) {
        echo RED . "ERROR: Failed to initialize BeckDepressionModule: " . $e->getMessage() . RESET . "\n";
        $allPass = false;
    }

    if (isset($bdiModule)) {
        $bdiAnswers = $bdiRef['answers'] ?? [];
        $bdiResults = $bdiModule->calculateResults($bdiAnswers);

        dim("BDI results calculated from " . count($bdiAnswers) . " answers");

        echo "  Total Score:      " . $bdiResults['total_score'] . " / " . $bdiResults['max_score'] . "\n";
        echo "  Percentage:       " . $bdiResults['percentage'] . "%\n";
        echo "  Severity:         " . $bdiResults['level'] . "\n";
        echo "  Level Name:       " . $bdiResults['level_name'] . "\n";
        echo "  Answered:         " . $bdiResults['answered_count'] . " / " . $bdiResults['total_questions'] . "\n";

        $refRaw = $bdiRef['raw_score'] ?? null;
        $refSeverity = $bdiRef['severity'] ?? null;

        subheader("Validation: BDI");

        if ($refRaw !== null) {
            $actual = $bdiResults['total_score'] ?? 0;
            if ($actual === $refRaw) {
                pass("BDI raw score: expected $refRaw, got $actual");
            } else {
                fail("BDI raw score: expected $refRaw, got $actual");
            }
        }

        if ($refSeverity !== null) {
            $actual = $bdiResults['level'] ?? '';
            if ($actual === $refSeverity) {
                pass("BDI severity: expected '$refSeverity', got '$actual'");
            } else {
                fail("BDI severity: expected '$refSeverity', got '$actual'");
            }
        }

        if ($refRaw === null && $refSeverity === null) {
            warn("No reference raw_score or severity in fixture");
        }
    }
} else {
    echo "  " . DIM . "No BDI reference fixture found ($bdiRefFile), skipping" . RESET . "\n";
}

// ============================================================
// 3. HADS TEST
// ============================================================

printHeader("HADS Test Simulation");

$hadsRefFile = $fixturesDir . '/hads-reference-results.json';

if (file_exists($hadsRefFile)) {
    $hadsRef = json_decode(file_get_contents($hadsRefFile), true);

    try {
        $hadsModule = new HadsModule();
    } catch (\Throwable $e) {
        echo RED . "ERROR: Failed to initialize HadsModule: " . $e->getMessage() . RESET . "\n";
        $allPass = false;
    }

    if (isset($hadsModule)) {
        $hadsAnswers = $hadsRef['answers'] ?? [];
        $hadsResults = $hadsModule->calculateResults($hadsAnswers);

        dim("HADS results calculated from " . count($hadsAnswers) . " answers");

        echo "  Total Score:      " . $hadsResults['total_score'] . " / 42\n";
        echo "  Anxiety:          " . $hadsResults['anxiety_score'] . " / 21 (" . $hadsResults['anxiety_level_name'] . ")\n";
        echo "  Depression:       " . $hadsResults['depression_score'] . " / 21 (" . $hadsResults['depression_level_name'] . ")\n";
        echo "  Answered:         " . $hadsResults['answered_count'] . " / " . $hadsResults['total_questions'] . "\n";

        $refSubscales = $hadsRef['subscale_scores'] ?? [];

        subheader("Validation: HADS");

        $refAnxietyScore = $refSubscales['anxiety']['raw_score'] ?? null;
        $refDepressionScore = $refSubscales['depression']['raw_score'] ?? null;
        $refAnxietySeverity = $refSubscales['anxiety']['severity'] ?? null;
        $refDepressionSeverity = $refSubscales['depression']['severity'] ?? null;

        // Severity mapping from reference to our levels
        $severityMap = [
            'normal' => 'normal',
            'subclinical' => 'subclinical',
            'clinical' => 'clinical',
            'moderate_to_severe' => 'clinical',
        ];

        if ($refAnxietyScore !== null) {
            $actual = $hadsResults['anxiety_score'] ?? 0;
            if ($actual === $refAnxietyScore) {
                pass("HADS anxiety score: expected $refAnxietyScore, got $actual");
            } else {
                fail("HADS anxiety score: expected $refAnxietyScore, got $actual");
            }
        }

        if ($refDepressionScore !== null) {
            $actual = $hadsResults['depression_score'] ?? 0;
            if ($actual === $refDepressionScore) {
                pass("HADS depression score: expected $refDepressionScore, got $actual");
            } else {
                fail("HADS depression score: expected $refDepressionScore, got $actual");
            }
        }

        if ($refAnxietySeverity !== null) {
            $expectedLevel = $severityMap[$refAnxietySeverity] ?? $refAnxietySeverity;
            $actual = $hadsResults['anxiety_level'] ?? '';
            if ($actual === $expectedLevel) {
                pass("HADS anxiety level: expected '$expectedLevel', got '$actual'");
            } else {
                fail("HADS anxiety level: expected '$expectedLevel' (ref: '$refAnxietySeverity'), got '$actual'");
            }
        }

        if ($refDepressionSeverity !== null) {
            $expectedLevel = $severityMap[$refDepressionSeverity] ?? $refDepressionSeverity;
            $actual = $hadsResults['depression_level'] ?? '';
            if ($actual === $expectedLevel) {
                pass("HADS depression level: expected '$expectedLevel', got '$actual'");
            } else {
                fail("HADS depression level: expected '$expectedLevel' (ref: '$refDepressionSeverity'), got '$actual'");
            }
        }

        $refTotal = $hadsRef['total_score'] ?? null;
        if ($refTotal !== null) {
            $actual = $hadsResults['total_score'] ?? 0;
            if ($actual === $refTotal) {
                pass("HADS total score: expected $refTotal, got $actual");
            } else {
                fail("HADS total score: expected $refTotal, got $actual");
            }
        }
    }
} else {
    echo "  " . DIM . "No HADS reference fixture found ($hadsRefFile), skipping" . RESET . "\n";
}

// ============================================================
// FINAL SUMMARY
// ============================================================

echo "\n" . BOLD . "═══════════════════════════════════════" . RESET . "\n";
if ($allPass) {
    echo GREEN . BOLD . "  ALL COMPARISONS PASSED" . RESET . "\n";
} else {
    echo RED . BOLD . "  SOME COMPARISONS FAILED" . RESET . "\n";
}
echo BOLD . "═══════════════════════════════════════" . RESET . "\n\n";

exit($allPass ? 0 : 1);
