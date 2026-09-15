<?php

declare(strict_types=1);

namespace PsyTest\Tests\Smil;

use PHPUnit\Framework\TestCase;

/**
 * Инварианты партии дополнительных шкал 05.S3.1 (WP4).
 *
 * Runtime-файл modules/smil/additional-scales-v2.json собирается скриптом
 * bin/smil-build-batch.php из транскрипции приложения Собчик. Тест стережёт
 * то, что должно оставаться верным при любой пересборке: ключи и нормы равны
 * источнику, номера пунктов допустимы, набор не смешан с прежними
 * неподтверждёнными кодами.
 */
final class AdditionalScalesInvariantsTest extends TestCase
{
    /** Номер записи транскрипции => runtime-код (партия 05.S3.1). */
    private const BATCH = [
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

    public function testBatchHasExactlySixteenScalesWithExpectedCodes(): void
    {
        self::assertCount(16, $this->scales);

        $codes = array_map(static fn (array $scale): string => $scale['code'], $this->scales);
        self::assertSame(count($codes), count(array_unique($codes)), 'коды дублируются');
        self::assertSame($this->sorted(array_values(self::BATCH)), $this->sorted($codes));

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

            self::assertSame(self::BATCH[$number], $scale['code'], "№{$number}: код партии");
            self::assertSame($entry['id'], $scale['id'], "№{$number}: id");
            self::assertSame($entry['name'], $scale['name'], "№{$number}: название источника");
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

    public function testEveryScaleIsVerified(): void
    {
        foreach ($this->scales as $scale) {
            self::assertSame('verified', $scale['status'], "{$scale['code']}: статус");
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
