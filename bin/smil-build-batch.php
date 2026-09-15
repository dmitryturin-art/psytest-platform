<?php

declare(strict_types=1);

/**
 * Сборка runtime-файла дополнительных шкал СМИЛ из транскрипции приложения Собчик.
 *
 * Пакет 05.S3.1: первая партия из 16 записей, утверждённая владельцем 15.09.2026.
 * Ключи и нормы НЕ перепечатываются руками — они переносятся ровно из
 * docs/smil-additional-scales-transcription.json (два независимых прохода, S2).
 * Скрипт детерминирован: повторный запуск даёт побайтово тот же файл.
 *
 * Запуск: php bin/smil-build-batch.php [--check]
 *   --check  ничего не пишет, а сравнивает существующий файл с ожидаемым.
 */

$root = dirname(__DIR__);
$transcriptionPath = $root . '/docs/smil-additional-scales-transcription.json';
$outputPath = $root . '/modules/smil/additional-scales-v2.json';

/**
 * Номер записи транскрипции => runtime-код.
 *
 * Партия 05.S3.1. №9 «Алкоголизм» намеренно отсутствует: низ печатной
 * стр. 195 обрезан в скане, строка «неверно» и нормы недоступны.
 */
const BATCH = [
    1 => 'A',
    2 => 'LRN',
    6 => 'MAT',
    39 => 'CYN',
    41 => 'DPR',
    42 => 'DSU',
    43 => 'DRT',
    49 => 'Do',
    51 => 'DOV',
    53 => 'DRX',
    57 => 'DPN',
    62 => 'EGO',
    77 => 'OH',
    92 => 'Es',
    171 => 'R',
    174 => 'Re',
];

$check = in_array('--check', $argv, true);

$doc = json_decode((string) file_get_contents($transcriptionPath), true, 512, JSON_THROW_ON_ERROR);
$entries = [];
foreach ($doc['entries'] as $entry) {
    $entries[(int) $entry['number']] = $entry;
}

$scales = [];
foreach (BATCH as $number => $code) {
    if (!isset($entries[$number])) {
        fwrite(STDERR, "Запись №{$number} отсутствует в транскрипции\n");
        exit(1);
    }
    $entry = $entries[$number];

    foreach (['male', 'female'] as $sex) {
        if (!isset($entry[$sex]['M'], $entry[$sex]['sigma'])) {
            fwrite(STDERR, "Запись №{$number}: нет норм для {$sex}\n");
            exit(1);
        }
    }

    $true = $entry['true'];
    $false = $entry['false'];
    sort($true);
    sort($false);

    $scales[] = [
        'id' => $entry['id'],
        'code' => $code,
        'name' => $entry['name'],
        'status' => 'verified',
        'source' => [
            'edition' => 'Л.Н. Собчик. СМИЛ. СПб.: Речь, 2003. Приложение «Ключи к дополнительным шкалам»',
            'entry' => (int) $entry['number'],
            'page_pdf' => (int) $entry['page_pdf'],
            'page_print' => (int) $entry['page_print'],
        ],
        'key' => [
            'true' => $true,
            'false' => $false,
        ],
        'norms' => [
            'male' => [
                'M' => $entry['male']['M'],
                'sigma' => $entry['male']['sigma'],
            ],
            'female' => [
                'M' => $entry['female']['M'],
                'sigma' => $entry['female']['sigma'],
            ],
        ],
        'max_raw' => count($true) + count($false),
    ];
}

usort($scales, static fn (array $a, array $b): int => $a['source']['entry'] <=> $b['source']['entry']);

$output = [
    'version' => '05.S3.1',
    'generated_by' => 'bin/smil-build-batch.php',
    'source' => $doc['source'],
    'method' => 'Ключи и нормы перенесены скриптом из docs/smil-additional-scales-transcription.json '
        . '(транскрипция S2, два независимых прохода, 113/113 совпадений). Ручной перепечатки нет.',
    'legal_status' => $doc['legal_status'],
    'note' => 'Первая партия 05.S3.1: 16 записей со статусом verified. №9 «Алкоголизм» отложена — '
        . 'низ печатной стр. 195 обрезан в скане. Прежние 23 неподтверждённых runtime-кода выведены из расчёта.',
    'formula' => 'raw = число ответов «верно» по key.true плюс «неверно» по key.false; '
        . 'T = 50 + 10 * (raw - M) / sigma по нормам пола респондента, округление до целого, зажим [20, 100].',
    'scales' => $scales,
];

$json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n";

if ($check) {
    $current = file_exists($outputPath) ? (string) file_get_contents($outputPath) : '';
    if ($current !== $json) {
        fwrite(STDERR, "additional-scales-v2.json отличается от сборки по транскрипции\n");
        exit(1);
    }
    echo "OK: " . count($scales) . " шкал, файл совпадает с транскрипцией\n";
    exit(0);
}

file_put_contents($outputPath, $json);
echo "Записано " . count($scales) . " шкал в modules/smil/additional-scales-v2.json\n";
