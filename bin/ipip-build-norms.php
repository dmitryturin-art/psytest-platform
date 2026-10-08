<?php

/**
 * Перцентильные нормы IPIP-NEO-120 из открытых данных Johnson (09.O1).
 *
 * Источник: Johnson's IPIP-NEO data repository, OSF https://osf.io/tbmh5/,
 * компонент https://osf.io/wxvth/ — файл IPIP120.dat (619 150 анкет,
 * англоязычная интернет-версия 2001–2011) и кодбук DAT120.doc.
 * Статья: Johnson, J. A. (2014). Measuring thirty facets of the Five Factor
 * Model with a 120-item public domain inventory. Journal of Research in
 * Personality, 51, 78–89. https://doi.org/10.1016/j.jrp.2014.05.003
 *
 * По кодбуку обратные пункты в файле уже перекодированы (1=5, 2=4, 4=2,
 * 5=1) в момент прохождения, поэтому баллы шкал — просто суммы. Скрипт
 * проверяет это и на самих данных: корреляции пункта с остатком своей
 * грани считаются для «как в файле» и для «перекодировать ещё раз».
 *
 * Сырой файл в Git не попадает (`.gitignore`), в репозиторий идёт только
 * агрегат — `modules/ipip-neo-120/norms.json`.
 *
 * Запуск:
 *   php bin/ipip-build-norms.php --download            # скачать в storage/ipip-raw/
 *   php bin/ipip-build-norms.php --input=path/IPIP120.dat [--output=...] [--report=...]
 */

declare(strict_types=1);

const IPIP_DATA_URL = 'https://osf.io/download/q9jrh/';
const IPIP_DATA_SHA256 = '526daf7ebe7d480ba71258cd20f0fc3b37ab3a43a2e4191c19a49e185a1df53b';
const IPIP_ITEMS = 120;
const IPIP_ITEM_OFFSET = 31; // I1 начинается с 32-й колонки (кодбук DAT120.doc)
const IPIP_RUSSIA = 'Russian F'; // COUNTRY обрезан до 9 символов: «Russian Federation»
const IPIP_USA = 'USA';

/** Возрастные группы взрослых: решение владельца 08.10.2026. */
const IPIP_AGE_GROUPS = [
    ['key' => '18_24', 'min' => 18, 'max' => 24, 'label' => '18–24'],
    ['key' => '25_34', 'min' => 25, 'max' => 34, 'label' => '25–34'],
    ['key' => '35_49', 'min' => 35, 'max' => 49, 'label' => '35–49'],
    ['key' => '50_plus', 'min' => 50, 'max' => 99, 'label' => '50 и старше'],
];

$root = dirname(__DIR__);
$options = getopt('', ['input:', 'output:', 'report:', 'download', 'downloaded-on:']);

$input = is_string($options['input'] ?? null) ? $options['input'] : $root . '/storage/ipip-raw/IPIP120.dat';
$output = is_string($options['output'] ?? null) ? $options['output'] : $root . '/modules/ipip-neo-120/norms.json';
$report = is_string($options['report'] ?? null) ? $options['report'] : null;

if (isset($options['download'])) {
    $dir = dirname($input);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Не удалось создать {$dir}\n");
        exit(1);
    }
    fwrite(STDERR, 'Скачиваю ' . IPIP_DATA_URL . " → {$input}\n");
    $in = fopen(IPIP_DATA_URL, 'rb');
    $out = fopen($input, 'wb');
    if ($in === false || $out === false || stream_copy_to_stream($in, $out) === false) {
        fwrite(STDERR, "Скачивание не удалось\n");
        exit(1);
    }
    fclose($in);
    fclose($out);
}

if (!is_file($input)) {
    fwrite(STDERR, "Нет файла данных {$input}; запустите с --download или --input=...\n");
    exit(1);
}

$sha = hash_file('sha256', $input);
if ($sha !== IPIP_DATA_SHA256) {
    fwrite(STDERR, "SHA-256 файла не совпадает с OSF ({$sha}); нормы не строятся\n");
    exit(1);
}

