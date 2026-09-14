<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Ai\AiClient;
use PsyTest\Core\Ai\AiProviderSettings;
use PsyTest\Core\Ai\AiReportContextBuilder;
use PsyTest\Core\Ai\AiReportGenerator;
use PsyTest\Core\Ai\AiReportRepository;
use PsyTest\Core\Ai\AiTransport;
use PsyTest\Core\Ai\Prompt;
use PsyTest\Core\Ai\PromptRegistry;
use PsyTest\Core\Database;
use PsyTest\Core\ModuleLoader;
use PsyTest\Core\SessionManager;
use PsyTest\Modules\Lazarus\LazarusModule;

/**
 * Неизменяемый снимок задания ИИ-разбора (аудит R2).
 *
 * Номер версии промпта сам по себе не описывает отправленный запрос: между
 * постановкой и обработкой владелец мог опубликовать другую версию, а результат
 * сессии — измениться. Здесь проверяется, что провайдер получает именно то, что
 * было заморожено при постановке.
 *
 * Внешний провайдер не вызывается: транспорт подменён.
 */
#[Group('database')]
final class AiReportSnapshotTest extends TestCase
{
    private Database $db;
    private AiReportRepository $reports;
    private SessionManager $sessions;
    private AiReportContextBuilder $contextBuilder;
    private string $sessionId = '';
    private string $promptsPath = '';

    /** @var array<string, mixed> */
    private array $recorded = [];

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->reports = new AiReportRepository($this->db);
        $this->sessions = new SessionManager($this->db);
        $this->contextBuilder = new AiReportContextBuilder(
            $this->sessions,
            (new ModuleLoader(null, $this->db))->discover(),
        );

        $test = $this->db->selectOne("SELECT id FROM tests WHERE slug = 'lazarus'");
        self::assertIsArray($test, 'Предусловие: методика Лазаруса зарегистрирована.');

        $session = $this->sessions->createSession((int) $test['id']);
        $this->sessionId = (string) $session['id'];
        $this->storeResults($this->lazarusResults(0));

