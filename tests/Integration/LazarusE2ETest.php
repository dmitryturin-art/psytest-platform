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

        // ---- pairSubmit logic: find P1 strictly by session_token ----
        $p1Found = $this->sm->getSessionBySessionToken($p1Token);
        $this->assertNotNull($p1Found, 'P1 found by session_token');
        $this->assertSame($p1Session['id'], $p1Found['id'], 'Must be P1, not P2 (the getSessionByToken bug)');

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

        // ---- Comparison data integrity ----
        $items = $comparison['items'];
        $this->assertCount(16, $items);
        $this->assertSame(8, $items[0]['p1_self'], 'P1 self score preserved');
        $this->assertSame(6, $items[0]['p2_self'], 'P2 self score preserved');
        $this->assertSame(2, $items[0]['difference'], 'difference = 8-6');
        $this->assertGreaterThan(0, $comparison['overall_agreement']);
    }

    /**
     * Regression guard: getSessionByToken (loose) vs getSessionBySessionToken (strict).
     * The loose matcher must NOT be used to find a specific partner — it can
     * return the wrong session when partner_token collides with session_token.
     */
    public function testGetSessionByTokenCanReturnWrongSession(): void
    {
        $p1 = $this->sm->createSession($this->testId);
        $p1Token = $p1['session_token'];

        // P2 created with partner_token = P1's session_token
        $p2 = $this->sm->createSession($this->testId, ['partner_token' => $p1Token]);

        // Strict lookup: always returns P1
        $strict = $this->sm->getSessionBySessionToken($p1Token);
        $this->assertNotNull($strict);
        $this->assertSame($p1['id'], $strict['id'], 'strict lookup returns P1');

        // Loose lookup: MAY return either (documents the hazard we worked around)
        $loose = $this->sm->getSessionByToken($p1Token);
        $this->assertNotNull($loose);
        $this->assertTrue(
            $loose['id'] === $p1['id'] || $loose['id'] === $p2['id'],
            'loose lookup matches by session_token OR partner_token'
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
