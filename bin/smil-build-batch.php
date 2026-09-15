<?php

declare(strict_types=1);

/**
 * Сборка runtime-файла дополнительных шкал СМИЛ из транскрипции приложения Собчик.
 *
 * Партии задаются списком BATCHES: 05.S3.1 (16 записей), 05.S3.2 (19 клинических
 * подшкал), 05.S3.3 (20 шкал психотического и психопатического спектра) и
 * 05.S3.4 (20 шкал гипоманиакального спектра и социального функционирования),
 * все утверждены владельцем 15.09.2026. Ключи и нормы НЕ перепечатываются
 * руками — они переносятся ровно из docs/smil-additional-scales-transcription.json
 * (два независимых прохода, S2). Скрипт детерминирован: повторный запуск даёт
 * побайтово тот же файл, а порядок шкал — порядок партий, внутри партии по номеру записи.
 *
 * Запуск: php bin/smil-build-batch.php [--check]
 *   --check  ничего не пишет, а сравнивает существующий файл с ожидаемым.
 */

$root = dirname(__DIR__);
$transcriptionPath = $root . '/docs/smil-additional-scales-transcription.json';
$outputPath = $root . '/modules/smil/additional-scales-v2.json';

/**
 * Партия => (номер записи транскрипции => runtime-код).
 *
 * 05.S3.1 — первая партия. №9 «Алкоголизм» намеренно отсутствует: низ печатной
 * стр. 195 обрезан в скане, строка «неверно» и нормы недоступны.
 * 05.S3.2 — вторая партия, 19 клинических подшкал. №72 «Предипохондрическое
 * состояние» выведена решением владельца: её нормы — дубль строки №74, то есть
 * собственные нормы записи в этом издании утрачены. Шкала без своих норм в расчёт
 * не идёт; ждём другое издание.
 * 05.S3.3 — третья партия, 20 шкал психотического и психопатического спектра
 * (подшкалы Pa/Pd/Sc, пары Obvious/Subtle и исследовательские шкалы приложения).
 * Аномалий сверки у этих записей нет, нормы по обоим полам в источнике присутствуют.
 * 05.S3.4 — четвёртая партия, 20 шкал: гипоманиакальный спектр (подшкалы Ma1/Ma2
 * Harris–Lingoes, пара Ma-Obvious/Ma-Subtle Wiener–Harmon, «чистая» выжимка из 9-й
 * шкалы) и социальное функционирование (контроль, лидерство, социальное участие
 * и статус, социальная желательность, установки на тест). Утраченных норм и опечаток
 * ключа нет; у №177 заголовок источника напечатан с опечаткой — см. NAME_OVERRIDES.
 */
const BATCHES = [
    '05.S3.1' => [
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
    ],
    '05.S3.2' => [
        37 => 'CNV',
        46 => 'GLM',
        48 => 'DNS',
        60 => 'EGC',
        73 => 'HDC',
        75 => 'HLT',
        80 => 'HYP',
        83 => 'HYS',
        84 => 'ARP',
        87 => 'SOM',
        89 => 'HYO',
        90 => 'HYL',
        93 => 'IMP',
        97 => 'ANC',
        98 => 'GLT',
        129 => 'NEU',
        131 => 'NOC',
        134 => 'NUC',
        193 => 'SOR',
    ],
    '05.S3.3' => [
        61 => 'EPI',
        138 => 'PAR',
        139 => 'PRS',
        140 => 'POI',
        141 => 'NAI',
        142 => 'PAO',
        143 => 'PAS',
        144 => 'PRC',
        146 => 'PPD',
        147 => 'FMD',
        148 => 'AUT',
        152 => 'PDO',
        153 => 'PDS',
        156 => 'SZP',
        157 => 'PFA',
        158 => 'PNE',
        170 => 'PSZ',
        182 => 'SAL',
        183 => 'EAL',
        187 => 'BSE',
    ],
    '05.S3.4' => [
        26 => 'Cn',
        36 => 'CMP',
        59 => 'EIM',
        66 => 'FEM',
        106 => 'LDR',
        109 => 'HPM',
        110 => 'Ma1',
        111 => 'Ma2',
        114 => 'MaO',
        115 => 'MaS',
        119 => 'SEN',
        121 => 'ALT',
        169 => 'PSI',
        177 => 'RPL',
        189 => 'SSF',
        194 => 'SDS',
        196 => 'SPA',
        200 => 'SST',
        203 => 'SHY',
        208 => 'TTD',
    ],
];

