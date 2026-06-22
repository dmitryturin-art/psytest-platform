<?php
declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\ResultSection;

final class ResultSectionTest extends TestCase
{
    public function testSectionCreation(): void
    {
        $section = new ResultSection(
            type: ResultSection::TYPE_SCORE_BADGE,
            title: 'Общий балл',
            data: ['score' => 21, 'max' => 63],
            order: 10,
        );
        $this->assertSame(ResultSection::TYPE_SCORE_BADGE, $section->type);
        $this->assertSame('Общий балл', $section->title);
        $this->assertSame(21, $section->data['score']);
        $this->assertSame(10, $section->order);
        $this->assertNull($section->block);
    }

    public function testSectionWithBlock(): void
    {
        $section = new ResultSection(
            type: ResultSection::TYPE_PROFILE_CHART,
            title: 'Профиль',
            data: ['scores' => []],
            block: 'blocks/profile-chart.twig',
            order: 5,
        );
        $this->assertSame('blocks/profile-chart.twig', $section->block);
    }
}