$questions = json_decode((string) file_get_contents($root . '/modules/ipip-neo-120/questions.json'), true)['questions'] ?? [];
if (count($questions) !== IPIP_ITEMS) {
    fwrite(STDERR, "questions.json должен содержать 120 пунктов\n");
    exit(1);
}

/** @var array<int, array{facet: string, domain: string, minus: bool}> $key */
$key = [];
$facets = [];
$domains = [];
foreach ($questions as $q) {
    $key[(int) $q['id'] - 1] = ['facet' => $q['facet'], 'domain' => $q['domain'], 'minus' => $q['keyed'] === '-'];
    $facets[$q['facet']][] = (int) $q['id'] - 1;
    $domains[$q['domain']][] = (int) $q['id'] - 1;
}
ksort($facets);
$domainOrder = ['N', 'E', 'O', 'A', 'C'];
$facetOrder = [];
foreach ($domainOrder as $d) {
    for ($i = 1; $i <= 6; $i++) {
        $facetOrder[] = $d . $i;
    }
}
$scales = array_merge($domainOrder, $facetOrder);
$scaleItems = [];
foreach ($domainOrder as $d) {
    $scaleItems[$d] = $domains[$d];
}
foreach ($facetOrder as $f) {
    $scaleItems[$f] = $facets[$f];
}

$cellDefs = [];
foreach (['male' => 'мужчины', 'female' => 'женщины'] as $sex => $sexLabel) {
    foreach (IPIP_AGE_GROUPS as $group) {
        $cellDefs[$sex . '_' . $group['key']] = ['label' => $sexLabel . ' ' . $group['label'], 'sex' => $sex, 'age_min' => $group['min'], 'age_max' => $group['max']];
    }
    $cellDefs[$sex . '_all'] = ['label' => $sexLabel . ', все взрослые', 'sex' => $sex, 'age_min' => 18, 'age_max' => 99];
}
$cellDefs['all'] = ['label' => 'все взрослые', 'sex' => null, 'age_min' => 18, 'age_max' => 99];

/** @var array<string, array<string, array<int, int>>> $hist */
$hist = [];
foreach (array_keys($cellDefs) as $cell) {
    foreach ($scales as $scale) {
        $hist[$cell][$scale] = [];
    }
}

$moments = static fn (): array => ['n' => 0, 's' => array_fill_keys($scales, 0.0), 'q' => array_fill_keys($scales, 0.0)];
$groups = ['all' => $moments(), 'russia' => $moments(), 'usa' => $moments()];
$russiaCells = [];

// Проверка направления ключа: суммы пунктов и произведений с остатком грани
// для двух гипотез — «уже перекодировано» (как есть) и «перекодировать».
$itemSum = array_fill(0, IPIP_ITEMS, 0.0);
$itemSq = array_fill(0, IPIP_ITEMS, 0.0);
$hyp = [];
foreach (['as_is', 'recode'] as $h) {
    $hyp[$h] = ['xs' => array_fill(0, IPIP_ITEMS, 0.0), 'fs' => array_fill_keys($facetOrder, 0.0), 'fq' => array_fill_keys($facetOrder, 0.0), 'xi' => array_fill(0, IPIP_ITEMS, 0.0), 'xiq' => array_fill(0, IPIP_ITEMS, 0.0)];
}
$scaleSq = array_fill_keys($scales, 0.0);
$scaleSum = array_fill_keys($scales, 0.0);

$counts = ['records' => 0, 'incomplete' => 0, 'sex_unknown' => 0, 'under_18' => 0, 'kept' => 0];
$countries = [];

