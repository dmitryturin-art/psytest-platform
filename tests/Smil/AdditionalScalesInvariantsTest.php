<?php

declare(strict_types=1);

namespace PsyTest\Tests\Smil;

use PHPUnit\Framework\TestCase;

/**
 * Инварианты партий дополнительных шкал 05.S3.1–05.S3.5 (WP4).
 *
 * Runtime-файл modules/smil/additional-scales-v2.json собирается скриптом
 * bin/smil-build-batch.php из транскрипции приложения Собчик. Тест стережёт
 * то, что должно оставаться верным при любой пересборке: ключи и нормы равны
 * источнику, номера пунктов допустимы, набор не смешан с прежними
 * неподтверждёнными кодами, все шкалы verified, а пояснительное поле note
 * стоит только у записей, для которых владелец его утвердил.
 */
final class AdditionalScalesInvariantsTest extends TestCase
{
    /** Партия 05.S3.1: номер записи транскрипции => runtime-код. */
    private const BATCH_1 = [
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

    /**
     * Партия 05.S3.2: 19 клинических подшкал, утверждены владельцем 15.09.2026.
     *
     * №72 «Предипохондрическое состояние» в партию не входит: её нормы в издании —
     * дубль строки №74, то есть собственные нормы записи утрачены, и считать по ним
     * T-балл нельзя. Ждёт другого издания.
     */
    private const BATCH_2 = [
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
    ];

    /**
     * Партия 05.S3.3: 20 шкал психотического и психопатического спектра,
     * утверждены владельцем 15.09.2026.
     *
     * Аномалий сверки (утраченные нормы, опечатки ключа) у этих записей нет:
     * нормы по обоим полам присутствуют в источнике, поля note не требуется.
     */
    private const BATCH_3 = [
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
    ];

    /**
     * Партия 05.S3.4: 20 шкал гипоманиакального спектра и социального
     * функционирования, утверждены владельцем 15.09.2026.
     *
     * Утраченных норм и опечаток ключа в партии нет. У №177 заголовок источника
     * напечатан с опечаткой («Играния роли»), поэтому runtime-имя правится
     * NAME_OVERRIDES, а написание источника едет в поле note.
     */
    private const BATCH_4 = [
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
    ];

    /**
     * Партия 05.S3.5: 30 оставшихся записей приложения, утверждены владельцем 15.09.2026.
     *
     * Партия закрывает приложение целиком, кроме семи отложенных записей: №9, 58, 72, 167
     * ждут другого издания (обрезанный скан или недостоверные нормы), а №175, 176, 178, 179 —
     * шкалы только для одного пола, они уходят в S3.6 вместе с поддержкой в калькуляторе.
     * Особые случаи партии: №47/№52 и №49/№50 названы в источнике одинаково (разведены
     * NAME_OVERRIDES), №74 несёт нормы, совпадающие со строкой выведенной №72, а №162 и №205
     * построены на почти одном наборе пунктов в противоположных направлениях.
     */
    private const BATCH_5 = [
        7 => 'ALD',
        19 => 'RSP',
        22 => 'Ca',
        23 => 'Cl',
        38 => 'Cs',
        47 => 'CRM1',
        50 => 'Do2',
        52 => 'CRM2',
        55 => 'Ds',
        56 => 'DSb',
        64 => 'IMV',
        70 => 'GMA',
        74 => 'HCO',
        81 => 'Hv',
        85 => 'Hy2',
        88 => 'Hy5',
        94 => 'In',
        95 => 'IQR',
        99 => 'CHO',
        122 => 'Mf4',
        135 => 'Or',
        162 => 'Pr',
        172 => 'RCD',
        181 => 'SCZ',
        205 => 'To',
        206 => 'TCH',
        209 => 'ULC',
        210 => 'LAC',
        211 => 'Wa',
        212 => 'SDF',
    ];

    /** Партия => номера записей. */
    private const BATCH_LABELS = [
        '05.S3.1' => self::BATCH_1,
        '05.S3.2' => self::BATCH_2,
        '05.S3.3' => self::BATCH_3,
        '05.S3.4' => self::BATCH_4,
        '05.S3.5' => self::BATCH_5,
    ];

    /**
     * Единственные записи, которым разрешено пояснительное поле note.
     *
     * №87 — опечатка источника в строке ключа («11 верно» вместо «11 неверно»);
     * состав ключа подтверждён сверкой с Harris–Lingoes Hy4, поэтому статус
     * остаётся `verified`, а note лишь объясняет расхождение с печатной строкой.
     * №177 — опечатка в заголовке источника («Играния роли»); ключ и нормы не
     * затронуты, note фиксирует написание книги.
     * №47 и №52 — один и тот же заголовок «Преступность» при разных ключах;
     * №50 — заголовок «Доминирование», повторяющий №49. Во всех трёх note объясняет,
     * почему runtime-имя отличается от печатного.
     * №55 — опечатка заголовка и часть чисел сжатым кеглем.
     * №56 — позиция 10 списка «неверно» набрана сжатым кеглем; вариант прочтения выбран
     * по принадлежности пункта ключу 2-й базовой шкалы.
     * №74 — нормы совпадают со строкой выведенной №72.
     * №162 и №205 — почти зеркальные ключи, считаются и трактуются раздельно.
     * Список закрыт: note у любой другой шкалы — дефект сборки.
     */
    private const NOTE_EXCEPTIONS = [47, 50, 52, 55, 56, 74, 87, 162, 177, 205];

    /**
     * Записи, у которых runtime-имя намеренно отличается от заголовка источника.
     *
     * Оснований ровно два: опечатка набора в книге (правится грамматика названия) и
     * одинаковый заголовок у двух разных записей (добавляется римская цифра, иначе шкалы
     * неразличимы в таблице результата и в PDF). Список закрыт и дублирует NAME_OVERRIDES
     * сборщика: любое другое расхождение имени с транскрипцией — дефект.
     */
    private const NAME_OVERRIDES = [
        47 => 'Шкала «Преступность (I)»',
        50 => 'Доминирование (II)',
        52 => 'Шкала «Преступность (II)»',
        177 => 'Шкала «Играние роли»',
    ];

    /** Прежние runtime-коды, признанные неподтверждёнными в S1/S2 и выведенные из расчёта. */
    private const RETIRED_CODES = [
        'Pk', 'ANX', 'FRS', 'OBS', 'DEP', 'HEA', 'BIZ', 'ANG',
        'ASP', 'TPA', 'LSE', 'SOD', 'FAM', 'WRK', 'TRT', 'MAC',
    ];

    /** @var list<array<string, mixed>> */
    private array $scales;

    /** @var array<int, array<string, mixed>> */
    private array $entries;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);

