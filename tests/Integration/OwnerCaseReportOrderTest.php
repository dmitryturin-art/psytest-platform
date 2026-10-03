<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Ai\AiReportContextBuilder;
use PsyTest\Core\Ai\AiReportRepository;
use PsyTest\Core\Ai\AiSettings;
use PsyTest\Core\Ai\Prompt;
use PsyTest\Core\Ai\PromptRegistry;
use PsyTest\Core\Database;
use PsyTest\Core\FormOnce;
use PsyTest\Core\ModuleLoader;
use PsyTest\Core\OwnerCaseReportOrder;
use PsyTest\Core\SessionManager;
use PsyTest\Modules\Lazarus\LazarusModule;

/**
 * «Заказать черновики» и «Заказать заново» в карточке кейса (07.K6b).
 *
 * Второй POST с тем же ключом формы — второй клик того же нажатия: он не
 * ставит задание и не сбрасывает уже отработавшее. Внешний ИИ не вызывается:
 * проверяется только очередь `ai_reports`.
 */
#[Group('database')]
final class OwnerCaseReportOrderTest extends TestCase
{
    private const SLUG = 'lazarus';
    private const MODE = 'individual';

    private Database $db;
    private SessionManager $sessions;
    private string $sessionId = '';

    /** @var array<string, mixed> */
    private array $session = [];

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->sessions = new SessionManager($this->db);
        $this->session = [];
        (new AiSettings($this->db))->setAiEnabled(true);

        $test = $this->db->selectOne('SELECT id FROM tests WHERE slug = ?', [self::SLUG]);
        self::assertIsArray($test, 'Предусловие: методика Лазаруса зарегистрирована.');
        $created = $this->sessions->createSession((int) $test['id']);
        $this->sessionId = (string) $created['id'];

