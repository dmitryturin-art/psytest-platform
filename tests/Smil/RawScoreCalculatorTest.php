<?php
declare(strict_types=1);

namespace PsyTest\Tests\Smil;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\Smil\Scoring\RawScoreCalculator;

final class RawScoreCalculatorTest extends TestCase
{
    private RawScoreCalculator $calc;

    protected function setUp(): void
    {
        $data = json_decode(
            file_get_contents(__DIR__ . '/../../modules/smil/questions-566-full.json'),
            true
        );
        $this->calc = new RawScoreCalculator($data['questions']);
    }

    public function testAllYesAnswersProducesCorrectMaxima(): void
    {
        $data = json_decode(
            file_get_contents(__DIR__ . '/../../modules/smil/questions-566-full.json'),
            true
        );
        $answers = [];
        foreach ($data['questions'] as $q) {
            $answers[$q['id']] = RawScoreCalculator::ANSWER_YES;
        }

        $raw = $this->calc->calculate($answers);

        $this->assertSame(0, $raw['L'], 'L: 15 items, all direction=-1, Yes→0');
        $this->assertSame(45, $raw['F'], 'F: 45 direction=+1 items, Yes→45');
        $this->assertSame(1, $raw['K'], 'K: 1 direction=+1 item, Yes→1');
        $this->assertSame(11, $raw['1'], '1: 11 direction=+1 items, Yes→11');
        $this->assertSame(20, $raw['2'], '2: 20 direction=+1 items, Yes→20');
        $this->assertSame(12, $raw['3'], '3: 12 direction=+1 items, Yes→12');
        $this->assertSame(24, $raw['4'], '4: 24 direction=+1 items, Yes→24');
        $this->assertSame(54, $raw['5'], '5: 54 direction=+1 items (5F+5M), Yes→54');
        $this->assertSame(25, $raw['6'], '6: 25 direction=+1 items, Yes→25');
        $this->assertSame(38, $raw['7'], '7: 38 direction=+1 items, Yes→38');
        $this->assertSame(59, $raw['8'], '8: 59 direction=+1 items, Yes→59');
        $this->assertSame(35, $raw['9'], '9: 35 direction=+1 items, Yes→35');
        $this->assertSame(34, $raw['0'], '0: 34 direction=+1 items, Yes→34');
    }

    public function testAllNoAnswersProducesCorrectValues(): void
    {
        $data = json_decode(
            file_get_contents(__DIR__ . '/../../modules/smil/questions-566-full.json'),
            true
        );
        $answers = [];
        foreach ($data['questions'] as $q) {
            $answers[$q['id']] = RawScoreCalculator::ANSWER_NO;
        }

        $raw = $this->calc->calculate($answers);

        $this->assertSame(15, $raw['L'], 'L: 15 items, all direction=-1, No→15');
        $this->assertSame(20, $raw['F'], 'F: 20 direction=-1 items, No→20');
        $this->assertSame(29, $raw['K'], 'K: 29 direction=-1 items, No→29');
        $this->assertSame(22, $raw['1'], '1: 22 direction=-1 items, No→22');
        $this->assertSame(40, $raw['2'], '2: 40 direction=-1 items, No→40');
        $this->assertSame(47, $raw['3'], '3: 47 direction=-1 items, No→47');
        $this->assertSame(26, $raw['4'], '4: 26 direction=-1 items, No→26');
        $this->assertSame(66, $raw['5'], '5: 66 direction=-1 items (5F+5M), No→66');
        $this->assertSame(15, $raw['6'], '6: 15 direction=-1 items, No→15');
        $this->assertSame(9, $raw['7'], '7: 9 direction=-1 items, No→9');
        $this->assertSame(19, $raw['8'], '8: 19 direction=-1 items, No→19');
        $this->assertSame(11, $raw['9'], '9: 11 direction=-1 items, No→11');
        $this->assertSame(36, $raw['0'], '0: 36 direction=-1 items, No→36');
    }

    public function testUnknownAnswersAreSkipped(): void
    {
        $data = json_decode(
            file_get_contents(__DIR__ . '/../../modules/smil/questions-566-full.json'),
            true
        );
        $answers = [];
        foreach ($data['questions'] as $q) {
            $answers[$q['id']] = RawScoreCalculator::ANSWER_UNKNOWN;
        }

        $raw = $this->calc->calculate($answers);
        foreach ($raw as $scale => $score) {
            $this->assertSame(0, $score, "$scale should be 0 with all Unknown");
        }
    }

    public function testCountUnknownReturnsCorrectNumber(): void
    {
        $answers = [];
        for ($i = 1; $i <= 566; $i++) {
            $answers[$i] = $i <= 10 ? RawScoreCalculator::ANSWER_UNKNOWN : RawScoreCalculator::ANSWER_YES;
        }

        $this->assertSame(10, $this->calc->countUnknown($answers));
    }

    public function testCountUnknownIgnoresNonNumericKeys(): void
    {
        $answers = [
            1 => RawScoreCalculator::ANSWER_UNKNOWN,
            2 => RawScoreCalculator::ANSWER_UNKNOWN,
            'gender' => 'male',
            'age' => 30,
        ];

        $this->assertSame(2, $this->calc->countUnknown($answers));
    }
}
