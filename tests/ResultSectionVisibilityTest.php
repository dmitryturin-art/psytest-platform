<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\ResultSection;

/** Секция без содержимого не выводится (04.D8): заголовок «Рекомендации» без пунктов — сбой на вид. */
final class ResultSectionVisibilityTest extends TestCase
{
    private function recommendations(mixed $items): ResultSection
    {
        return new ResultSection(
            type: ResultSection::TYPE_RECOMMENDATIONS,
            title: 'Рекомендации',
            data: ['items' => $items],
            block: 'blocks/recommendations.twig',
        );
    }

    public function testRecommendationsWithoutItemsAreEmpty(): void
    {
        self::assertTrue($this->recommendations([])->isEmpty());
        self::assertTrue($this->recommendations(['', '  '])->isEmpty());
        self::assertTrue($this->recommendations(null)->isEmpty());
        self::assertTrue((new ResultSection(ResultSection::TYPE_RECOMMENDATIONS, 'Рекомендации', []))->isEmpty());
    }

    public function testRecommendationsWithItemsAreVisible(): void
    {
        self::assertFalse($this->recommendations(['Первый пункт'])->isEmpty());
    }

    public function testOtherSectionTypesAreNeverHidden(): void
    {
        $badge = new ResultSection(ResultSection::TYPE_SCORE_BADGE, 'Уровень', ['items' => []]);

        self::assertFalse($badge->isEmpty());
    }

    public function testVisibleDropsOnlyEmptySectionsAndKeepsOrder(): void
    {
        $badge = new ResultSection(ResultSection::TYPE_SCORE_BADGE, 'Уровень', ['score' => 21]);
        $full = $this->recommendations(['Пункт']);
        $empty = $this->recommendations([]);

        self::assertSame([$badge, $full], ResultSection::visible([$badge, $empty, $full]));
    }
}