        $module = new LazarusModule();
        $answers = ['gender' => 'female', 'age' => '34'];
        foreach ($module->getQuestions() as $index => $question) {
            $answers[$question['id'] . '_self'] = 3 + ($index % 7);
            $answers[$question['id'] . '_partner'] = 2 + ($index % 5);
        }
        $this->db->update('test_sessions', [
            'status' => 'completed',
            'calculated_results' => json_encode($module->calculateResults($answers), JSON_UNESCAPED_UNICODE),
        ], 'id = ?', [$this->sessionId]);
    }

    protected function tearDown(): void
    {
        if ($this->sessionId !== '') {
            $this->db->delete('ai_reports', 'session_id = ?', [$this->sessionId]);
            $this->db->delete('test_sessions', 'id = ?', [$this->sessionId]);
            $this->sessionId = '';
        }
        $this->db->delete('ai_settings', 'setting_key = ?', [AiSettings::KEY_ENABLED]);
    }

    public function testDoubleSubmitQueuesEachKindOnceAndRepeatsTheAnswer(): void
    {
        $keys = $this->order()->issueKeys();
        $post = ['form_key' => $keys['all'], 'ai_consent' => '1', 'owner_context' => ''];

        $first = $this->order()->submit($this->sessionId, self::SLUG, self::MODE, $post);
        $second = $this->order()->submit($this->sessionId, self::SLUG, self::MODE, $post);

        self::assertSame('success', $first['type']);
        self::assertSame(2, $first['queued']);
        self::assertFalse($first['replayed']);
        self::assertSame($first['message'], $second['message'], 'Второй клик получает тот же ответ.');
        self::assertSame('success', $second['type']);
        self::assertSame(0, $second['queued'], 'Повтор ничего не запускает.');
        self::assertTrue($second['replayed']);
        self::assertSame(2, $this->reportCount());
    }

    public function testRepeatedReorderDoesNotResetAFinishedJobButAFreshFormDoes(): void
    {
        $this->order()->submit($this->sessionId, self::SLUG, self::MODE, [
            'form_key' => $this->order()->issueKeys()['all'],
            'ai_consent' => '1',
        ]);
        $this->finish(Prompt::KIND_CLEAR);

        // «Заказать заново» у готового черновика: задание снова в очереди.
        $reorder = [
            'form_key' => $this->order()->issueKeys()[Prompt::KIND_CLEAR],
            'ai_consent' => '1',
            'kind' => Prompt::KIND_CLEAR,
        ];
        $first = $this->order()->submit($this->sessionId, self::SLUG, self::MODE, $reorder);
        self::assertSame(1, $first['queued']);
        self::assertSame(AiReportRepository::STATUS_PENDING, $this->jobStatus(Prompt::KIND_CLEAR));

        // Воркер успел отработать до второго клика: дубль не сбрасывает готовый
        // черновик и не ставит второй платный прогон.
        $this->finish(Prompt::KIND_CLEAR);
        $second = $this->order()->submit($this->sessionId, self::SLUG, self::MODE, $reorder);
        self::assertSame(0, $second['queued']);
        self::assertTrue($second['replayed']);
        self::assertSame(AiReportRepository::STATUS_READY, $this->jobStatus(Prompt::KIND_CLEAR));

        // Сознательный повторный заказ со свежей страницы работает как прежде.
        $again = $this->order()->submit($this->sessionId, self::SLUG, self::MODE, [
            'form_key' => $this->order()->issueKeys()[Prompt::KIND_CLEAR],
            'ai_consent' => '1',
            'kind' => Prompt::KIND_CLEAR,
        ]);
        self::assertSame(1, $again['queued']);
        self::assertSame(AiReportRepository::STATUS_PENDING, $this->jobStatus(Prompt::KIND_CLEAR));
        self::assertSame(2, $this->reportCount(), 'Перезаказ обновляет задание, а не плодит строки.');
    }

    public function testOrderWithoutOrWithAnUnknownKeyQueuesNothing(): void
    {
        $this->order()->issueKeys();

        foreach ([null, str_repeat('cd', 16)] as $key) {
            $post = ['ai_consent' => '1'];
            if ($key !== null) {
                $post['form_key'] = $key;
            }
            $outcome = $this->order()->submit($this->sessionId, self::SLUG, self::MODE, $post);
            self::assertSame('error', $outcome['type']);
            self::assertSame(0, $outcome['queued']);
        }
        self::assertSame(0, $this->reportCount());
    }

    public function testRejectedOrderKeepsTheKeyUsable(): void
    {
        $key = $this->order()->issueKeys()['all'];

        $noConsent = $this->order()->submit($this->sessionId, self::SLUG, self::MODE, ['form_key' => $key]);
        $tooLong = $this->order()->submit($this->sessionId, self::SLUG, self::MODE, [
            'form_key' => $key,
            'ai_consent' => '1',
            'owner_context' => str_repeat('я', OwnerCaseReportOrder::OWNER_CONTEXT_MAX_LENGTH + 1),
        ]);
        self::assertSame('error', $noConsent['type']);
        self::assertStringContainsString('подтвердить передачу', $noConsent['message']);
        self::assertSame('error', $tooLong['type']);
        self::assertSame(0, $this->reportCount());

        $fixed = $this->order()->submit($this->sessionId, self::SLUG, self::MODE, ['form_key' => $key, 'ai_consent' => '1']);
        self::assertSame(2, $fixed['queued'], 'Исправленная форма проходит с тем же ключом.');
    }

    public function testEachOrderFormOnThePageGetsItsOwnKey(): void
    {
        $keys = $this->order()->issueKeys();

        self::assertSame(['all', Prompt::KIND_CLEAR, Prompt::KIND_PROFESSIONAL], array_keys($keys));
        self::assertCount(3, array_unique($keys));

        // Заказ одного вида не гасит соседнюю форму той же отрисовки.
        $clear = $this->order()->submit($this->sessionId, self::SLUG, self::MODE, [
            'form_key' => $keys[Prompt::KIND_CLEAR], 'ai_consent' => '1', 'kind' => Prompt::KIND_CLEAR,
        ]);
        $professional = $this->order()->submit($this->sessionId, self::SLUG, self::MODE, [
            'form_key' => $keys[Prompt::KIND_PROFESSIONAL], 'ai_consent' => '1', 'kind' => Prompt::KIND_PROFESSIONAL,
        ]);
        self::assertSame(1, $clear['queued']);
        self::assertSame(1, $professional['queued']);
        self::assertSame(2, $this->reportCount());
    }

    private function order(): OwnerCaseReportOrder
    {
        $settings = new AiSettings($this->db);

        return new OwnerCaseReportOrder(
            new AiReportRepository($this->db),
            PromptRegistry::default($this->db),
            $settings,
            new AiReportContextBuilder($this->sessions, (new ModuleLoader(null, $this->db))->discover(), $settings),
            new FormOnce($this->session),
        );
    }

    private function finish(string $kind): void
    {
        $this->db->execute(
            'UPDATE ai_reports SET status = :status, content = :content WHERE session_id = :session_id AND report_kind = :kind',
            ['status' => AiReportRepository::STATUS_READY, 'content' => 'Готово.', 'session_id' => $this->sessionId, 'kind' => $kind],
        );
    }

    private function jobStatus(string $kind): string
    {
        $row = $this->db->selectOne(
            'SELECT status FROM ai_reports WHERE session_id = ? AND report_kind = ?',
            [$this->sessionId, $kind],
        );
        self::assertIsArray($row);

        return (string) $row['status'];
    }

    private function reportCount(): int
    {
        return (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM ai_reports WHERE session_id = ?', [$this->sessionId])['n'];
    }
}