$fh = fopen($input, 'rb');
if ($fh === false) {
    fwrite(STDERR, "Не удалось открыть {$input}\n");
    exit(1);
}
while (($line = fgets($fh)) !== false) {
    $line = rtrim($line, "\r\n");
    if ($line === '') {
        continue;
    }
    $counts['records']++;
    $answers = substr($line, IPIP_ITEM_OFFSET, IPIP_ITEMS);
    if (strlen($answers) !== IPIP_ITEMS || strpbrk($answers, '0 ') !== false || preg_match('/\A[1-5]{120}\z/', $answers) !== 1) {
        $counts['incomplete']++;
        continue;
    }
    $sexCode = $line[6];
    if ($sexCode !== '1' && $sexCode !== '2') {
        $counts['sex_unknown']++;
        continue;
    }
    $age = (int) trim(substr($line, 7, 2));
    if ($age < 18) {
        $counts['under_18']++;
        continue;
    }
    $counts['kept']++;
    $sex = $sexCode === '1' ? 'male' : 'female';
    $country = trim(substr($line, 22, 9));
    $countries[$country] = ($countries[$country] ?? 0) + 1;

    $x = array_map('intval', str_split($answers));
    $score = [];
    foreach ($scaleItems as $scale => $items) {
        $sum = 0;
        foreach ($items as $i) {
            $sum += $x[$i];
        }
        $score[$scale] = $sum;
    }

    $ageKey = null;
    foreach (IPIP_AGE_GROUPS as $group) {
        if ($age >= $group['min'] && $age <= $group['max']) {
            $ageKey = $group['key'];
        }
    }
    foreach ([$sex . '_' . $ageKey, $sex . '_all', 'all'] as $cell) {
        foreach ($score as $scale => $value) {
            $hist[$cell][$scale][$value] = ($hist[$cell][$scale][$value] ?? 0) + 1;
        }
    }

    $targets = ['all'];
    if ($country === IPIP_RUSSIA) {
        $targets[] = 'russia';
        $rc = $sex . '_' . $ageKey;
        $russiaCells[$rc] = ($russiaCells[$rc] ?? 0) + 1;
    }
    if ($country === IPIP_USA) {
        $targets[] = 'usa';
    }
    foreach ($targets as $t) {
        $groups[$t]['n']++;
        foreach ($score as $scale => $value) {
            $groups[$t]['s'][$scale] += $value;
            $groups[$t]['q'][$scale] += $value * $value;
        }
    }

    foreach ($score as $scale => $value) {
        $scaleSum[$scale] += $value;
        $scaleSq[$scale] += $value * $value;
    }
    for ($i = 0; $i < IPIP_ITEMS; $i++) {
        $itemSum[$i] += $x[$i];
        $itemSq[$i] += $x[$i] * $x[$i];
    }
    foreach (['as_is', 'recode'] as $h) {
        $y = $x;
        if ($h === 'recode') {
            foreach ($key as $i => $k) {
                if ($k['minus']) {
                    $y[$i] = 6 - $y[$i];
                }
            }
        }
        foreach ($facetOrder as $f) {
            $s = 0;
            foreach ($facets[$f] as $i) {
                $s += $y[$i];
            }
            $hyp[$h]['fs'][$f] += $s;
            $hyp[$h]['fq'][$f] += $s * $s;
            foreach ($facets[$f] as $i) {
                $hyp[$h]['xs'][$i] += $y[$i] * $s;
                $hyp[$h]['xi'][$i] += $y[$i];
                $hyp[$h]['xiq'][$i] += $y[$i] * $y[$i];
            }
        }
    }
}
fclose($fh);

$n = $counts['kept'];
if ($n === 0) {
    fwrite(STDERR, "Ни одной подходящей анкеты\n");
    exit(1);
}

/** @return array{min: int, max: int} */
$range = static fn (string $scale): array => strlen($scale) === 1 ? ['min' => 24, 'max' => 120] : ['min' => 4, 'max' => 20];