/**
 * Записи, у которых заголовок источника напечатан с опечаткой.
 *
 * Номер записи => имя для runtime. Правится только грамматика русского заголовка;
 * написание источника остаётся в NOTES, чтобы сверка с книгой не ломалась. Ключи,
 * нормы и страницы при этом не трогаются.
 */
const NAME_OVERRIDES = [
    177 => 'Шкала «Играние роли»',
];

/**
 * Примечания к записям, у которых состав ключа потребовал отдельной сверки.
 *
 * Номер записи => текст. Статус шкалы при этом остаётся `verified`: примечание
 * объясняет опечатку источника, а не ставит под сомнение сами данные. Текст
 * попадает в поле note runtime-файла и показывается в результате и PDF.
 */
const NOTES = [
    87 => 'В источнике опечатка «верно/неверно»; состав подтверждён сверкой с '
        . 'Harris–Lingoes Hy4 (17 п., 6 true / 11 false).',
    177 => 'В источнике заголовок напечатан как «Шкала „Играния роли“»; в runtime '
        . 'исправлена только грамматика названия, ключ и нормы перенесены без изменений.',
];

$check = in_array('--check', $argv, true);

$doc = json_decode((string) file_get_contents($transcriptionPath), true, 512, JSON_THROW_ON_ERROR);
$entries = [];
foreach ($doc['entries'] as $entry) {
    $entries[(int) $entry['number']] = $entry;
}

$scales = [];
$seenCodes = [];
foreach (BATCHES as $batch => $codes) {
    ksort($codes);
    foreach ($codes as $number => $code) {
        if (!isset($entries[$number])) {
            fwrite(STDERR, "Запись №{$number} отсутствует в транскрипции\n");
            exit(1);
        }
        if (isset($seenCodes[$code])) {
            fwrite(STDERR, "Код {$code} встречается в партиях дважды\n");
            exit(1);
        }
        $seenCodes[$code] = true;
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

        $scale = [
            'id' => $entry['id'],
            'code' => $code,
            'name' => NAME_OVERRIDES[$number] ?? $entry['name'],
            'status' => 'verified',
            'batch' => $batch,
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

        if (isset(NOTES[$number])) {
            $scale['note'] = NOTES[$number];
        }

        $scales[] = $scale;
    }
}

$output = [
    'version' => '05.S3.4',
    'generated_by' => 'bin/smil-build-batch.php',
    'source' => $doc['source'],
    'method' => 'Ключи и нормы перенесены скриптом из docs/smil-additional-scales-transcription.json '
        . '(транскрипция S2, два независимых прохода, 113/113 совпадений). Ручной перепечатки нет.',
    'legal_status' => $doc['legal_status'],
    'note' => 'Партия 05.S3.1: 16 записей. Партия 05.S3.2: 19 клинических подшкал. '
        . 'Партия 05.S3.3: 20 шкал психотического и психопатического спектра. '
        . 'Партия 05.S3.4: 20 шкал гипоманиакального спектра и социального функционирования. '
        . 'Все 75 — verified; '
        . 'у №87 есть поле note про опечатку «верно/неверно» в источнике, состав ключа подтверждён '
        . 'сверкой с Harris–Lingoes Hy4; у №177 — про опечатку заголовка «Играния роли» в источнике. '
        . '№72 «Предипохондрическое состояние» выведена: её нормы — '
        . 'дубль строки №74, собственные нормы записи в этом издании утрачены. '
        . '№9 «Алкоголизм» отложена — низ печатной стр. 195 обрезан в скане. '
        . 'Прежние 23 неподтверждённых runtime-кода выведены из расчёта.',
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
