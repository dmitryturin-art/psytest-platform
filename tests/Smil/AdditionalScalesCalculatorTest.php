<?php

declare(strict_types=1);

namespace PsyTest\Tests\Smil;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\Smil\Scoring\AdditionalScalesCalculator;

/**
 * Поведение калькулятора дополнительных шкал на партии 05.S3.1.
 *
 * Численные ожидания живут в AdditionalScalesReferenceTest (независимый
 * эталон по источнику); здесь проверяется контракт: форма результата,
 * кодировка ответов, применение норм по полу.
 */
final class AdditionalScalesCalculatorTest extends TestCase
{
    private AdditionalScalesCalculator $calc;

    /** @var list<array<string, mixed>> */
    private array $definitions;

    protected function setUp(): void
    {
        $runtime = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/modules/smil/additional-scales-v2.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->definitions = $runtime['scales'];
        $this->calc = new AdditionalScalesCalculator($this->definitions);
    }

    public function testReturnsTheWholeVerifiedBatch(): void
    {
        $results = $this->calc->calculate($this->uniformAnswers(1), 'male');

        self::assertCount(16, $results);
        foreach (['A', 'R', 'Es', 'Do', 'Re', 'CYN', 'OH', 'LRN', 'MAT', 'DPR', 'DSU', 'DRT', 'DOV', 'DRX', 'DPN', 'EGO'] as $code) {
            self::assertArrayHasKey($code, $results, "шкала {$code} отсутствует");
        }
    }

    public function testScaleCarriesRawTNormsProvenanceAndNoClinicalText(): void
    {
        $results = $this->calc->calculate($this->uniformAnswers(1), 'male');
        $scale = $results['A'];

        self::assertSame(
            ['id', 'code', 'name', 'raw', 't', 'M', 'sigma', 'max_raw', 'answered', 'level', 'level_name', 'source', 'status'],
            array_keys($scale)
        );
        self::assertSame('sobchik-001', $scale['id']);
        self::assertSame('verified', $scale['status']);
        self::assertSame(195, $scale['source']['page_print']);
        self::assertArrayNotHasKey('interpretation', $scale, 'клинические тексты для новых шкал не выдаются');
    }

    public function testAllTrueScoresEveryTrueItemAndNoFalseItem(): void
    {
        $results = $this->calc->calculate($this->uniformAnswers(1), 'male');

        foreach ($this->definitions as $definition) {
            $code = $definition['code'];
            self::assertSame(count($definition['key']['true']), $results[$code]['raw'], "{$code}: raw при всех «верно»");
            self::assertSame($results[$code]['max_raw'], $results[$code]['answered'], "{$code}: answered");
        }
    }

    public function testUnknownAnswersAreNotCountedAsAnswered(): void
    {
        $results = $this->calc->calculate($this->uniformAnswers(2), 'male');

        foreach ($results as $code => $scale) {
            self::assertSame(0, $scale['raw'], "{$code}: «не знаю» не даёт сырых баллов");
            self::assertSame(0, $scale['answered'], "{$code}: «не знаю» не считается отвеченным");
        }
    }

    public function testNormsFollowTheRespondentSex(): void
    {
        $answers = $this->uniformAnswers(1);
        $male = $this->calc->calculate($answers, 'male');
        $female = $this->calc->calculate($answers, 'female');

        $definitions = [];
        foreach ($this->definitions as $definition) {
            $definitions[$definition['code']] = $definition;
        }

        foreach ($male as $code => $scale) {
            self::assertSame($definitions[$code]['norms']['male']['M'], $scale['M'], "{$code}: мужская M");
            self::assertSame($definitions[$code]['norms']['female']['M'], $female[$code]['M'], "{$code}: женская M");
        }

        self::assertNotEquals($male['A']['t'], $female['A']['t'], 'нормы пола должны влиять на T');
    }

    public function testTScoresStayInsideTheBasicScaleRange(): void
    {
        foreach ([$this->uniformAnswers(1), $this->uniformAnswers(0)] as $answers) {
            foreach ($this->calc->calculate($answers, 'female') as $code => $scale) {
                self::assertGreaterThanOrEqual(20, $scale['t'], "{$code}: T >= 20");
                self::assertLessThanOrEqual(100, $scale['t'], "{$code}: T <= 100");
            }
        }
    }

    public function testNeutralLevelLabelsHaveNoClinicalWording(): void
    {
        $results = $this->calc->calculate($this->uniformAnswers(1), 'male');

        foreach ($results as $code => $scale) {
            self::assertContains($scale['level'], ['above', 'normal', 'below'], "{$code}: уровень");
            self::assertContains(
                $scale['level_name'],
                ['выше нормы', 'в пределах нормы', 'ниже нормы'],
                "{$code}: подпись уровня"
            );
        }
    }

    /**
     * @return array<int, int>
     */
    private function uniformAnswers(int $value): array
    {
        $answers = [];
        for ($questionId = 1; $questionId <= 566; $questionId++) {
            $answers[$questionId] = $value;
        }

        return $answers;
    }
}
