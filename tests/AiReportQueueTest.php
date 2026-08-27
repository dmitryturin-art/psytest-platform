<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Ai\AiCompletion;
use PsyTest\Core\Ai\AiReportRepository;
use PsyTest\Core\Ai\Prompt;
use PsyTest\Core\Database;
use PsyTest\Core\SessionManager;

/**
 * Очередь заданий на разбор.
 *
 * Разбор делается около двух минут, поэтому веб-запрос его не ждёт. Здесь
 * стерегутся свойства очереди, без которых фоновая работа ломается: задание
 * не задваивается, не берётся дважды и не остаётся висеть навсегда.
 */
#[Group('database')]
final class AiReportQueueTest extends TestCase
{
    private Database $db;
    private AiReportRepository $reports;
    private string $sessionId = '';

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->reports = new AiReportRepository($this->db);

        $test = $this->db->selectOne("SELECT id FROM tests WHERE slug = 'lazarus'");
        self::assertIsArray($test, 'Предусловие: методика Лазаруса зарегистрирована.');

        $session = (new SessionManager($this->db))->createSession((int) $test['id']);
        $this->sessionId = (string) $session['id'];
    }

    protected function tearDown(): void
    {
        if ($this->sessionId !== '') {
            // Каскад уносит и разборы вместе с сессией — заодно проверяется он же.
            $this->db->delete('test_sessions', 'id = ?', [$this->sessionId]);
        }
    }

    private function prompt(): Prompt
    {
        return new Prompt('lazarus', 'individual', 'clear', 2, Prompt::STATUS_PUBLISHED, 'текст', false, 'test');
    }

    private function request(): array
    {
        return $this->reports->request($this->sessionId, 'lazarus', 'individual', 'clear', $this->prompt());
    }

    public function testRepeatedRequestReusesTheSameJobInsteadOfDuplicating(): void
    {
        $first = $this->request();
        $second = $this->request();

        self::assertSame($first['id'], $second['id'], 'Повторное нажатие кнопки не должно плодить задания.');
        self::assertSame(AiReportRepository::STATUS_PENDING, $second['status']);
    }

    public function testJobIsClaimedOnlyOnce(): void
    {
        $this->request();

        $first = $this->reports->claimNext();
        self::assertIsArray($first);
        self::assertSame(AiReportRepository::STATUS_RUNNING, $first['status']);
        self::assertSame(1, (int) $first['attempts']);

        // Второй запуск cron не должен взять ту же работу.
        $again = $this->reports->claimNext();
        if ($again !== null) {
            self::assertNotSame($first['id'], $again['id'], 'Одно задание взято дважды.');
        } else {
            self::assertNull($again);
        }
    }

    public function testFailedJobCanBeAskedAgainWhileAttemptsRemain(): void
    {
        $job = $this->request();
        $this->reports->markFailed((string) $job['id'], 'провайдер недоступен');

        $failed = $this->reports->find((string) $job['id']);
        self::assertSame(AiReportRepository::STATUS_FAILED, $failed['status']);
        self::assertSame('провайдер недоступен', $failed['failure_reason']);

        $again = $this->request();
        self::assertSame($job['id'], $again['id']);
        self::assertSame(AiReportRepository::STATUS_PENDING, $again['status']);
        self::assertNull($again['failure_reason'], 'Прежняя причина отказа не должна оставаться на новой попытке.');
    }

    public function testExhaustedJobIsNotPickedUpForever(): void
    {
        $job = $this->request();
        $this->db->update('ai_reports', ['attempts' => AiReportRepository::MAX_ATTEMPTS], 'id = ?', [$job['id']]);

        $claimed = $this->reports->claimNext();

        if ($claimed !== null) {
            self::assertNotSame($job['id'], $claimed['id'], 'Исчерпавшее попытки задание не должно браться снова.');
        } else {
            self::assertNull($claimed);
        }
    }

    public function testStuckJobReturnsToTheQueue(): void
    {
        // Обработчик может умереть посреди вызова: задание иначе останется
        // в работе навсегда и не повторится никогда.
        $job = $this->request();
        $this->db->execute(
            "UPDATE ai_reports SET status = 'running', updated_at = (NOW() - INTERVAL 2 HOUR) WHERE id = ?",
            [$job['id']],
        );

        $released = $this->reports->releaseStuck(30);

        self::assertContains($job['id'], array_column($released, 'id'));
        self::assertSame(AiReportRepository::STATUS_PENDING, $this->reports->find((string) $job['id'])['status']);
    }

    public function testReadyJobKeepsWhatIsNeededToExplainTheReport(): void
    {
        $job = $this->request();

        $this->reports->markReady((string) $job['id'], new AiCompletion(
            text: 'Готовый разбор.',
            requestedModel: 'openrouter/free',
            servedModel: 'qwen/qwen3.7-plus',
            promptTokens: 2000,
            completionTokens: 5000,
        ));

        $ready = $this->reports->find((string) $job['id']);

        self::assertSame(AiReportRepository::STATUS_READY, $ready['status']);
        self::assertSame('Готовый разбор.', $ready['content']);
        // Запрошенная и фактически ответившая модель хранятся раздельно:
        // без второй отчёт нельзя ни повторить, ни объяснить.
        self::assertSame('openrouter/free', $ready['requested_model']);
        self::assertSame('qwen/qwen3.7-plus', $ready['served_model']);
        self::assertSame('lazarus | individual | clear', $ready['prompt_key']);
        self::assertSame(2, (int) $ready['prompt_version']);
        self::assertNotNull($ready['completed_at']);
    }

    public function testDeletingTheSessionRemovesItsReports(): void
    {
        $job = $this->request();
        self::assertNotNull($this->reports->find((string) $job['id']));

        $this->db->delete('test_sessions', 'id = ?', [$this->sessionId]);
        $this->sessionId = '';

        self::assertNull(
            $this->reports->find((string) $job['id']),
            'Разбор — клинический документ сессии и обязан уходить вместе с ней.',
        );
    }
}
