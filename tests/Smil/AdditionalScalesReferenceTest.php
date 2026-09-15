<?php

declare(strict_types=1);

namespace PsyTest\Tests\Smil;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PsyTest\Modules\Smil\Scoring\AdditionalScalesCalculator;

/**
 * Независимые reference cases партий 05.S3.1–05.S3.4 (WP5).
 *
 * Ожидаемые значения в tests/fixtures/smil-additional-reference.json
 * посчитаны bin/smil-additional-reference.py прямо по транскрипции источника,
 * вне PHP и вне runtime-файла шкал. Self-generated fixture не считается
 * доказательством, поэтому этот тест — сравнение двух независимых реализаций.
 */
final class AdditionalScalesReferenceTest extends TestCase
{
    private const TOTAL_QUESTIONS = 566;

    /** @var array<string, mixed> */
    private array $fixture;

    private AdditionalScalesCalculator $calc;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);

        $this->fixture = json_decode(
            (string) file_get_contents($root . '/tests/fixtures/smil-additional-reference.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $runtime = json_decode(
            (string) file_get_contents($root . '/modules/smil/additional-scales-v2.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->calc = new AdditionalScalesCalculator($runtime['scales']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function caseProvider(): array
    {
        $cases = [];
        foreach (['all_true', 'all_false', 'reference_valid', 'pseudo_random_20260915'] as $set) {
            foreach (['male', 'female'] as $sex) {
                $cases["{$set}/{$sex}"] = [$set, $sex];
            }
        }

        return $cases;
    }

    #[DataProvider('caseProvider')]
    public function testCalculatorAgreesWithTheIndependentReference(string $set, string $sex): void
    {
        $expected = $this->fixture['cases']["{$set}_{$sex}"];
        $actual = $this->calc->calculate($this->answersFor($set), $sex);

        self::assertSame(
            array_keys($expected),
            array_keys($actual),
            "{$set}/{$sex}: набор шкал разошёлся с эталоном"
        );

        foreach ($expected as $code => $reference) {
            self::assertSame(
                (int) $reference['raw'],
                $actual[$code]['raw'],
                "{$set}/{$sex}/{$code}: сырой балл"
            );
            self::assertEqualsWithDelta(
                (float) $reference['t'],
                (float) $actual[$code]['t'],
                0.01,
                "{$set}/{$sex}/{$code}: T-балл"
            );
            self::assertSame(
                (int) $reference['answered'],
                $actual[$code]['answered'],
                "{$set}/{$sex}/{$code}: число отвеченных пунктов"
            );
            self::assertSame(
                (int) $reference['max_raw'],
                $actual[$code]['max_raw'],
                "{$set}/{$sex}/{$code}: max_raw"
            );
            self::assertEqualsWithDelta(
                (float) $reference['M'],
                (float) $actual[$code]['M'],
                0.0001,
                "{$set}/{$sex}/{$code}: норма M"
            );
            self::assertEqualsWithDelta(
                (float) $reference['sigma'],
                (float) $actual[$code]['sigma'],
                0.0001,
                "{$set}/{$sex}/{$code}: норма sigma"
            );
        }
    }

    public function testFixtureDocumentsItsIndependentProvenance(): void
    {
        $provenance = $this->fixture['provenance'];

        self::assertSame('bin/smil-additional-reference.py', $this->fixture['generated_by']);
        self::assertStringContainsString('Собчик', $provenance['source']);
        self::assertStringContainsString('transcription', $provenance['input']);
        self::assertStringContainsString('вне PHP', $provenance['independence']);
        self::assertNotEmpty($provenance['formula']);
        self::assertCount(75, $provenance['entries']);
        self::assertCount(75, $provenance['batches']);
        self::assertSame(
            ['05.S3.1' => 16, '05.S3.2' => 19, '05.S3.3' => 20, '05.S3.4' => 20],
            array_count_values($provenance['batches']),
            'эталон обязан покрывать все партии целиком'
        );
    }

    /**
     * @return array<int, int>
     */
    private function answersFor(string $set): array
    {
        if ($set === 'pseudo_random_20260915') {
            $answers = [];
            foreach ($this->fixture['answers'][$set] as $questionId => $value) {
                $answers[(int) $questionId] = (int) $value;
            }

            return $answers;
        }

        if ($set === 'reference_valid') {
            $payload = json_decode(
                (string) file_get_contents(dirname(__DIR__, 2) . '/tests/fixtures/smil-reference-answers-valid.json'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $answers = [];
            foreach ($payload as $questionId => $value) {
                if (is_numeric($questionId)) {
                    $answers[(int) $questionId] = (int) $value;
                }
            }

            return $answers;
        }

        $value = $set === 'all_true' ? 1 : 0;
        $answers = [];
        for ($questionId = 1; $questionId <= self::TOTAL_QUESTIONS; $questionId++) {
            $answers[$questionId] = $value;
        }

        return $answers;
    }
}
