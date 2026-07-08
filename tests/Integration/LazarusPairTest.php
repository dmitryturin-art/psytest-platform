<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\Lazarus\LazarusModule;

/**
 * Integration test for the Lazarus pair-mode flow.
 *
 * Validates the full comparison pipeline: two partners each answer
 * independently, the module compares their results, and the output
 * structure matches what the pair-comparison.twig template expects.
 */
final class LazarusPairTest extends TestCase
{
    private LazarusModule $module;

    protected function setUp(): void
    {
        $this->module = new LazarusModule();
    }

    public function testFullPairFlowProducesValidComparison(): void
    {
        // Партнёр 1: в основном доволен (self=8), думает партнёр умеренно (partner=6)
        $r1 = $this->module->calculateResults($this->allAnswers(self: 8, partner: 6));

        // Партнёр 2: умеренно доволен (self=6), думает партнёр очень доволен (partner=9)
        $r2 = $this->module->calculateResults($this->allAnswers(self: 6, partner: 9));

        // Оба результата валидны по отдельности
        $this->assertSame(128, $r1['total_self'], 'П1 total: 8*16');
        $this->assertSame(96, $r2['total_self'], 'П2 total: 6*16');
        $this->assertSame('satisfied', $r1['level']);
        $this->assertSame('satisfied', $r2['level']);

        // Сравнение
        $comparison = $this->module->comparePairResults($r1, $r2);

        // Структура для pair-comparison.twig
        $this->assertArrayHasKey('items', $comparison);
        $this->assertArrayHasKey('overall_agreement', $comparison);
        $this->assertArrayHasKey('summary', $comparison);
        $this->assertCount(16, $comparison['items']);

        // Каждый item имеет поля, нужные шаблону
        $item = $comparison['items'][0];
        $requiredKeys = ['id', 'text', 'domain', 'p1_self', 'p2_self', 'difference',
                         'p1_perception', 'p2_perception', 'p1_accuracy', 'p2_accuracy'];
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $item, "Item missing key: $key");
        }

        // П1 ставит 8, П2 ставит 6 → difference = 2
        $this->assertSame(8, $item['p1_self']);
        $this->assertSame(6, $item['p2_self']);
        $this->assertSame(2, $item['difference']);

        // П1 думал, П2 ответит 6, реально 6 → accuracy = 0 (идеально угадал!)
        $this->assertSame(6, $item['p1_perception'], 'П1 perception of П2');
        $this->assertSame(0, $item['p1_accuracy'], 'П1 accuracy: 6 - 6 = 0');

        // П2 думал, П1 ответит 9, реально 8 → accuracy = 1 (переоценил на 1)
        $this->assertSame(9, $item['p2_perception']);
        $this->assertSame(1, $item['p2_accuracy']);
    }

    public function testOverallAgreementIsHighWhenPartnersAgree(): void
    {
        // Оба ставят 7 — полная согласованность
        $r1 = $this->module->calculateResults($this->allAnswers(self: 7, partner: 7));
        $r2 = $this->module->calculateResults($this->allAnswers(self: 7, partner: 7));

        $comparison = $this->module->comparePairResults($r1, $r2);

        $this->assertSame(100.0, $comparison['overall_agreement'], '100% agreement when all equal');
    }

    public function testOverallAgreementIsLowWhenPartnersDisagree(): void
    {
        // П1 ставит 10, П2 ставит 1 — максимальное расхождение
        $r1 = $this->module->calculateResults($this->allAnswers(self: 10, partner: 1));
        $r2 = $this->module->calculateResults($this->allAnswers(self: 1, partner: 10));

        $comparison = $this->module->comparePairResults($r1, $r2);

        // |10-1|=9 на каждом пункте → agreement = (10-9)/10 = 10%
        $this->assertSame(10.0, $comparison['overall_agreement']);
        $this->assertStringContainsString('расхождения', $comparison['summary']);
    }

    public function testComparisonStructureMatchesTwigTemplate(): void
    {
        // Проверяем что все поля, к которым обращается pair-comparison.twig, есть
        $r1 = $this->module->calculateResults($this->allAnswers(self: 5, partner: 7));
        $r2 = $this->module->calculateResults($this->allAnswers(self: 9, partner: 3));
        $comparison = $this->module->comparePairResults($r1, $r2);

        // Шаблон использует: comparison.summary, comparison.overall_agreement, comparison.items
        $this->assertIsString($comparison['summary']);
        $this->assertIsFloat($comparison['overall_agreement']);
        $this->assertIsArray($comparison['items']);

        // В цикле: item.domain, item.text, item.p1_self, item.p2_self,
        // item.difference, item.p1_accuracy, item.p2_accuracy
        foreach ($comparison['items'] as $item) {
            $this->assertIsString($item['domain']);
            $this->assertIsString($item['text']);
            $this->assertIsInt($item['p1_self']);
            $this->assertIsInt($item['p2_self']);
            $this->assertIsInt($item['difference']);
        }
    }

    /**
     * @return array<string, int>
     */
    private function allAnswers(int $self, int $partner): array
    {
        $answers = [];
        foreach ($this->module->getQuestions() as $q) {
            $answers[$q['id'] . '_self'] = $self;
            $answers[$q['id'] . '_partner'] = $partner;
        }
        return $answers;
    }
}
