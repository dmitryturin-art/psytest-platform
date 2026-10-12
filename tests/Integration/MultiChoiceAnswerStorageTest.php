<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\AnswerMerger;
use PsyTest\Core\AnswerNormalizer;
use PsyTest\Core\AnswerValidator;
use PsyTest\Core\Database;
use PsyTest\Core\SessionManager;
use PsyTest\Core\TestInviteService;
use PsyTest\Tests\Fixtures\DemoMulti\DemoMultiChoiceModule;

require_once dirname(__DIR__) . '/fixtures/demo-multi-choice/DemoMultiChoiceModule.php';

/**
 * Ответ «несколько вариантов» в существующем JSON-поле ответов сессии: тот же
 * путь, что у отправки формы и промежуточного сохранения (приведение →
 * проверка → слияние → запись), без изменения схемы БД.
 */
#[Group('database')]
final class MultiChoiceAnswerStorageTest extends TestCase
{
    private Database $db;
    private SessionManager $sessions;
    private DemoMultiChoiceModule $module;
    private string $sessionId;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->sessions = new SessionManager($this->db);
        $this->module = new DemoMultiChoiceModule();
        // Хранилище ответов от методики не зависит: строка каталога нужна только как внешний ключ.
        $testId = (int) $this->db->selectOne('SELECT id FROM tests WHERE slug = ?', ['bdi'])['id'];
        $this->sessionId = $this->sessions->createSession($testId)['id'];
    }

    protected function tearDown(): void
    {
        $this->db->delete('activity_log', 'session_id = ?', [$this->sessionId]);
        $this->db->delete('test_sessions', 'id = ?', [$this->sessionId]);
    }

    public function testDraftSaveStoresReplacesAndClearsTheChoice(): void
    {
        self::assertTrue($this->save(['1' => '2', '2' => ['home', 'work', 'home']]));
        self::assertSame(['1' => '2', '2' => ['work', 'home']], $this->stored());
        self::assertSame(2, TestInviteService::answeredCount($this->stored()));

        // Продолжение после перерыва: новый выбор заменяет прежний целиком.
        self::assertTrue($this->save(['2' => ['none']]));
        self::assertSame(['1' => '2', '2' => ['none']], $this->stored());

        // Снятый выбор: вопрос снова не отвечен, остальные ответы на месте.
        self::assertTrue($this->save(['2' => []]));
        self::assertSame(['1' => '2'], $this->stored());
        self::assertSame(1, TestInviteService::answeredCount($this->stored()));
    }

    public function testSubmitStoresTheListAndRejectsWhatTheClientMustNotSend(): void
    {
        self::assertTrue($this->save(['1' => '2']));

        foreach ([['work', 'none'], ['office'], 'work', [['work']]] as $bad) {
            $all = AnswerMerger::overlay($this->stored(), AnswerNormalizer::fromForm($this->module, ['2' => $bad, '3' => '1']));
            self::assertContains('invalid_answer', AnswerValidator::validate($this->module, $all, true));
        }
        // Пустой выбор форма не присылает вовсе: набор неполон.
        $all = AnswerMerger::overlay($this->stored(), AnswerNormalizer::fromForm($this->module, ['3' => '1']));
        self::assertSame(['incomplete_answers'], AnswerValidator::validate($this->module, $all, true));

        $all = AnswerMerger::overlay($this->stored(), AnswerNormalizer::fromForm($this->module, ['2' => ['leisure', 'work'], '3' => '1']));
        self::assertSame([], AnswerValidator::validate($this->module, $all, true));
        $results = $this->module->calculateResults($all);
        self::assertSame(2, $results['areas']);
        self::assertTrue($this->sessions->finalizeSession($this->sessionId, $all, $results, null));

        $session = $this->sessions->getSessionById($this->sessionId);
        self::assertNotNull($session);
        self::assertSame('completed', $session['status']);
        self::assertSame(['1' => '2', '2' => ['work', 'leisure'], '3' => 1], $session['answers']);
    }

    /**
     * Тот же порядок действий, что в TestController::save().
     *
     * @param array<int|string, mixed> $answers
     */
    private function save(array $answers): bool
    {
        $answers = AnswerNormalizer::fromJson($this->module, AnswerValidator::withoutExtraKeys($this->module, $answers));
        self::assertSame([], AnswerValidator::validatePartial($this->module, $answers));

        return $this->sessions->mergeAnswers($this->sessionId, $answers);
    }

    /** @return array<int|string, mixed> */
    private function stored(): array
    {
        return (array) json_decode($this->rawJson(), true);
    }

    private function rawJson(): string
    {
        return (string) $this->db->selectOne('SELECT answers FROM test_sessions WHERE id = ?', [$this->sessionId])['answers'];
    }
}
