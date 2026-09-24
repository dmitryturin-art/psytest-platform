<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use PsyTest\Core\Ai\AiReportContextBuilder;
use PsyTest\Core\Database;
use PsyTest\Core\ModuleLoader;
use PsyTest\Core\ResultPresenter;
use PsyTest\Core\ResultRefresher;
use PsyTest\Core\SessionManager;
use PsyTest\Modules\Smil\SmilModule;

/**
 * Сохранённые результаты СМИЛ на прежнем реестре дополнительных шкал (05.S4a).
 *
 * Кейс, пройденный до расширения реестра, хранит 35 дополнительных шкал. При
 * чтении через единую точку он получает все 105, обновление записывается в БД
 * один раз, базовый расчёт не меняется. Без ответов пересчитывать не из чего.
 */
#[Group('database')]
final class SmilAdditionalScoresRefreshTest extends TestCase
{
    private Database $db;
    private SessionManager $sessions;
    private SmilModule $smil;
    /** @var list<string> */
    private array $sessionIds = [];

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->sessions = new SessionManager($this->db);
        $this->smil = new SmilModule();
    }

    protected function tearDown(): void
    {
        foreach ($this->sessionIds as $id) {
            $this->db->delete('activity_log', 'session_id = ?', [$id]);
            $this->db->delete('test_sessions', 'id = ?', [$id]);
        }
        $this->sessionIds = [];
    }

    public function testStaleSessionIsRefreshedOnceAndTheCoreIsUntouched(): void
    {
        $id = $this->staleSession(withAnswers: true);
        $storedBefore = $this->storedResults($id);
        self::assertCount(35, $storedBefore['additional_scores']);

        $session = $this->sessions->withFreshResults($this->sessionById($id), $this->smil);

        self::assertCount(105, $session['calculated_results']['additional_scores']);
        $storedAfter = $this->storedResults($id);
        self::assertCount(105, $storedAfter['additional_scores']);
        foreach ($storedBefore as $key => $value) {
            if ($key === 'additional_scores') {
                continue;
            }
            self::assertSame($value, $storedAfter[$key], "Блок {$key} не должен меняться");
        }

        // Повторное чтение: набор уже актуален, записи нет.
        $rawAfterFirst = $this->rawResults($id);
        $second = (new ResultRefresher($this->db, new NullLogger()))->apply($this->sessionById($id), $this->smil);
        self::assertSame(ResultRefresher::CURRENT, $second['status']);
        self::assertSame($rawAfterFirst, $this->rawResults($id));
    }

    public function testSessionWithoutAnswersIsLeftAsIs(): void
    {
        $id = $this->staleSession(withAnswers: false);
        $rawBefore = $this->rawResults($id);

        $outcome = (new ResultRefresher($this->db, new NullLogger()))->apply($this->sessionById($id), $this->smil);

        self::assertSame(ResultRefresher::NO_ANSWERS, $outcome['status']);
        self::assertCount(35, $outcome['session']['calculated_results']['additional_scores']);
        self::assertSame($rawBefore, $this->rawResults($id));
    }

    public function testRefreshFailureKeepsTheStoredResultVisible(): void
    {
        $id = $this->staleSession(withAnswers: true);
        $rawBefore = $this->rawResults($id);
        $failing = new class () extends SmilModule {
            public function refreshAdditionalScores(array $results, array $answers): array
            {
                throw new \RuntimeException('boom');
            }
        };

        $outcome = (new ResultRefresher($this->db, new NullLogger()))->apply($this->sessionById($id), $failing);

        self::assertSame(ResultRefresher::FAILED, $outcome['status']);
        self::assertCount(35, $outcome['session']['calculated_results']['additional_scores']);
        self::assertSame($rawBefore, $this->rawResults($id));
    }

    public function testResultPageAndAiContextSeeTheFullRegistry(): void
    {
        $pageId = $this->staleSession(withAnswers: true);
        $results = (new ResultPresenter($this->db, $this->sessions))->results($this->sessionById($pageId), $this->smil);
        self::assertCount(105, $results['additional_scores']);

        $aiId = $this->staleSession(withAnswers: true);
        $context = (new AiReportContextBuilder($this->sessions, (new ModuleLoader(null, $this->db))->discover()))
            ->build($aiId, 'smil', 'individual');
        self::assertCount(105, $context['additional_scales']);
        self::assertCount(105, $this->storedResults($aiId)['additional_scores']);
    }

    public function testBulkRefreshCountsAndDryRunWritesNothing(): void
    {
        $staleId = $this->staleSession(withAnswers: true);
        $this->staleSession(withAnswers: false);
        $rawBefore = $this->rawResults($staleId);
        $refresher = new ResultRefresher($this->db, new NullLogger());

        $dry = $refresher->refreshAll('smil', $this->smil, dryRun: true);
        self::assertGreaterThanOrEqual(1, $dry['updated']);
        self::assertGreaterThanOrEqual(1, $dry['no_answers']);
        self::assertSame($rawBefore, $this->rawResults($staleId));

        $real = $refresher->refreshAll('smil', $this->smil);
        self::assertSame($dry['updated'], $real['updated']);
        self::assertCount(105, $this->storedResults($staleId)['additional_scores']);

        $again = $refresher->refreshAll('smil', $this->smil);
        self::assertSame(0, $again['updated']);
    }

    private function staleSession(bool $withAnswers): string
    {
        $test = $this->db->selectOne("SELECT id FROM tests WHERE slug = 'smil'");
        self::assertIsArray($test, 'Предусловие: методика СМИЛ зарегистрирована.');

        $answers = ['gender' => 'female'];
        foreach ($this->smil->getQuestions() as $index => $question) {
            $answers[(string) ($question['id'] ?? $index + 1)] = $index % 3 === 0 ? 1 : 0;
        }
        $results = $this->smil->calculateResults($answers);
        $results['interpretation'] = $this->smil->generateInterpretation($results);
        // Результат, сохранённый до расширения реестра: 35 дополнительных шкал.
        $results['additional_scores'] = array_slice($results['additional_scores'], 0, 35, true);

        $session = $this->sessions->createSession((int) $test['id']);
        $id = (string) $session['id'];
        $this->sessionIds[] = $id;
        $this->db->update('test_sessions', [
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
            'answers' => json_encode($withAnswers ? $answers : []),
            'calculated_results' => json_encode($results),
        ], 'id = ?', [$id]);

        return $id;
    }

    /** @return array<string, mixed> */
    private function sessionById(string $id): array
    {
        $session = $this->sessions->getSessionById($id);
        self::assertIsArray($session);

        return $session;
    }

    private function rawResults(string $id): string
    {
        $row = $this->db->selectOne('SELECT calculated_results FROM test_sessions WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return (string) $row['calculated_results'];
    }

    /** @return array<string, mixed> */
    private function storedResults(string $id): array
    {
        return json_decode($this->rawResults($id), true, 512, JSON_THROW_ON_ERROR);
    }
}