        $runtime = json_decode(
            (string) file_get_contents($root . '/modules/smil/additional-scales-v2.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->scales = $runtime['scales'];

        $doc = json_decode(
            (string) file_get_contents($root . '/docs/smil-additional-scales-transcription.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->entries = [];
        foreach ($doc['entries'] as $entry) {
            $this->entries[(int) $entry['number']] = $entry;
        }
    }

    /**
     * Номер записи => runtime-код по всем партиям.
     *
     * @return array<int, string>
     */
    private static function batch(): array
    {
        return self::BATCH_1 + self::BATCH_2 + self::BATCH_3 + self::BATCH_4 + self::BATCH_5;
    }

    public function testBothBatchesArePresentWithExpectedCodesAndOrder(): void
    {
        self::assertCount(105, $this->scales);

        $expectedOrder = [];
        foreach (self::BATCH_LABELS as $label => $codes) {
            ksort($codes);
            foreach ($codes as $code) {
                $expectedOrder[] = $code;
            }
        }
        self::assertSame(
            $expectedOrder,
            array_map(static fn (array $scale): string => $scale['code'], $this->scales),
            'порядок: партии 1–5, внутри партии — по номеру записи'
        );

        foreach ($this->scales as $scale) {
            $number = (int) $scale['source']['entry'];
            $expectedBatch = match (true) {
                isset(self::BATCH_1[$number]) => '05.S3.1',
                isset(self::BATCH_2[$number]) => '05.S3.2',
                isset(self::BATCH_3[$number]) => '05.S3.3',
                isset(self::BATCH_4[$number]) => '05.S3.4',
                default => '05.S3.5',
            };
            self::assertSame($expectedBatch, $scale['batch'] ?? null, "№{$number}: партия");
        }

        $codes = array_map(static fn (array $scale): string => $scale['code'], $this->scales);
        self::assertSame(count($codes), count(array_unique($codes)), 'коды дублируются');
        self::assertSame($this->sorted(array_values(self::batch())), $this->sorted($codes));

        $ids = array_map(static fn (array $scale): string => $scale['id'], $this->scales);
        self::assertSame(count($ids), count(array_unique($ids)), 'id дублируются');
        foreach ($ids as $id) {
            self::assertMatchesRegularExpression('/^sobchik-\d{3}$/', $id);
        }
    }

    public function testKeysAndNormsMatchTheTranscriptionExactly(): void
    {
        foreach ($this->scales as $scale) {
            $number = (int) $scale['source']['entry'];
            self::assertArrayHasKey($number, $this->entries, "запись №{$number} отсутствует в транскрипции");
            $entry = $this->entries[$number];

            self::assertSame(self::batch()[$number], $scale['code'], "№{$number}: код партии");
            self::assertSame($entry['id'], $scale['id'], "№{$number}: id");
            self::assertSame(
                self::NAME_OVERRIDES[$number] ?? $entry['name'],
                $scale['name'],
                "№{$number}: название источника"
            );
            self::assertSame((int) $entry['page_pdf'], (int) $scale['source']['page_pdf'], "№{$number}: страница PDF");
            self::assertSame((int) $entry['page_print'], (int) $scale['source']['page_print'], "№{$number}: печатная страница");

            self::assertSame($this->sorted($entry['true']), $this->sorted($scale['key']['true']), "№{$number}: ключ «верно»");
            self::assertSame($this->sorted($entry['false']), $this->sorted($scale['key']['false']), "№{$number}: ключ «неверно»");

            foreach (['male', 'female'] as $sex) {
                self::assertSame($entry[$sex]['M'], $scale['norms'][$sex]['M'], "№{$number}: M {$sex}");
                self::assertSame($entry[$sex]['sigma'], $scale['norms'][$sex]['sigma'], "№{$number}: sigma {$sex}");
            }
        }
    }

    public function testQuestionNumbersAreValidAndNonOverlapping(): void
    {
        foreach ($this->scales as $scale) {
            $code = $scale['code'];
            $true = $scale['key']['true'];
            $false = $scale['key']['false'];

            self::assertSame(count($true), count(array_unique($true)), "{$code}: дубли в «верно»");
            self::assertSame(count($false), count(array_unique($false)), "{$code}: дубли в «неверно»");
            self::assertSame([], array_values(array_intersect($true, $false)), "{$code}: пункт и в «верно», и в «неверно»");

            foreach (array_merge($true, $false) as $questionId) {
                self::assertIsInt($questionId, "{$code}: номер пункта не целое число");
                self::assertGreaterThanOrEqual(1, $questionId, "{$code}: номер пункта < 1");
                self::assertLessThanOrEqual(566, $questionId, "{$code}: номер пункта > 566");
            }

            self::assertSame(count($true) + count($false), (int) $scale['max_raw'], "{$code}: max_raw");
        }
    }

    public function testNormsAreReachableAndUsable(): void
    {
        foreach ($this->scales as $scale) {
            $code = $scale['code'];
            foreach (['male', 'female'] as $sex) {
                $mean = (float) $scale['norms'][$sex]['M'];
                $sigma = (float) $scale['norms'][$sex]['sigma'];

                self::assertGreaterThanOrEqual(0, $mean, "{$code}/{$sex}: M < 0");
                self::assertLessThanOrEqual((float) $scale['max_raw'], $mean, "{$code}/{$sex}: M > max_raw");
                self::assertGreaterThan(0, $sigma, "{$code}/{$sex}: sigma <= 0");
            }
        }
    }

    public function testEveryScaleIsVerifiedAndOnlyApprovedEntriesCarryANote(): void
    {
        $withNote = [];

        foreach ($this->scales as $scale) {
            $number = (int) $scale['source']['entry'];

            self::assertSame(
                'verified',
                (string) $scale['status'],
                "№{$number} ({$scale['code']}): в runtime идут только verified-шкалы"
            );

            if (isset($scale['note'])) {
                $withNote[] = $number;
                self::assertContains(
                    $number,
                    self::NOTE_EXCEPTIONS,
                    "№{$number} ({$scale['code']}): note разрешён только записям "
                    . implode(', ', self::NOTE_EXCEPTIONS)
                );
                self::assertNotEmpty($scale['note'], "№{$number}: пустой note бессмыслен");
            }
        }

        sort($withNote);
        self::assertSame(self::NOTE_EXCEPTIONS, $withNote, 'состав записей с примечанием закреплён владельцем');
    }

    /**
     * №72 выведена из runtime: её нормы в издании — дубль строки №74.
     *
     * Шкала без собственных норм даёт бессмысленный T-балл, поэтому её возвращение
     * без нового источника должно ломать сборку, а не тихо проходить.
     */
    public function testEntryWithLostNormsStaysOutOfRuntime(): void
    {
        foreach ($this->scales as $scale) {
            self::assertNotSame(72, (int) $scale['source']['entry'], 'запись №72 вернулась в расчёт');
            self::assertNotSame('PHC', $scale['code'], 'код PHC вернулся в расчёт');
        }

        // Предусловие решения владельца: нормы №72 и №74 в транскрипции совпадают.
        foreach (['male', 'female'] as $sex) {
            self::assertSame(
                $this->entries[74][$sex],
                $this->entries[72][$sex],
                "нормы №72 и №74 разошлись — основание вывода №72 требует пересмотра ({$sex})"
            );
        }
    }

    public function testRetiredUnverifiedCodesAreGoneFromRuntime(): void
    {
        $codes = array_map(static fn (array $scale): string => $scale['code'], $this->scales);

        foreach (self::RETIRED_CODES as $retired) {
            self::assertNotContains($retired, $codes, "неподтверждённый код {$retired} вернулся в runtime");
        }

        $root = dirname(__DIR__, 2);
        self::assertFileDoesNotExist($root . '/modules/smil/additional-scales-norms.json');
        self::assertFileDoesNotExist($root . '/modules/smil/additional-scales.json');
    }

    public function testRuntimeFileIsReproducibleFromTheTranscription(): void
    {
        $root = dirname(__DIR__, 2);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/smil-build-batch.php') . ' --check 2>&1', $output, $code);

        self::assertSame(0, $code, "bin/smil-build-batch.php --check: " . implode("\n", $output));
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<mixed>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return array_values($values);
    }
}
