<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Ai\AiReportContextBuilder;
use PsyTest\Core\CaseExportPresenter;
use PsyTest\Core\Database;
use PsyTest\Core\ModuleLoader;
use PsyTest\Core\ResultPresenter;
use PsyTest\Core\RetentionPolicy;
use PsyTest\Core\SessionManager;
use PsyTest\Modules\Lazarus\LazarusModule;

/**
 * Пара в кабинете после истечения ссылки на результат (07.K10).
 *
 * Дефект со стенда: кейс специалиста, привязанный к первому партнёру, через
 * 30 дней (`expires_at`, срок публичной ссылки) стал показываться
 * индивидуальным. Карточка брала кейс без срока, а парную часть — через
 * `getSessionById()`, который считает просроченную сессию несуществующей.
 * Срок ссылки ограничивает доступ по токену, а не хранение кейса.
 */
#[Group('database')]
final class ExpiredLinkPairCaseTest extends TestCase
{
    private Database $db;
    private SessionManager $sessions;
    private LazarusModule $module;
    private string $firstId = '';
    private string $secondId = '';
    private string $comparisonId = '';

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->sessions = new SessionManager($this->db);
        $this->module = new LazarusModule();

        $test = $this->db->selectOne("SELECT id FROM tests WHERE slug = 'lazarus'");
        self::assertIsArray($test, 'Предусловие: методика Лазаруса зарегистрирована.');
        $testId = (int) $test['id'];

        $this->firstId = $this->completedSession($testId, self: 8, partner: 6);
        $this->secondId = $this->completedSession($testId, self: 6, partner: 9);

        $first = $this->sessions->getSessionById($this->firstId);
        $second = $this->sessions->getSessionById($this->secondId);
        self::assertIsArray($first);
        self::assertIsArray($second);
        $record = $this->sessions->createPairComparison(
            $testId,
            $this->firstId,
            $this->secondId,
            $this->module->comparePairResults($first['calculated_results'], $second['calculated_results']),
        );
        $this->comparisonId = (string) $record['id'];

        // Первый партнёр — кейс специалиста, его ссылка истекла.
        $this->db->update('test_sessions', [
            'retention_class' => RetentionPolicy::THERAPIST_CASE,
            'expires_at' => '2026-01-01 00:00:00',
        ], 'id = ?', [$this->firstId]);
    }

    protected function tearDown(): void
    {
        if ($this->comparisonId !== '') {
            $this->db->delete('pair_comparisons', 'id = ?', [$this->comparisonId]);
        }
        foreach ([$this->firstId, $this->secondId] as $id) {
            if ($id !== '') {
                $this->db->delete('test_sessions', 'id = ?', [$id]);
            }
        }
        $this->comparisonId = '';
        $this->firstId = '';
        $this->secondId = '';
    }

    public function testTheExpiredLinkStillClosesTokenAccessButNotTheStoredRecord(): void
    {
        self::assertNull($this->sessions->getSessionById($this->firstId));

        $session = $this->sessions->getRetainedSessionById($this->firstId);
        self::assertIsArray($session);
        self::assertSame($this->firstId, $session['id']);
        self::assertIsArray($session['calculated_results']);
        self::assertNotSame([], $session['calculated_results']);
    }

    public function testTheOwnerCardKeepsThePairWhenTheCaseLinkExpired(): void
    {
        $session = $this->sessions->getRetainedSessionById($this->firstId);
        self::assertIsArray($session);

        $presenter = new ResultPresenter($this->db, $this->sessions);
        self::assertSame('pair', $presenter->reportMode($session));

        $pair = $presenter->pairViewData($session, $this->module);
        self::assertIsArray($pair);
        self::assertSame(1, $pair['position']);
        $types = array_map(static fn ($section): string => $section->type, $pair['sections']);
        self::assertContains('pair_comparison', $types);
    }

    public function testThePairViewSurvivesAnExpiredPartnerToo(): void
    {
        $this->db->update('test_sessions', ['expires_at' => '2026-01-01 00:00:00'], 'id = ?', [$this->secondId]);
        $session = $this->sessions->getRetainedSessionById($this->firstId);
        self::assertIsArray($session);

        $pair = (new ResultPresenter($this->db, $this->sessions))->pairViewData($session, $this->module);
        self::assertIsArray($pair);
        self::assertSame($this->secondId, $pair['partner_session_id']);
    }

    public function testTheCaseExportCarriesThePairAfterTheLinkExpired(): void
    {
        $session = $this->sessions->getRetainedSessionById($this->firstId);
        self::assertIsArray($session);
        $case = array_merge($session, ['test_name' => 'Опросник Лазаруса', 'client_label' => 'Клиент', 'owner_note' => null]);

        $document = (new CaseExportPresenter($this->db, $this->sessions))->build(
            $case,
            $this->module,
            CaseExportPresenter::options([]),
        );

        self::assertIsArray($document['pair']);
    }

    public function testTheAiPairContextIsBuiltAfterTheLinkExpired(): void
    {
        $context = (new AiReportContextBuilder($this->sessions, (new ModuleLoader(null, $this->db))->discover()))
            ->build($this->firstId, 'lazarus', 'pair');

        self::assertNotSame([], $context);
    }

    public function testThePartnerPageStillComparesWithTheExpiredFirstPartner(): void
    {
        $second = $this->sessions->getSessionById($this->secondId);
        self::assertIsArray($second);

        $results = (new ResultPresenter($this->db, $this->sessions))->results($second, $this->module);

        self::assertIsArray($results['pair_comparison'] ?? null);
        self::assertSame(8, $results['pair_comparison']['items'][0]['p1_self']);
    }

    private function completedSession(int $testId, int $self, int $partner): string
    {
        $session = $this->sessions->createSession($testId);
        $id = (string) $session['id'];

        $answers = [];
        foreach ($this->module->getQuestions() as $question) {
            $answers[(string) $question['id'] . '_self'] = $self;
            $answers[(string) $question['id'] . '_partner'] = $partner;
        }

        $this->db->update('test_sessions', [
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
            'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
            'calculated_results' => json_encode($this->module->calculateResults($answers), JSON_UNESCAPED_UNICODE),
        ], 'id = ?', [$id]);

        return $id;
    }
}
