<?php

declare(strict_types=1);

namespace PsyTest\Tests\Smil;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\Smil\Scoring\ValidityAssessor;

final class ValidityAssessorTest extends TestCase
{
    public function testAllValidProfile(): void
    {
        $assessor = new ValidityAssessor();
        $tScores = ['L' => 50, 'F' => 55, 'K' => 50, '1' => 50, '2' => 55];
        $answers = [];
        foreach (ValidityAssessor::CONTROL_QUESTIONS as $cq) {
            $answers[$cq] = 1; // All control correct
        }

        $result = $assessor->assess($tScores, $answers);
        $this->assertTrue($result['is_valid']);
        $this->assertSame(27, $result['control_score']);
        $this->assertEmpty($result['warnings']);
    }

    public function testHighLInvalidates(): void
    {
        $assessor = new ValidityAssessor();
        $tScores = ['L' => 70, 'F' => 50, 'K' => 50];
        $answers = [];
        foreach (ValidityAssessor::CONTROL_QUESTIONS as $cq) {
            $answers[$cq] = 1;
        }

        $result = $assessor->assess($tScores, $answers);
        $this->assertFalse($result['is_valid']);
        $this->assertStringContainsString('социальная желательность', $result['warnings'][0]);
    }

    public function testLowControlScoreInvalidates(): void
    {
        $assessor = new ValidityAssessor();
        $tScores = ['L' => 50, 'F' => 50, 'K' => 50];
        $answers = []; // No answers → QC = 0

        $result = $assessor->assess($tScores, $answers);
        $this->assertFalse($result['is_valid']);
        $this->assertSame(0, $result['control_score']);
    }
}