$cells = [];
foreach ($cellDefs as $cell => $def) {
    $cellN = array_sum($hist[$cell]['N']);
    $out = $def + ['n' => $cellN, 'scales' => []];
    foreach ($scales as $scale) {
        ['min' => $min, 'max' => $max] = $range($scale);
        $h = $hist[$cell][$scale];
        $below = 0;
        $sum = 0.0;
        $sq = 0.0;
        $percentiles = [];
        for ($v = $min; $v <= $max; $v++) {
            $eq = $h[$v] ?? 0;
            $pr = $cellN > 0 ? 100 * ($below + 0.5 * $eq) / $cellN : 50.0;
            $percentiles[] = max(1, min(99, (int) round($pr)));
            $below += $eq;
            $sum += $v * $eq;
            $sq += $v * $v * $eq;
        }
        $mean = $cellN > 0 ? $sum / $cellN : 0.0;
        $sd = $cellN > 1 ? sqrt(max(0.0, ($sq - $cellN * $mean * $mean) / ($cellN - 1))) : 0.0;
        $out['scales'][$scale] = [
            'min' => $min,
            'max' => $max,
            'mean' => round($mean, 2),
            'sd' => round($sd, 2),
            'percentiles' => $percentiles,
        ];
    }
    $cells[$cell] = $out;
}

$downloadedOn = is_string($options['downloaded-on'] ?? null) ? $options['downloaded-on'] : date('Y-m-d', (int) filemtime($input));
$norms = [
    'schema' => 1,
    'instrument' => 'ipip-neo-120',
    'label' => 'Нормы: международная выборка IPIP-NEO (Johnson, 2014), N = ' . number_format($n, 0, ',', "\u{00A0}") . ', по полу и возрасту; на русской выборке не проверено. Перевод PsyTest, не валидирован.',
    'source' => [
        'citation' => 'Johnson, J. A. (2014). Measuring thirty facets of the Five Factor Model with a 120-item public domain inventory: Development of the IPIP-NEO-120. Journal of Research in Personality, 51, 78–89.',
        'doi' => 'https://doi.org/10.1016/j.jrp.2014.05.003',
        'repository' => 'https://osf.io/tbmh5/',
        'component' => 'https://osf.io/wxvth/',
        'file' => 'IPIP120.dat',
        'file_url' => IPIP_DATA_URL,
        'file_sha256' => IPIP_DATA_SHA256,
        'codebook' => 'DAT120.doc (https://osf.io/download/hgm7n/): SEX 1 = male, 2 = female; items 1–5, missing = 0; reverse-keyed items already recoded at collection',
        'downloaded_on' => $downloadedOn,
    ],
    'method' => [
        'filters' => [
            'complete protocols only (no item coded 0)',
            'SEX 1 or 2',
            'AGE 18–99',
            'all countries (international sample)',
        ],
        'scores' => 'facet = sum of 4 items (4–20), domain = sum of 24 items (24–120); file values summed as stored (reverse items pre-recoded)',
        'percentile' => 'mid-rank: 100 × (count below + 0.5 × count equal) / n, rounded to integer, bounded 1–99',
        'cells' => 'sex × age 18–24, 25–34, 35–49, 50+; sex × all adults (age unknown); all adults (sex unknown)',
        'bands' => 'percentile ≤ 30 — ниже, чем у большинства; 31–69 — как у большинства; ≥ 70 — выше, чем у большинства',
    ],
    'counts' => $counts,
    'cells' => $cells,
];

