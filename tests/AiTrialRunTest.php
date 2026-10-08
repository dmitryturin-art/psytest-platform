<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Ai\AiClient;
use PsyTest\Core\Ai\AiProviderSettings;
use PsyTest\Core\Ai\AiTransport;
use PsyTest\Core\Ai\AiTrialRepository;
use PsyTest\Core\Ai\AiTrialRunner;
use PsyTest\Core\Ai\Prompt;
use PsyTest\Core\Database;

/**
 * Пробный разбор промпта (07.K14a): очередь, а не вызов провайдера в запросе.
 *
 * Прокси хостинга обрывает запрос раньше, чем отвечает модель с большим
 * контекстом (504), поэтому веб-запрос только ставит задание.
 */
#[Group('database')]
final class AiTrialRunTest extends TestCase
{
    private const TEST = 'k14a-test';

    private Database $db;
    private AiTrialRepository $trials;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->trials = new AiTrialRepository($this->db);
        $this->db->execute('DELETE FROM ai_trial_runs WHERE test_slug = ?', [self::TEST]);
    }

    protected function tearDown(): void
    {
        $this->db->execute('DELETE FROM ai_trial_runs WHERE test_slug = ?', [self::TEST]);
    }

    private function root(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/' . $path);
    }

    private function prompt(): Prompt
    {
        return new Prompt(self::TEST, 'individual', 'clear', 3, Prompt::STATUS_PUBLISHED, 'Текст промпта.', false, 'test');
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return ['test' => self::TEST, 'totals' => ['scale' => 12]];
    }

    private function transport(bool $fail = false): object
    {
        return new class ($fail) implements AiTransport {
            public int $calls = 0;

            public function __construct(private readonly bool $fail)
            {
            }

            public function request(string $method, string $url, array $headers, ?array $body, int $timeoutSeconds): array
            {
                $this->calls++;
                if ($this->fail) {
                    return ['status' => 500, 'body' => '{"error":{"message":"секретная подробность"}}'];
                }

                return ['status' => 200, 'body' => (string) json_encode([
                    'model' => 'fixture/model',
                    'choices' => [['message' => ['content' => 'Пробный текст.']]],
                ])];
            }
        };
    }

    private function runner(object $transport): AiTrialRunner
    {
        return new AiTrialRunner(
            $this->trials,
            new AiClient(new AiProviderSettings('https://provider.invalid/api/v1', 'fixture-key', 'fixture/model', 30), $transport),
        );
    }

    public function testRequestOnlyEnqueuesAndNeverCallsTheProvider(): void
    {
        $transport = $this->transport();
        $row = $this->trials->request(self::TEST, 'individual', 'clear', $this->prompt(), $this->context());

        self::assertSame(AiTrialRepository::STATUS_PENDING, $row['status']);
        self::assertSame(0, $transport->calls);
        self::assertTrue($this->trials->hasActive(self::TEST, 'individual', 'clear'));
    }

    public function testWorkerProcessesATrialExactlyOnce(): void
    {
        $transport = $this->transport();
        $this->trials->request(self::TEST, 'individual', 'clear', $this->prompt(), $this->context());

        $job = $this->trials->claimNext();
        self::assertIsArray($job);
        self::assertNull($this->trials->claimNext(), 'Взятый в работу пробный разбор второй раз не берётся.');

        $this->runner($transport)->process($job);

        self::assertSame(1, $transport->calls);
        $done = $this->trials->find((string) $job['id']);
        self::assertSame(AiTrialRepository::STATUS_READY, $done['status']);
        self::assertSame('Пробный текст.', $done['content']);
        self::assertSame('fixture/model', $done['served_model']);
        self::assertFalse($this->trials->hasActive(self::TEST, 'individual', 'clear'));
    }

    public function testProviderFailureIsRecordedWithoutProviderBody(): void
    {
        $this->trials->request(self::TEST, 'individual', 'clear', $this->prompt(), $this->context());
        $job = $this->trials->claimNext();
        self::assertIsArray($job);

        $this->runner($this->transport(true))->process($job);

        $done = $this->trials->find((string) $job['id']);
        self::assertSame(AiTrialRepository::STATUS_FAILED, $done['status']);
        self::assertStringNotContainsString('секретная подробность', (string) $done['failure_reason']);
    }

    public function testSnapshotHoldsOnlyThePromptAndTheSyntheticContext(): void
    {
        $row = $this->trials->request(self::TEST, 'individual', 'clear', $this->prompt(), $this->context());

        self::assertSame($this->context(), json_decode((string) $row['context_snapshot'], true));
        self::assertSame('Текст промпта.', json_decode((string) $row['prompt_snapshot'], true)['text']);

        $columns = array_column($this->db->select('SHOW COLUMNS FROM ai_trial_runs'), 'Field');
        foreach (['session_id', 'client_id', 'invite_id', 'owner_context'] as $personal) {
            self::assertNotContains($personal, $columns, 'У пробного разбора нет связи с клиентом или сессией.');
        }
    }

    public function testExpiredRowsArePurgedAndHiddenFromThePage(): void
    {
        $row = $this->trials->request(self::TEST, 'individual', 'clear', $this->prompt(), $this->context());
        $this->db->execute('UPDATE ai_trial_runs SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE id = ?', [$row['id']]);

        self::assertNull($this->trials->latestFor(self::TEST, 'individual', 'clear'));
        self::assertGreaterThanOrEqual(1, $this->trials->purgeExpired());
        self::assertNull($this->trials->find((string) $row['id']));
    }

    public function testNewRequestReplacesAFinishedTrialAndDismissDeletesIt(): void
    {
        $first = $this->trials->request(self::TEST, 'individual', 'clear', $this->prompt(), $this->context());
        $this->trials->markFailed((string) $first['id'], 'причина');

        $second = $this->trials->request(self::TEST, 'individual', 'clear', $this->prompt(), $this->context());
        self::assertNull($this->trials->find((string) $first['id']));
        self::assertNotSame($first['id'], $second['id']);

        $this->trials->delete((string) $second['id']);
        self::assertNull($this->trials->latestFor(self::TEST, 'individual', 'clear'));
    }

    public function testStuckTrialIsClosedAsFailed(): void
    {
        $row = $this->trials->request(self::TEST, 'individual', 'clear', $this->prompt(), $this->context());
        $this->db->execute('UPDATE ai_trial_runs SET status = ?, updated_at = NOW() - INTERVAL 30 MINUTE WHERE id = ?', ['running', $row['id']]);

        self::assertGreaterThanOrEqual(1, $this->trials->releaseStuck());
        self::assertSame(AiTrialRepository::STATUS_FAILED, $this->trials->find((string) $row['id'])['status']);
    }

    public function testPostRequestPathNeverCallsTheProvider(): void
    {
        $controller = $this->root('controllers/OwnerController.php');
        $start = strpos($controller, 'public function promptTrial(');
        $end = strpos($controller, 'public function promptTrialStatus(');
        self::assertIsInt($start);
        self::assertIsInt($end);
        $post = substr($controller, $start, $end - $start);

        // В пути запроса провайдер вызывается только после отдачи ответа
        // браузеру (запасной режим без отдельного процесса), через раннер.
        self::assertDoesNotMatchRegularExpression('/(?<!formOnce\(\))->complete\(/', $post);
        self::assertStringContainsString('ResponseFinisher::finish()', $post);
        self::assertGreaterThan(
            strpos($post, 'ResponseFinisher::finish()'),
            strpos($post, '$runner->process('),
            'Раннер запускается только после ответа браузеру.',
        );
        self::assertStringContainsString("FormOnce::REPLAY", $post);
        self::assertStringContainsString('Пробный разбор уже запрошен', $post);
        self::assertStringContainsString("'owner_prompt_trial'", $controller);
        self::assertStringContainsString('#trial-result', $post);
    }

    public function testStatusAndDismissAreOwnerOnlyAndRoutesExist(): void
    {
        $controller = $this->root('controllers/OwnerController.php');
        foreach (['promptTrialStatus', 'promptTrialDismiss'] as $method) {
            $start = strpos($controller, 'public function ' . $method . '(');
            self::assertIsInt($start);
            self::assertStringContainsString('requireOwner()', substr($controller, $start, 400));
        }

        $index = $this->root('public/index.php');
        self::assertStringContainsString("get('/admin/tests/{test}/prompts/{mode}/{kind}/trial/status'", $index);
        self::assertStringContainsString("post('/admin/tests/{test}/prompts/{mode}/{kind}/trial/dismiss'", $index);
    }

    public function testPageContractKeyBusyTextAnchorAndClose(): void
    {
        $page = $this->root('templates/owner-prompt-key.twig');

        self::assertStringContainsString('name="form_key" value="{{ trial_form_key }}"', $page);
        self::assertStringContainsString('class="action-pop__form prompt-trial" data-submit-once', $page);
        self::assertStringContainsString('data-busy-text="Запускаем разбор…"', $page);
        self::assertStringContainsString('id="trial-result"', $page);
        self::assertStringContainsString('data-trial-poll="{{ trial_status_path }}"', $page);
        self::assertStringContainsString('Закрыть</button>', $page);
        self::assertStringContainsString("asset('js/owner-forms.js')", $page);
        self::assertStringContainsString('обычно 30–90 секунд', $page);

        $script = $this->root('public/js/owner-tests.js');
        self::assertStringContainsString('data-trial-poll', $script);
        self::assertStringContainsString('5000', $script);

        $worker = $this->root('bin/generate-ai-reports.php');
        self::assertStringContainsString('AiTrialRunner', $worker);
        self::assertStringContainsString('purgeExpired', $this->root('bin/cleanup-sessions.php'));
    }
}
