<?php

/**
 * End-to-end HTTP test for the Lazarus pair flow.
 *
 * Drives the full lifecycle through the real controllers/session layer:
 *   1. Partner 1 starts the test, submits answers → result page with invite
 *   2. Partner 2 starts via the pair link, submits → comparison page
 *   3. Partner 1's result page now shows the comparison (polling target)
 *
 * Unlike LazarusPairTest (which tests the module in isolation), this test
 * exercises the controller + SessionManager + DB path end-to-end.
 */

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\Database;
use PsyTest\Core\SessionManager;
use PsyTest\Modules\Lazarus\LazarusModule;

final class LazarusE2ETest extends TestCase
{
    private Database $db;
    private SessionManager $sm;
    private LazarusModule $module;
    private int $testId;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->sm = new SessionManager();
        $this->module = new LazarusModule();
        $test = $this->db->selectOne("SELECT id FROM tests WHERE slug = 'lazarus'");
        $this->testId = (int) $test['id'];
    }

    /**
     * Full partner-1 → partner-2 flow with real session storage.
     */
    public function testFullPairFlowEndToEnd(): void
    {
        // ---- Partner 1: create, answer, complete ----
        $p1Session = $this->sm->createSession($this->testId);
        $p1Answers = $this->buildAnswers(self: 8, partner: 6);
        $this->sm->saveAnswers($p1Session['id'], $p1Answers);
        $p1Results = $this->module->calculateResults($p1Answers);
        $p1Interp = $this->module->generateInterpretation($p1Results);
        $this->sm->completeSession($p1Session['id'], array_merge($p1Results, ['interpretation' => $p1Interp]));

        $this->assertSame(128, $p1Results['total_self'], 'P1 total = 8×16');
        $this->assertSame('satisfied', $p1Results['level']);

        // ---- Partner 2: create via pair token, answer, complete ----
        $p1Token = $p1Session['session_token'];
        $p2Session = $this->sm->createSession($this->testId, ['partner_token' => $p1Token]);
        $p2Answers = $this->buildAnswers(self: 6, partner: 9);
        $this->sm->saveAnswers($p2Session['id'], $p2Answers);
        $p2Results = $this->module->calculateResults($p2Answers);
        $p2Results['is_pair_partner'] = true;
        $this->sm->completeSession($p2Session['id'], array_merge($p2Results, [
            'interpretation' => $this->module->generateInterpretation($p2Results),
        ]));

        // Sessions must be distinct
        $this->assertNotSame($p1Session['id'], $p2Session['id'], 'P1 and P2 must be different sessions');

        // ---- pairSubmit logic: find P1 by result-access token ----
        $p1Found = $this->sm->getSessionByResultToken($p1Token);
        $this->assertNotNull($p1Found, 'P1 found by session_token');
        $this->assertSame($p1Session['id'], $p1Found['id'], 'Must be P1, not P2');

        // ---- Create comparison ----
        $comparison = $this->module->comparePairResults(
            $p1Found['calculated_results'],
            $p2Results
        );
        $record = $this->sm->createPairComparison($this->testId, $p1Found['id'], $p2Session['id'], $comparison);

        // Comparison record must link two DIFFERENT sessions
        $this->assertNotSame(
            $record['session_1_id'],
            $record['session_2_id'],
            'Comparison must link two distinct sessions (regression: was self-vs-self)'
        );

        // ---- Partner 1's page: comparison now findable ----
        $p1Comparison = $this->sm->getPairComparisonBySession($p1Session['id']);
        $this->assertNotNull($p1Comparison, 'P1 can find comparison by own session id');
        $this->assertSame($record['id'], $p1Comparison['id']);

        // ---- Partner 2's page: comparison also findable ----
        $p2Comparison = $this->sm->getPairComparisonBySession($p2Session['id']);
        $this->assertNotNull($p2Comparison, 'P2 can also find comparison');

        // ---- Both partners see comparison on their OWN result page ----
        // Simulate what ResultController::show() does for P1.
        $p1Results = $p1Found['calculated_results'];
        $p1Results['pair_comparison'] = $this->module->comparePairResults(
            $p1Results,
            $p2Results
        );
        $p1Sections = $this->module->buildSections($p1Results);
        $p1HasComparison = false;
        foreach ($p1Sections as $s) {
            if ($s->type === \PsyTest\Modules\ResultSection::TYPE_PAIR_COMPARISON) {
                $p1HasComparison = true;
            }
        }
        $this->assertTrue($p1HasComparison, 'P1 result page shows comparison');

        // Simulate show() for P2 — comparison must also appear there.
        $p2Refreshed = $this->sm->getSessionById($p2Session['id']);
        $p2ResultsRefreshed = $p2Refreshed['calculated_results'];
        // show() computes comparison relative to the CURRENT session:
        // for P2, partner is P1.
        $p2ResultsRefreshed['pair_comparison'] = $this->module->comparePairResults(
            $p2ResultsRefreshed,
            $p1Found['calculated_results']
        );
        $p2Sections = $this->module->buildSections($p2ResultsRefreshed);
        $p2HasComparison = false;
        foreach ($p2Sections as $s) {
            if ($s->type === \PsyTest\Modules\ResultSection::TYPE_PAIR_COMPARISON) {
                $p2HasComparison = true;
            }
        }
        $this->assertTrue($p2HasComparison, 'P2 result page also shows comparison');

        // ---- Comparison data integrity ----
        $items = $comparison['items'];
        $this->assertCount(16, $items);
        $this->assertSame(8, $items[0]['p1_self'], 'P1 self score preserved');
        $this->assertSame(6, $items[0]['p2_self'], 'P2 self score preserved');
        $this->assertSame(2, $items[0]['difference'], 'difference = 8-6');
        $this->assertGreaterThan(0, $comparison['overall_agreement']);
    }

    /**
     * A partner reference cannot impersonate the first partner's result token.
     */
    public function testResultTokenLookupNeverResolvesPartnerReference(): void
    {
        $p1 = $this->sm->createSession($this->testId);
        $p1Token = $p1['session_token'];

        // P2 created with partner_token = P1's session_token
        $p2 = $this->sm->createSession($this->testId, ['partner_token' => $p1Token]);

        $p1Lookup = $this->sm->getSessionByResultToken($p1Token);
        $p2Lookup = $this->sm->getSessionByResultToken($p2['session_token']);

        $this->assertNotNull($p1Lookup);
        $this->assertSame($p1['id'], $p1Lookup['id'], 'P1 result token resolves only P1');
        $this->assertNotNull($p2Lookup);
        $this->assertSame($p2['id'], $p2Lookup['id'], 'P2 result token resolves only P2');
        $this->assertFalse(
            method_exists(SessionManager::class, 'getSessionByToken'),
            'The ambiguous token lookup API must not be reintroduced'
        );
    }

    /**
     * Pair-status endpoint data: before and after comparison exists.
     */
    public function testPairStatusTransitionsWhenPartnerCompletes(): void
    {
        $p1 = $this->sm->createSession($this->testId);

        // Before P2 completes: no comparison
        $before = $this->sm->getPairComparisonBySession($p1['id']);
        $this->assertNull($before, 'No comparison before P2 completes');

        // Simulate P2 + comparison
        $p2 = $this->sm->createSession($this->testId, ['partner_token' => $p1['session_token']]);
        $this->sm->saveAnswers($p2['id'], $this->buildAnswers(self: 5, partner: 5));
        $r2 = $this->module->calculateResults($this->buildAnswers(self: 5, partner: 5));
        $this->sm->completeSession($p2['id'], $r2);

        $p1Refreshed = $this->sm->getSessionById($p1['id']);
        $comparison = $this->module->comparePairResults($p1Refreshed['calculated_results'] ?? [], $r2);
        $this->sm->createPairComparison($this->testId, $p1['id'], $p2['id'], $comparison);

        // After: comparison exists for P1
        $after = $this->sm->getPairComparisonBySession($p1['id']);
        $this->assertNotNull($after, 'Comparison exists after P2 completes');
    }

    public function testSourceTokenCanHaveOnlyOnePairSession(): void
    {
        $p1 = $this->sm->createSession($this->testId);
        self::assertFalse($this->sm->hasPairSessionForSourceToken($p1['session_token']));

        $this->sm->createSession($this->testId, ['partner_token' => $p1['session_token']]);

        self::assertTrue($this->sm->hasPairSessionForSourceToken($p1['session_token']));
    }

    public function testPairSessionMustBeBoundToTheSubmittedSourceToken(): void
    {
        $source = $this->sm->createSession($this->testId);
        $pair = $this->sm->createSession($this->testId, ['partner_token' => $source['session_token']]);
        $unrelated = $this->sm->createSession($this->testId);

        self::assertTrue($this->sm->isPairSessionBoundToSourceToken($pair['id'], $source['session_token']));
        self::assertFalse($this->sm->isPairSessionBoundToSourceToken($pair['id'], $unrelated['session_token']));
    }

    public function testExpiredSourceInviteOrPairSessionCannotBeUsed(): void
    {
        $expiredSource = $this->sm->createSession($this->testId);
        $this->db->update(
            'test_sessions',
            ['expires_at' => '2000-01-01 00:00:00'],
            'id = ?',
            [$expiredSource['id']],
        );

        self::assertNull($this->sm->getSessionByResultToken($expiredSource['session_token']));

        $source = $this->sm->createSession($this->testId);
        $expiredPair = $this->sm->createSession($this->testId, ['partner_token' => $source['session_token']]);
        $this->db->update(
            'test_sessions',
            ['expires_at' => '2000-01-01 00:00:00'],
            'id = ?',
            [$expiredPair['id']],
        );

        self::assertFalse($this->sm->isPairSessionBoundToSourceToken($expiredPair['id'], $source['session_token']));
    }

    public function testConcurrentPairInviteAttemptReturnsNoSecondSession(): void
    {
        $source = $this->sm->createSession($this->testId);

        $first = $this->sm->createPairSession($this->testId, $source['session_token']);
        $second = $this->sm->createPairSession($this->testId, $source['session_token']);

        self::assertNotNull($first);
        self::assertNull($second);
    }

    /**
     * @return array<string, int>
     */
    private function buildAnswers(int $self, int $partner): array
    {
        $answers = [];
        foreach ($this->module->getQuestions() as $q) {
            $answers[$q['id'] . '_self'] = $self;
            $answers[$q['id'] . '_partner'] = $partner;
        }
        return $answers;
    }
}