$json = (string) json_encode($norms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
// Таблица перцентилей — одной строкой на шкалу: файл читается и сравнивается в diff.
$json = (string) preg_replace_callback(
    '/\[\s+(\d+(?:,\s+\d+)*)\s+\]/',
    static fn (array $m): string => '[' . preg_replace('/,\s+/', ', ', $m[1]) . ']',
    $json,
);
file_put_contents($output, $json . "\n");
fwrite(STDERR, "Нормы записаны: {$output} (N = {$n})\n");

if ($report === null) {
    exit(0);
}

// ---- Отчёт для docs/ipip-norms-review.md ----
$stat = static function (array $g, string $scale): array {
    $m = $g['n'] > 0 ? $g['s'][$scale] / $g['n'] : 0.0;
    $sd = $g['n'] > 1 ? sqrt(max(0.0, ($g['q'][$scale] - $g['n'] * $m * $m) / ($g['n'] - 1))) : 0.0;

    return [$m, $sd];
};

$md = [];
$md[] = '<!-- generated by bin/ipip-build-norms.php --report; data SHA-256 ' . IPIP_DATA_SHA256 . ' -->';
$md[] = '';
$md[] = '### Отбор анкет';
$md[] = '';
$md[] = '| Шаг | Анкет |';
$md[] = '|---|---|';
$md[] = '| Всего строк в IPIP120.dat | ' . $counts['records'] . ' |';
$md[] = '| Исключены: есть пропуск (0) | ' . $counts['incomplete'] . ' |';
$md[] = '| Исключены: пол не указан | ' . $counts['sex_unknown'] . ' |';
$md[] = '| Исключены: моложе 18 | ' . $counts['under_18'] . ' |';
$md[] = '| **В нормах (взрослые, полные анкеты)** | **' . $n . '** |';
$md[] = '';
$md[] = '### N по ячейкам';
$md[] = '';
$md[] = '| Ячейка | N | Россия (COUNTRY = «Russian F») |';
$md[] = '|---|---|---|';
foreach ($cells as $cell => $c) {
    $md[] = '| ' . $c['label'] . ' | ' . $c['n'] . ' | ' . ($russiaCells[$cell] ?? ($cell === 'all' ? $groups['russia']['n'] : '—')) . ' |';
}
$md[] = '';
$md[] = '### Проверка направления ключа (корреляция пункта с остатком своей грани)';
$md[] = '';
$corr = [];
foreach (['as_is', 'recode'] as $h) {
    $neg = 0;
    $list = [];
    foreach ($facetOrder as $f) {
        foreach ($facets[$f] as $i) {
            // r(x, S − x) из сумм: cov(x, S) − var(x) и var(S − x) = var(S) − 2cov(x, S) + var(x)
            $mx = $hyp[$h]['xi'][$i] / $n;
            $ms = $hyp[$h]['fs'][$f] / $n;
            $vx = $hyp[$h]['xiq'][$i] / $n - $mx * $mx;
            $vs = $hyp[$h]['fq'][$f] / $n - $ms * $ms;
            $cxs = $hyp[$h]['xs'][$i] / $n - $mx * $ms;
            $cov = $cxs - $vx;
            $vr = $vs - 2 * $cxs + $vx;
            $r = $vx > 0 && $vr > 0 ? $cov / sqrt($vx * $vr) : 0.0;
            $list[$i] = $r;
            if ($r < 0) {
                $neg++;
            }
        }
    }
    $corr[$h] = $list;
    $minusR = array_filter($list, static fn (int $i): bool => $key[$i]['minus'], ARRAY_FILTER_USE_KEY);
    $plusR = array_filter($list, static fn (int $i): bool => !$key[$i]['minus'], ARRAY_FILTER_USE_KEY);
    $md[] = '- ' . ($h === 'as_is' ? 'Значения как в файле' : 'Обратные пункты перекодированы ещё раз (6 − x)')
        . ': отрицательных корреляций ' . $neg . ' из 120; средняя у прямых пунктов ' . number_format(array_sum($plusR) / max(1, count($plusR)), 2)
        . ', у обратных ' . number_format(array_sum($minusR) / max(1, count($minusR)), 2) . '.';
}
$md[] = '';
$md[] = '### Альфа Кронбаха: наш отбор, Johnson (2014, табл. 1) и ipip.ori.org (N = 619 150)';
$md[] = '';
$johnsonAlpha = ['N' => .90, 'E' => .89, 'O' => .83, 'A' => .87, 'C' => .90,
    'N1' => .78, 'N2' => .87, 'N3' => .85, 'N4' => .74, 'N5' => .72, 'N6' => .76,
    'E1' => .81, 'E2' => .79, 'E3' => .85, 'E4' => .71, 'E5' => .77, 'E6' => .80,
    'O1' => .76, 'O2' => .76, 'O3' => .69, 'O4' => .72, 'O5' => .75, 'O6' => .64,
    'A1' => .86, 'A2' => .76, 'A3' => .76, 'A4' => .73, 'A5' => .76, 'A6' => .72,
    'C1' => .63, 'C2' => .83, 'C3' => .69, 'C4' => .80, 'C5' => .73, 'C6' => .87];
$siteAlpha = ['N1' => .78, 'N2' => .87, 'N3' => .85, 'N4' => .70, 'N5' => .69, 'N6' => .76,
    'E1' => .81, 'E2' => .79, 'E3' => .85, 'E4' => .69, 'E5' => .73, 'E6' => .79,
    'O1' => .74, 'O2' => .74, 'O3' => .65, 'O4' => .70, 'O5' => .73, 'O6' => .63,
    'A1' => .85, 'A2' => .74, 'A3' => .73, 'A4' => .71, 'A5' => .73, 'A6' => .72,
    'C1' => .77, 'C2' => .83, 'C3' => .67, 'C4' => .79, 'C5' => .71, 'C6' => .88];
$md[] = '| Шкала | α наш отбор | α Johnson 2014, табл. 1 | α ipip.ori.org | M (все взрослые) | SD |';
$md[] = '|---|---|---|---|---|---|';
foreach ($scales as $scale) {
    $items = $scaleItems[$scale];
    $k = count($items);
    $sumVar = 0.0;
    foreach ($items as $i) {
        $m = $itemSum[$i] / $n;
        $sumVar += $itemSq[$i] / $n - $m * $m;
    }
    $mt = $scaleSum[$scale] / $n;
    $vt = $scaleSq[$scale] / $n - $mt * $mt;
    $alpha = $vt > 0 ? $k / ($k - 1) * (1 - $sumVar / $vt) : 0.0;
    [$m, $sd] = $stat($groups['all'], $scale);
    $md[] = '| ' . $scale . ' | ' . number_format($alpha, 2) . ' | ' . number_format($johnsonAlpha[$scale], 2) . ' | ' . (isset($siteAlpha[$scale]) ? number_format($siteAlpha[$scale], 2) : '—') . ' | ' . number_format($m, 2) . ' | ' . number_format($sd, 2) . ' |';
}
$md[] = '';
$md[] = '### Россия, США и вся выборка: средние доменов и граней';
$md[] = '';
$md[] = 'd — разница средних, делённая на SD всей выборки (положительная — у подвыборки выше).';
$md[] = '';
$md[] = '| Шкала | Все взрослые M (SD), N = ' . $groups['all']['n'] . ' | Россия M (SD), N = ' . $groups['russia']['n'] . ' | d Россия | США M (SD), N = ' . $groups['usa']['n'] . ' | d США |';
$md[] = '|---|---|---|---|---|---|';
foreach ($scales as $scale) {
    [$ma, $sa] = $stat($groups['all'], $scale);
    [$mr, $sr] = $stat($groups['russia'], $scale);
    [$mu, $su] = $stat($groups['usa'], $scale);
    $md[] = sprintf(
        '| %s | %.1f (%.1f) | %.1f (%.1f) | %+.2f | %.1f (%.1f) | %+.2f |',
        strlen($scale) === 1 ? '**' . $scale . '**' : $scale,
        $ma,
        $sa,
        $mr,
        $sr,
        $sa > 0 ? ($mr - $ma) / $sa : 0,
        $mu,
        $su,
        $sa > 0 ? ($mu - $ma) / $sa : 0,
    );
}
$md[] = '';
arsort($countries);
$md[] = '### Крупнейшие страны в отборе';
$md[] = '';
$md[] = '| COUNTRY | N |';
$md[] = '|---|---|';
foreach (array_slice($countries, 0, 12, true) as $c => $cn) {
    $md[] = '| ' . ($c === '' ? '(пусто)' : $c) . ' | ' . $cn . ' |';
}
$russianLike = array_filter($countries, static fn (string $c): bool => stripos($c, 'russ') !== false, ARRAY_FILTER_USE_KEY);
$md[] = '';
$md[] = 'Значения COUNTRY с «Russ»: ' . implode(', ', array_map(static fn (string $c, int $cn): string => '«' . $c . '» — ' . $cn, array_keys($russianLike), $russianLike)) . '.';
$md[] = '';

file_put_contents($report, implode("\n", $md));
fwrite(STDERR, "Отчёт: {$report}\n");