        $this->promptsPath = $this->copyPrompts();
    }

    protected function tearDown(): void
    {
        if ($this->sessionId !== '') {
            $this->db->delete('test_sessions', 'id = ?', [$this->sessionId]);
            $this->sessionId = '';
        }

        if ($this->promptsPath !== '') {
            $this->removeDirectory($this->promptsPath);
            $this->promptsPath = '';
        }
    }

    // ---------------------------------------------------------------- fixtures

    /** @return array<string, mixed> */
    private function lazarusResults(int $shift): array
    {
        $module = new LazarusModule();
        $answers = ['gender' => 'female', 'age' => '34'];

        foreach ($module->getQuestions() as $index => $question) {
            $id = (int) $question['id'];
            $answers[$id . '_self'] = max(1, min(10, 4 + (($index + $shift) % 7)));
            $answers[$id . '_partner'] = max(1, min(10, 3 + (($index + $shift) % 5)));
        }

        return $module->calculateResults($answers);
    }

    /** @param array<string, mixed> $results */
    private function storeResults(array $results): void
    {
        $this->db->update(
            'test_sessions',
            ['status' => 'completed', 'calculated_results' => json_encode($results, JSON_UNESCAPED_UNICODE)],
            'id = ?',
            [$this->sessionId],
        );
    }

    /**
     * Копия реального набора промптов во временном каталоге: публикацию новой
     * версии тест имитирует правкой этого манифеста, а не боевого.
     */
    private function copyPrompts(): string
    {
        $source = dirname(__DIR__, 2) . '/prompts';
        $target = sys_get_temp_dir() . '/psytest-prompts-' . bin2hex(random_bytes(6));

        mkdir($target . '/lazarus', 0o755, true);
        copy($source . '/manifest.json', $target . '/manifest.json');

        foreach ((array) glob($source . '/lazarus/*.md') as $file) {
            copy((string) $file, $target . '/lazarus/' . basename((string) $file));
        }

        return $target;
    }

    private function publish(int $version): void
    {
        $manifest = json_decode((string) file_get_contents($this->promptsPath . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifest['prompts']['lazarus | individual | clear']['version'] = $version;

        file_put_contents(
            $this->promptsPath . '/manifest.json',
            json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    private function registry(): PromptRegistry
    {
        // Новый экземпляр на каждое обращение: реестр кэширует манифест, а тест
        // как раз проверяет поведение до и после публикации новой версии.
        return new PromptRegistry($this->promptsPath);
    }

    private function publishedPrompt(): Prompt
    {
        $prompt = $this->registry()->published('lazarus', 'individual', 'clear');
        self::assertInstanceOf(Prompt::class, $prompt);

        return $prompt;
    }

    private function transport(): AiTransport
    {
        $transport = $this->createStub(AiTransport::class);
        $transport->method('request')->willReturnCallback(
            function (string $method, string $url, array $headers, ?array $payload, int $timeout): array {
                $this->recorded = (array) $payload;

                return [
                    'status' => 200,
                    'body' => json_encode([
                        'model' => 'fixture/served',
                        'choices' => [['message' => ['content' => 'Готовый разбор.']]],
                        'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 22],
                    ], JSON_UNESCAPED_UNICODE),
                ];
            }
        );

        return $transport;
    }

    private function generator(): AiReportGenerator
    {
        return new AiReportGenerator(
            $this->reports,
            $this->contextBuilder,
            $this->registry(),
            new AiClient(
                new AiProviderSettings('https://provider.invalid/api/v1', 'fixture-key', 'fixture/requested', 30),
                $this->transport(),
            ),
        );
    }

    /** @param array<string, mixed> $context */
    private function queue(Prompt $prompt, array $context): string
    {
        $job = $this->reports->request($this->sessionId, 'lazarus', 'individual', 'clear', $prompt, $context);

        return (string) $job['id'];
    }

    private function processQueue(): void
    {
        $job = $this->reports->claimNext();
        self::assertIsArray($job, 'Предусловие: задание взято в работу.');

        $this->generator()->process($job);
    }

    private function sentSystemPrompt(): string
    {
        return (string) $this->recorded['messages'][0]['content'];
    }

    /** @return array<string, mixed> */
    private function sentContext(): array
    {
        return (array) json_decode((string) $this->recorded['messages'][1]['content'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function removeDirectory(string $path): void
    {
        foreach ((array) glob($path . '/*') as $entry) {
            $entry = (string) $entry;
            is_dir($entry) ? $this->removeDirectory($entry) : unlink($entry);
        }

        @rmdir($path);
    }

    // ------------------------------------------------------------------- tests

    public function testProviderGetsThePromptPublishedWhenTheJobWasQueuedNotTheLatestOne(): void
    {
        $this->publish(1);
        $atRequest = $this->publishedPrompt();
        self::assertSame(1, $atRequest->version);

        $id = $this->queue($atRequest, $this->contextBuilder->build($this->sessionId, 'lazarus', 'individual'));

        // Владелец публикует другую версию уже после постановки.
        $this->publish(2);
        $afterRequest = $this->publishedPrompt();
        self::assertSame(2, $afterRequest->version);
        self::assertNotSame($atRequest->text, $afterRequest->text, 'Предусловие: версии промпта действительно различаются.');

        $this->processQueue();

        self::assertSame($atRequest->text, $this->sentSystemPrompt(), 'Провайдер обязан получить промпт версии постановки.');
        self::assertSame(AiReportRepository::STATUS_READY, $this->reports->find($id)['status']);
        self::assertSame('fixture/served', $this->reports->find($id)['served_model']);
        self::assertSame(11, (int) $this->reports->find($id)['prompt_tokens']);
        self::assertSame(22, (int) $this->reports->find($id)['completion_tokens']);
    }

    public function testProviderGetsTheFrozenContextEvenIfTheStoredResultChangedAfterwards(): void
    {
        $this->publish(1);
        $context = $this->contextBuilder->build($this->sessionId, 'lazarus', 'individual');
        $this->queue($this->publishedPrompt(), $context);

        // Результат сессии переписан напрямую в базе уже после постановки.
        $changed = $this->lazarusResults(3);
        $this->db->execute(
            'UPDATE test_sessions SET calculated_results = ? WHERE id = ?',
            [json_encode($changed, JSON_UNESCAPED_UNICODE), $this->sessionId],
        );
        $live = $this->contextBuilder->build($this->sessionId, 'lazarus', 'individual');
        self::assertNotSame($context, $live, 'Предусловие: живой контекст действительно изменился.');

        $this->processQueue();

        self::assertSame($context, $this->sentContext(), 'Наружу уходит снимок постановки, а не текущий результат.');
    }

    public function testLegacyJobWithoutSnapshotIsProcessedTheOldWay(): void
    {
        $this->publish(1);
        $id = $this->queue($this->publishedPrompt(), $this->contextBuilder->build($this->sessionId, 'lazarus', 'individual'));

        // Задания, поставленные до миграции, снимков не имеют.
        $this->db->update('ai_reports', ['context_snapshot' => null, 'prompt_snapshot' => null], 'id = ?', [$id]);
        $this->publish(2);

        $this->processQueue();

        self::assertSame($this->publishedPrompt()->text, $this->sentSystemPrompt(), 'Без снимка промпт берётся из реестра.');
        self::assertSame(
            $this->contextBuilder->build($this->sessionId, 'lazarus', 'individual'),
            $this->sentContext(),
            'Без снимка контекст собирается из живого результата.',
        );
        self::assertSame(AiReportRepository::STATUS_READY, $this->reports->find($id)['status']);
    }

    public function testRepeatedRequestOfAFailedJobKeepsTheOriginalSnapshot(): void
    {
        $this->publish(1);
        $original = $this->publishedPrompt();
        $context = $this->contextBuilder->build($this->sessionId, 'lazarus', 'individual');
        $id = $this->queue($original, $context);

        $before = $this->reports->find($id);
        $this->reports->markFailed($id, 'провайдер недоступен');

        // Повтор идёт с тем же входом, даже если реестр и результат уже другие.
        $this->publish(2);
        $this->storeResults($this->lazarusResults(5));
        $again = $this->reports->request(
            $this->sessionId,
            'lazarus',
            'individual',
            'clear',
            $this->publishedPrompt(),
            $this->contextBuilder->build($this->sessionId, 'lazarus', 'individual'),
        );

        self::assertSame($id, (string) $again['id']);
        self::assertSame(AiReportRepository::STATUS_PENDING, $again['status']);
        self::assertSame($before['context_snapshot'], $again['context_snapshot']);
        self::assertSame($before['prompt_snapshot'], $again['prompt_snapshot']);

        $this->processQueue();

        self::assertSame($original->text, $this->sentSystemPrompt());
        self::assertSame($context, $this->sentContext());
    }

    public function testSnapshotSurvivesJsonRoundTripAtMediumTextSize(): void
    {
        // Колонка обязана быть MEDIUMTEXT: парный контекст заметно больше 64 КБ,
        // которые вмещает обычный TEXT, а молчаливое усечение испортило бы JSON.
        $large = ['test' => 'lazarus', 'mode' => 'individual', 'items' => []];
        for ($i = 0; $i < 2000; $i++) {
            $large['items'][] = ['id' => $i, 'domain' => 'область взаимодействия партнёров ' . $i, 'self' => 7];
        }
        $encoded = json_encode($large, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertGreaterThan(65535, strlen($encoded), 'Предусловие: контекст не помещается в TEXT.');

        $this->publish(1);
        $id = $this->queue($this->publishedPrompt(), $large);

        $stored = $this->reports->find($id);
        self::assertSame($encoded, $stored['context_snapshot']);
        self::assertSame($large, json_decode((string) $stored['context_snapshot'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testDeletingTheSessionTakesTheSnapshotWithIt(): void
    {
        $this->publish(1);
        $id = $this->queue($this->publishedPrompt(), $this->contextBuilder->build($this->sessionId, 'lazarus', 'individual'));
        self::assertNotNull($this->reports->find($id)['context_snapshot']);

        // Снимок — те же клинические данные, что и разбор: он живёт и уходит
        // вместе со строкой задания (PRODUCT_RULES §11).
        self::assertTrue($this->sessions->deleteSession($this->sessionId));
        self::assertNull($this->reports->find($id));

        self::assertNull(
            $this->db->selectOne('SELECT id FROM ai_reports WHERE session_id = ?', [$this->sessionId]),
        );
    }
}
