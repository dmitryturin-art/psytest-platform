<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\Lazarus\LazarusModule;
use PsyTest\Modules\ResultSection;

final class LazarusModuleTest extends TestCase
{
    private LazarusModule $module;

    protected function setUp(): void
    {
        $this->module = new LazarusModule();
    }

    public function testLoads16QuestionsFromJson(): void
    {
        $questions = $this->module->getQuestions();
        $this->assertCount(16, $questions);
        $this->assertTrue($questions[0]['dual'] ?? false, 'Question 1 must be dual-evaluated');
        $this->assertSame('Общение', $questions[0]['domain']);
    }

    public function testSupportsPairMode(): void
    {
        $this->assertTrue($this->module->supportsPairMode());
    }

    public function testDoesNotRequireDemographics(): void
    {
        $req = $this->module->getDemographicsRequirements();
        $this->assertFalse($req['gender']);
        $this->assertFalse($req['age']);
    }

    /**
     * Все ответы 10 → total=160, level=satisfied.
     */
    public function testAllMaxAnswersProducesSatisfied(): void
    {
        $answers = $this->allAnswers(self: 10, partner: 10);
        $r = $this->module->calculateResults($answers);

        $this->assertSame(160, $r['total_self']);
        $this->assertSame(160, $r['total_partner']);
        $this->assertSame('satisfied', $r['level']);
        $this->assertSame(160, $r['max_score']);
        $this->assertSame(16, $r['total_questions']);
        $this->assertSame(16, $r['answered_count']);
        // При равных self=partner gaps = 0
        foreach ($r['perception_gaps'] as $gap) {
            $this->assertSame(0, $gap);
        }
    }

    /**
     * Все ответы 1 → total=16, level=dissatisfied.
     */
    public function testAllMinAnswersProducesDissatisfied(): void
    {
        $answers = $this->allAnswers(self: 1, partner: 1);
        $r = $this->module->calculateResults($answers);

        $this->assertSame(16, $r['total_self']);
        $this->assertSame('dissatisfied', $r['level']);
    }

    /**
     * Perception gap: self=8, partner=5 → gap=3.
     */
    public function testPerceptionGapIsSelfMinusPartner(): void
    {
        $answers = $this->allAnswers(self: 8, partner: 5);
        $r = $this->module->calculateResults($answers);

        $this->assertSame(3, $r['perception_gaps'][1], 'Gap for item 1 should be 8-5=3');
        $this->assertSame(3, $r['perception_gaps'][16], 'Gap for item 16 should be 3');
    }

    public function testGenerateInterpretationIncludesSummaryAndRecommendations(): void
    {
        $r = $this->module->calculateResults($this->allAnswers(self: 4, partner: 4));
        $interp = $this->module->generateInterpretation($r);

        $this->assertArrayHasKey('summary', $interp);
        $this->assertStringContainsString('64', $interp['summary']);
        $this->assertArrayHasKey('recommendations', $interp);
        $this->assertNotEmpty($interp['recommendations']);
    }

    public function testGenerateInterpretationFlagsWeakDomains(): void
    {
        // Все self=4 (<=5) → должны попасть в слабые домены
        $r = $this->module->calculateResults($this->allAnswers(self: 4, partner: 8));
        $interp = $this->module->generateInterpretation($r);

        $joined = implode(' ', $interp['recommendations']);
        $this->assertStringContainsString('Зоны для внимания', $joined);
        $this->assertStringContainsString('Общение', $joined);
    }

    public function testBuildSectionsStructure(): void
    {
        $r = $this->module->calculateResults($this->allAnswers(self: 7, partner: 7));
        $sections = $this->module->buildSections($r);

        // 4 секции: score_badge, scales_table, interpretation, recommendations + pair_invite (5)
        $this->assertGreaterThanOrEqual(4, count($sections));

        $types = array_map(fn ($s) => $s->type, $sections);
        $this->assertContains(ResultSection::TYPE_SCORE_BADGE, $types);
        $this->assertContains(ResultSection::TYPE_SCALES_TABLE, $types);
        $this->assertContains(ResultSection::TYPE_INTERPRETATION, $types);
        $this->assertContains(ResultSection::TYPE_RECOMMENDATIONS, $types);

        // Score badge data
        $badge = $sections[0];
        $this->assertSame(112, $badge->data['score']); // 7*16
        $this->assertSame(160, $badge->data['max']);
        $this->assertSame('satisfied', $badge->data['level']);
    }

    public function testBuildSectionsShowsPairInviteWhenNoComparison(): void
    {
        $r = $this->module->calculateResults($this->allAnswers(self: 7, partner: 7));
        $sections = $this->module->buildSections($r);

        // Должна быть секция с приглашением партнёра (нет pair_comparison в results)
        $hasInvite = false;
        foreach ($sections as $s) {
            if ($s->type === ResultSection::TYPE_RAW_HTML && $s->title === 'Пригласить партнёра') {
                $hasInvite = true;
                break;
            }
        }
        $this->assertTrue($hasInvite, 'Pair invite section should be present when no comparison');
    }

    public function testComparePairResultsProducesItemsAndAgreement(): void
    {
        $r1 = $this->module->calculateResults($this->allAnswers(self: 8, partner: 7));
        $r2 = $this->module->calculateResults($this->allAnswers(self: 6, partner: 9));

        $comparison = $this->module->comparePairResults($r1, $r2);

        $this->assertCount(16, $comparison['items']);
        $this->assertArrayHasKey('overall_agreement', $comparison);
        $this->assertGreaterThan(0, $comparison['overall_agreement']);
        $this->assertLessThanOrEqual(100, $comparison['overall_agreement']);
        $this->assertStringContainsString('согласованность', $comparison['summary']);

        // П1 оценил себя 8, П2 — 6 → difference=2
        $item1 = $comparison['items'][0];
        $this->assertSame(8, $item1['p1_self']);
        $this->assertSame(6, $item1['p2_self']);
        $this->assertSame(2, $item1['difference']);
    }

    public function testComparePairResultsAccuracyShowsPerceptionVsReality(): void
    {
        // П1 думает что П2 ответит 7, реально П2 ответил 9 → accuracy = -2
        $r1 = $this->module->calculateResults($this->allAnswers(self: 8, partner: 7));
        $r2 = $this->module->calculateResults($this->allAnswers(self: 9, partner: 8));

        $comparison = $this->module->comparePairResults($r1, $r2);
        $item1 = $comparison['items'][0];

        $this->assertSame(7, $item1['p1_perception'], 'П1 perception of П2');
        $this->assertSame(-2, $item1['p1_accuracy'], 'П1 accuracy: 7 - 9 = -2');
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
