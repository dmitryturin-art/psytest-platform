<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Controllers\OwnerController;
use PsyTest\Core\Ai\AiClient;
use PsyTest\Core\Ai\AiProviderSettings;
use PsyTest\Core\Ai\AiReportAvailability;
use PsyTest\Core\Ai\AiReportContextBuilder;
use PsyTest\Core\Ai\AiReportGenerator;
use PsyTest\Core\Ai\AiReportRepository;
use PsyTest\Core\Ai\AiSettings;
use PsyTest\Core\Ai\AiTestSettings;
use PsyTest\Core\Ai\AiTransport;
use PsyTest\Core\Ai\Prompt;
use PsyTest\Core\Ai\PromptRegistry;
use PsyTest\Core\Ai\PromptStubSeeder;
use PsyTest\Core\Database;
use PsyTest\Core\FormOnce;
use PsyTest\Core\ModuleLoader;
use PsyTest\Core\OwnerCaseReportOrder;
use PsyTest\Core\ResultPresenter;
use PsyTest\Core\SessionManager;
use PsyTest\Core\TestInviteService;
use Ramsey\Uuid\Uuid;

/**
 * Универсальный ИИ-разбор (07.WP10, D-056): галочки по методикам, заготовки
 * промптов, единое правило показа и что уходит наружу.
 *
 * Внешний провайдер не вызывается: транспорт записывает запрос и отвечает
 * готовым текстом.
 */
#[Group('database')]
final class UniversalAiReportTest extends TestCase
{
    private const TEST = 'beck-anxiety';
    private const MODE = 'individual';

    private const MARKER_LABEL = 'Маркер-Подпись-Клиента-7Q';
    private const MARKER_EMAIL = 'marker-7q@example.test';
    private const MARKER_NOTE = 'Маркер-Заметка-Специалиста-7Q';

    private Database $db;
    private SessionManager $sessions;

    /** @var list<string> */
    private array $sessionIds = [];

    /** @var list<string> */
    private array $clientIds = [];

    /** @var array<string, array{report_enabled: bool, send_item_answers: bool}> */
    private array $savedSettings = [];

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->sessions = new SessionManager($this->db);
        $this->savedSettings = (new AiTestSettings($this->db))->all();
        $this->cleanPrompts();
        $this->db->delete('ai_test_settings', 'test_slug = ?', [self::TEST]);
        (new AiSettings($this->db))->setAiEnabled(true);
    }

    protected function tearDown(): void
    {
        foreach ($this->sessionIds as $id) {
            $this->db->delete('test_invites', 'claimed_session_id = ?', [$id]);
            $this->db->delete('ai_reports', 'session_id = ?', [$id]);
            $this->db->delete('test_sessions', 'id = ?', [$id]);
        }
        foreach ($this->clientIds as $id) {
            $this->db->delete('therapist_clients', 'id = ?', [$id]);
        }
        $this->sessionIds = [];
        $this->clientIds = [];

        $this->cleanPrompts();
        $this->db->execute('DELETE FROM ai_test_settings');
        foreach ($this->savedSettings as $slug => $row) {
            $this->db->insert('ai_test_settings', [
                'test_slug' => $slug,
                'report_enabled' => $row['report_enabled'] ? 1 : 0,
                'send_item_answers' => $row['send_item_answers'] ? 1 : 0,
            ]);
        }
        $this->db->execute("DELETE FROM activity_log WHERE action = 'ai_test_settings_changed'");
        $this->db->delete('ai_settings', 'setting_key = ?', [AiSettings::KEY_ENABLED]);
    }

    private function cleanPrompts(): void
    {
        foreach (['beck-anxiety', 'bdi', 'hads'] as $slug) {
            $this->db->delete('prompt_versions', 'test = ?', [$slug]);
            $this->db->delete('prompt_publications', 'test = ?', [$slug]);
        }
    }

    // ------------------------------------------------------------- галочки

    public function testSmilAndLazarusAreSwitchedOnByTheMigrationWithoutItemAnswers(): void
    {
        $settings = new AiTestSettings($this->db);

        foreach (['smil', 'lazarus'] as $slug) {
            self::assertTrue($settings->isReportEnabled($slug), $slug);
            self::assertFalse($settings->sendsItemAnswers($slug), $slug);
        }
        foreach (['beck-anxiety', 'bdi', 'hads'] as $slug) {
            self::assertFalse($settings->isReportEnabled($slug), "{$slug} по умолчанию выключен.");
        }
    }

    public function testTogglesPersistAndAreAuditedWithoutPersonalData(): void
    {
        $settings = new AiTestSettings($this->db);

        // Строки нет — методика и так выключена: «выключить» ничего не пишет.
        self::assertFalse($settings->save(self::TEST, false, false));

        self::assertTrue($settings->save(self::TEST, true, true));
        $reread = new AiTestSettings($this->db);
        self::assertTrue($reread->isReportEnabled(self::TEST));
        self::assertTrue($reread->sendsItemAnswers(self::TEST));

        // Выключение разбора сбрасывает и вторую галочку.
        self::assertTrue($settings->save(self::TEST, false, true));
        $row = $this->db->selectOne('SELECT report_enabled, send_item_answers FROM ai_test_settings WHERE test_slug = ?', [self::TEST]);
        self::assertSame(['report_enabled' => 0, 'send_item_answers' => 0], array_map('intval', (array) $row));

        // Повтор без изменений не пишет журнал.
        self::assertFalse($settings->save(self::TEST, false, false));

        $events = $this->db->select(
            "SELECT session_id, test_id, details FROM activity_log WHERE action = 'ai_test_settings_changed' ORDER BY id",
        );
        self::assertCount(2, $events);
        foreach ($events as $event) {
            self::assertNull($event['session_id']);
            self::assertNull($event['test_id']);
            $details = json_decode((string) $event['details'], true);
            self::assertEqualsCanonicalizing(['actor', 'test', 'report_enabled', 'send_item_answers'], array_keys($details));
            self::assertSame('owner', $details['actor']);
            self::assertSame(self::TEST, $details['test']);
        }
        self::assertEquals(
            ['actor' => 'owner', 'test' => self::TEST, 'report_enabled' => false, 'send_item_answers' => false],
            json_decode((string) $events[1]['details'], true),
        );
    }

    // ------------------------------------------------------------ заготовки

    public function testEnablingATestWithoutPromptsCreatesTwoDraftStubsOnce(): void
    {
        $registry = PromptRegistry::default($this->db);
        $seeder = PromptStubSeeder::default($registry);

        self::assertFalse($registry->hasKey(self::TEST, self::MODE, Prompt::KIND_CLEAR));

        $created = $seeder->ensureFor(self::TEST, 'Шкала тревоги Бека (BAI)');
        self::assertSame([
            Prompt::keyFor(self::TEST, self::MODE, Prompt::KIND_CLEAR),
            Prompt::keyFor(self::TEST, self::MODE, Prompt::KIND_PROFESSIONAL),
        ], $created);
        self::assertSame([], $seeder->ensureFor(self::TEST, 'Шкала тревоги Бека (BAI)'), 'Повтор ничего не создаёт.');
        self::assertSame(2, (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM prompt_versions WHERE test = ?', [self::TEST])['n']);

        $fresh = PromptRegistry::default($this->db);
        foreach ([Prompt::KIND_CLEAR, Prompt::KIND_PROFESSIONAL] as $kind) {
            self::assertContains(Prompt::keyFor(self::TEST, self::MODE, $kind), $fresh->keys());
            self::assertNull($fresh->published(self::TEST, self::MODE, $kind), 'Заготовка — черновик.');
            self::assertNull($fresh->manifestVersion(self::TEST, self::MODE, $kind));
            self::assertFalse($fresh->hasFactoryText(self::TEST, self::MODE, $kind));
            self::assertSame([PromptRegistry::STUB_FIRST_VERSION], $fresh->availableVersions(self::TEST, self::MODE, $kind));

            $draft = $fresh->version(self::TEST, self::MODE, $kind, PromptRegistry::STUB_FIRST_VERSION);
            self::assertInstanceOf(Prompt::class, $draft);
            self::assertStringContainsString('«Шкала тревоги Бека (BAI)»', $draft->text);
            self::assertStringNotContainsString('{{test_name}}', $draft->text);
            self::assertStringContainsString(PromptStubSeeder::NOTE, $draft->source);
            self::assertSame($kind === Prompt::KIND_PROFESSIONAL, $draft->allowsOwnerContext);

            try {
                $fresh->resetToManifest(self::TEST, self::MODE, $kind);
                self::fail('У заготовки нет заводского текста.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('заводского текста нет, это заготовка', $e->getMessage());
            }
        }

        // Ключи с заводским текстом заготовки не получают.
        self::assertSame([], $seeder->ensureFor('smil', 'СМИЛ'));
        self::assertSame([], $seeder->ensureFor('lazarus', 'Лазарус'));

        // Правка и публикация работают как у обычного ключа.
        $next = $fresh->createOwnerVersion(self::TEST, self::MODE, Prompt::KIND_CLEAR, 'Правленый текст.', null, false);
        self::assertSame(PromptRegistry::STUB_FIRST_VERSION + 1, $next);
        $fresh->publishVersion(self::TEST, self::MODE, Prompt::KIND_CLEAR, $next);
        self::assertSame('Правленый текст.', PromptRegistry::default($this->db)->published(self::TEST, self::MODE, Prompt::KIND_CLEAR)?->text);
    }

    // ------------------------------------------------- единое правило показа

    public function testAvailabilityMatrixForGuestPageAndOrdering(): void
    {
        $sessionId = $this->completedSession(self::TEST);
        $session = $this->sessions->getSessionById($sessionId);
        self::assertIsArray($session);

        $state = function (): array {
            $availability = AiReportAvailability::forDatabase($this->db);
            $view = (new ResultPresenter($this->db, $this->sessions))->reportViewData(self::TEST, $this->sessions->getSessionById($this->sessionIds[0]) ?? []);

            return [
                $availability->canOffer(self::TEST, self::MODE, Prompt::KIND_CLEAR),
                $view === null ? null : array_column($view['kinds'], 'kind'),
            ];
        };

        // Методика выключена, промптов нет.
        self::assertSame([false, null], $state());
        $this->assertOrderRefused($sessionId, AiReportAvailability::REASON_TEST_OFF);

        // Включена, но промпт — только черновик.
        (new AiTestSettings($this->db))->save(self::TEST, true, false);
        PromptStubSeeder::default(PromptRegistry::default($this->db))->ensureFor(self::TEST, 'BAI');
        self::assertSame([false, null], $state());
        $this->assertOrderRefused($sessionId, AiReportAvailability::REASON_NO_PROMPT);

        // Понятный разбор опубликован — предлагается только он.
        PromptRegistry::default($this->db)->publishVersion(self::TEST, self::MODE, Prompt::KIND_CLEAR, PromptRegistry::STUB_FIRST_VERSION);
        self::assertSame([true, [Prompt::KIND_CLEAR]], $state());
        self::assertSame([Prompt::KIND_CLEAR], AiReportAvailability::forDatabase($this->db)->offeredKinds(self::TEST, self::MODE));

        // Общий выключатель закрывает всё.
        (new AiSettings($this->db))->setAiEnabled(false);
        self::assertSame([false, null], $state());
        $this->assertOrderRefused($sessionId, AiClient::DISABLED_REASON);
        (new AiSettings($this->db))->setAiEnabled(true);

        // Методика снова выключена: опубликованный промпт не помогает.
        (new AiTestSettings($this->db))->save(self::TEST, false, false);
        self::assertSame([false, null], $state());
        $this->assertOrderRefused($sessionId, AiReportAvailability::REASON_TEST_OFF);

        // Включена и опубликована — кабинет ставит задание.
        (new AiTestSettings($this->db))->save(self::TEST, true, false);
        $session = [];
        $order = $this->order($session)->submit($sessionId, self::TEST, self::MODE, [
            'form_key' => $this->order($session)->issueKeys()['all'],
            'ai_consent' => '1',
        ]);
        self::assertSame('success', $order['type'], $order['message']);
        self::assertSame(1, $order['queued'], 'Профессиональное заключение не опубликовано и не заказывается.');
    }

    public function testTurningOffStopsNewOrdersButKeepsAReadyReportVisible(): void
    {
        $sessionId = $this->completedSession(self::TEST);
        (new AiTestSettings($this->db))->save(self::TEST, true, false);
        $registry = PromptRegistry::default($this->db);
        PromptStubSeeder::default($registry)->ensureFor(self::TEST, 'BAI');
        $registry->publishVersion(self::TEST, self::MODE, Prompt::KIND_CLEAR, PromptRegistry::STUB_FIRST_VERSION);

        $prompt = PromptRegistry::default($this->db)->published(self::TEST, self::MODE, Prompt::KIND_CLEAR);
        self::assertInstanceOf(Prompt::class, $prompt);
        $context = $this->builder()->build($sessionId, self::TEST, self::MODE);
        $job = (new AiReportRepository($this->db))->request($sessionId, self::TEST, self::MODE, Prompt::KIND_CLEAR, $prompt, $context);
        $this->db->update('ai_reports', ['status' => AiReportRepository::STATUS_READY, 'content' => 'Готовый **разбор**.'], 'id = ?', [$job['id']]);

        (new AiTestSettings($this->db))->save(self::TEST, false, false);

        $view = (new ResultPresenter($this->db, $this->sessions))->reportViewData(self::TEST, (array) $this->sessions->getSessionById($sessionId));
        self::assertIsArray($view, 'Готовый разбор остаётся на странице.');
        self::assertCount(1, $view['kinds']);
        self::assertSame(AiReportRepository::STATUS_READY, $view['kinds'][0]['status']);
        self::assertFalse($view['kinds'][0]['can_order']);
        self::assertStringContainsString('<strong>разбор</strong>', (string) $view['kinds'][0]['html']);

        // Карточка кейса: карточка разбора есть, нового заказа нет.
        $section = $this->caseSection($sessionId);
        self::assertTrue($section['available']);
        self::assertFalse($section['can_order']);
        self::assertSame([Prompt::KIND_CLEAR], array_column($section['kinds'], 'kind'));
        self::assertFalse($section['kinds'][0]['can_order']);
    }

    public function testCasePageOffersOrderingOnlyByTheRule(): void
    {
        $sessionId = $this->completedSession(self::TEST);

        $off = $this->caseSection($sessionId);
        self::assertFalse($off['available'], 'Методика выключена: раздела разбора нет.');
        self::assertFalse($off['can_order']);

        (new AiTestSettings($this->db))->save(self::TEST, true, false);
        $registry = PromptRegistry::default($this->db);
        PromptStubSeeder::default($registry)->ensureFor(self::TEST, 'BAI');
        self::assertFalse($this->caseSection($sessionId)['can_order'], 'Черновик не открывает заказ.');

        $registry->publishVersion(self::TEST, self::MODE, Prompt::KIND_CLEAR, PromptRegistry::STUB_FIRST_VERSION);
        $registry->publishVersion(self::TEST, self::MODE, Prompt::KIND_PROFESSIONAL, PromptRegistry::STUB_FIRST_VERSION);
        $on = $this->caseSection($sessionId);
        self::assertTrue($on['available']);
        self::assertTrue($on['can_order']);
        self::assertSame([Prompt::KIND_CLEAR, Prompt::KIND_PROFESSIONAL], array_column($on['kinds'], 'kind'));
    }

    // ------------------------------------------- задания, поставленные раньше

    public function testQueuedJobIsRefusedAfterTheTestIsSwitchedOff(): void
    {
        $sessionId = $this->completedSession(self::TEST);
        $this->enableAndPublish(true);
        $job = $this->queue($sessionId);

        (new AiTestSettings($this->db))->save(self::TEST, false, false);

        $transport = $this->recordingTransport();
        $this->generator($transport)->process((array) (new AiReportRepository($this->db))->claimNext());

        self::assertSame([], $transport->bodies, 'Вход задания не должен уйти наружу.');
        $failed = (new AiReportRepository($this->db))->find((string) $job['id']);
        self::assertSame(AiReportRepository::STATUS_FAILED, $failed['status'] ?? null);
        self::assertSame(AiReportAvailability::REASON_TEST_OFF, $failed['failure_reason']);
    }

    public function testQueuedJobLosesItemAnswersWhenTheCheckboxIsClearedAfterOrdering(): void
    {
        $sessionId = $this->completedSession(self::TEST);
        $this->enableAndPublish(true);
        $job = $this->queue($sessionId);
        self::assertStringContainsString('"items"', (string) $this->db->selectOne('SELECT context_snapshot FROM ai_reports WHERE id = ?', [$job['id']])['context_snapshot']);

        (new AiTestSettings($this->db))->save(self::TEST, true, false);

        $transport = $this->recordingTransport();
        $this->generator($transport)->process((array) (new AiReportRepository($this->db))->claimNext());

        self::assertCount(1, $transport->bodies);
        $user = (string) ($transport->bodies[0]['messages'][1]['content'] ?? '');
        self::assertStringContainsString('beck-anxiety', $user);
        self::assertStringNotContainsString('"items"', $user, 'Снятая галочка действует и на уже поставленные задания.');
        self::assertStringNotContainsString('answer_label', $user);
        self::assertSame(AiReportRepository::STATUS_READY, (new AiReportRepository($this->db))->find((string) $job['id'])['status'] ?? null);
    }

    public function testLazarusItemsAreKeptBecauseTheyArePartOfItsApprovedContext(): void
    {
        $builder = $this->builder();
        $context = ['test' => 'lazarus', 'items' => [['id' => 1, 'self' => 5]]];

        self::assertSame($context, $builder->enforceItemPolicy('lazarus', 'individual', $context));
        self::assertSame($context, $builder->enforceItemPolicy('lazarus', 'pair', $context));
        self::assertArrayNotHasKey('items', $builder->enforceItemPolicy(self::TEST, 'individual', ['test' => self::TEST, 'items' => []]));
    }

    public function testStubVersionsLiveAboveAnyFileVersion(): void
    {
        $registry = PromptRegistry::default($this->db);
        PromptStubSeeder::default($registry)->ensureFor(self::TEST, 'BAI');

        // Файловые версии любого ключа манифеста — меньше номера заготовок,
        // иначе правка кабинета молча заслонила бы файл с тем же номером.
        $fileOnly = new PromptRegistry(dirname(__DIR__, 2) . '/prompts');
        foreach ($fileOnly->keys() as $key) {
            [$test, $mode, $kind] = array_map('trim', explode('|', $key));
            foreach ($fileOnly->availableVersions($test, $mode, $kind) as $version) {
                self::assertLessThan(PromptRegistry::STUB_FIRST_VERSION, $version, $key);
            }
        }
        self::assertSame([PromptRegistry::STUB_FIRST_VERSION], PromptRegistry::default($this->db)->availableVersions(self::TEST, self::MODE, Prompt::KIND_CLEAR));
    }

    private function enableAndPublish(bool $items): void
    {
        (new AiTestSettings($this->db))->save(self::TEST, true, $items);
        $registry = PromptRegistry::default($this->db);
        PromptStubSeeder::default($registry)->ensureFor(self::TEST, 'BAI');
        $registry->publishVersion(self::TEST, self::MODE, Prompt::KIND_CLEAR, PromptRegistry::STUB_FIRST_VERSION);
    }

    /** @return array<string, mixed> */
    private function queue(string $sessionId): array
    {
        $prompt = PromptRegistry::default($this->db)->published(self::TEST, self::MODE, Prompt::KIND_CLEAR);
        self::assertInstanceOf(Prompt::class, $prompt);

        return (new AiReportRepository($this->db))->request(
            $sessionId,
            self::TEST,
            self::MODE,
            Prompt::KIND_CLEAR,
            $prompt,
            $this->builder()->build($sessionId, self::TEST, self::MODE),
        );
    }

    private function generator(AiTransport $transport): AiReportGenerator
    {
        return new AiReportGenerator(
            new AiReportRepository($this->db),
            $this->builder(),
            PromptRegistry::default($this->db),
            new AiClient(new AiProviderSettings('https://provider.invalid/api/v1', 'fixture-key', 'fixture/model', 30), $transport),
            AiReportAvailability::forDatabase($this->db),
        );
    }

    // ------------------------------------------------------------ приватность

    public function testRequestPayloadForEveryTestCarriesNoClientLabelEmailOrNote(): void
    {
        $settings = new AiTestSettings($this->db);
        $modules = (new ModuleLoader(null, $this->db))->discover();

        foreach (array_keys($modules->getAllModules()) as $slug) {
            $slug = (string) $slug;
            $sessionId = $this->completedSession($slug, true);

            foreach ([false, true] as $items) {
                $settings->save($slug, true, $items);
                $context = $this->builder()->build($sessionId, $slug, self::MODE);
                self::assertSame($items || $slug === 'lazarus', isset($context['items']), "{$slug}: ответы по пунктам строго по галочке.");

                $transport = $this->recordingTransport();
                (new AiClient(new AiProviderSettings('https://provider.invalid/api/v1', 'fixture-key', 'fixture/model', 30), $transport))
                    ->complete(new Prompt($slug, self::MODE, Prompt::KIND_CLEAR, 1, Prompt::STATUS_PUBLISHED, 'Промпт.', false, 'test'), $context);

                $payload = json_encode($transport->bodies, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                foreach ([self::MARKER_LABEL, self::MARKER_EMAIL, self::MARKER_NOTE, $sessionId] as $secret) {
                    self::assertStringNotContainsString($secret, $payload, "{$slug}: в запрос провайдеру попали данные клиента.");
                }
            }
        }
    }

    // --------------------------------------------------------------- помощники

    private function assertOrderRefused(string $sessionId, string $reason): void
    {
        $session = [];
        $order = $this->order($session)->submit($sessionId, self::TEST, self::MODE, [
            'form_key' => $this->order($session)->issueKeys()['all'],
            'ai_consent' => '1',
        ]);
        self::assertSame('error', $order['type']);
        self::assertSame(0, $order['queued']);
        self::assertStringContainsString($reason, $order['message']);
        self::assertNull((new AiReportRepository($this->db))->findFor($sessionId, self::MODE, Prompt::KIND_CLEAR));
    }

    /** @param array<string, mixed> $session */
    private function order(array &$session): OwnerCaseReportOrder
    {
        $settings = new AiSettings($this->db);

        return new OwnerCaseReportOrder(
            new AiReportRepository($this->db),
            PromptRegistry::default($this->db),
            $settings,
            $this->builder(),
            new FormOnce($session),
            AiReportAvailability::forDatabase($this->db),
        );
    }

    private function builder(): AiReportContextBuilder
    {
        return new AiReportContextBuilder(
            $this->sessions,
            (new ModuleLoader(null, $this->db))->discover(),
            new AiSettings($this->db),
            new AiTestSettings($this->db),
        );
    }

    /** @return array<string, mixed> */
    private function caseSection(string $sessionId): array
    {
        $controller = new OwnerController();
        $method = new \ReflectionMethod($controller, 'aiSection');

        /** @var array<string, mixed> $section */
        $section = $method->invoke($controller, $sessionId, self::TEST);

        return $section;
    }

    /**
     * Завершённая сессия кейса специалиста с подписью, email и заметкой рядом.
     */
    private function completedSession(string $slug, bool $withClient = false): string
    {
        $test = $this->db->selectOne('SELECT id FROM tests WHERE slug = ?', [$slug]);
        self::assertIsArray($test, "Предусловие: методика {$slug} зарегистрирована.");
        $module = (new ModuleLoader(null, $this->db))->discover()->getModule($slug);
        self::assertNotNull($module);

        $schema = $module->getAnswerSchema();
        $answers = ['gender' => 'female'];
        foreach (array_values($module->getQuestions()) as $index => $question) {
            $id = (string) $question['id'];
            $value = match ($schema['answer_type']) {
                'ternary' => (string) ($index % 3),
                'scale10' => (string) (3 + $index % 7),
                default => (string) ($question['options'][$index % count($question['options'])]['value'] ?? 0),
            };
            if ($schema['key_template'] === 'dual') {
                $answers[$id . '_self'] = $value;
                $answers[$id . '_partner'] = $value;
            } else {
                $answers[$id] = $value;
            }
        }
        // Посторонний ключ в ответах не должен уйти наружу.
        $answers['note'] = self::MARKER_NOTE;

        $created = $this->sessions->createSession((int) $test['id']);
        $sessionId = (string) $created['id'];
        $this->sessionIds[] = $sessionId;
        $this->db->update('test_sessions', [
            'status' => 'completed',
            'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
            'calculated_results' => json_encode($module->calculateResults($answers), JSON_UNESCAPED_UNICODE),
            'user_email' => self::MARKER_EMAIL,
            'user_name' => self::MARKER_LABEL,
        ], 'id = ?', [$sessionId]);

        if ($withClient) {
            $clientId = Uuid::uuid4()->toString();
            $this->clientIds[] = $clientId;
            $this->db->insert('therapist_clients', [
                'id' => $clientId,
                'label' => self::MARKER_LABEL,
                'note' => self::MARKER_NOTE,
                'email' => self::MARKER_EMAIL,
            ]);
            (new TestInviteService($this->db, $this->sessions))->bindExistingSession($sessionId, (int) $test['id'], $clientId, self::MARKER_NOTE);
        }

        return $sessionId;
    }

    private function recordingTransport(): AiTransport
    {
        return new class () implements AiTransport {
            /** @var list<array<string, mixed>|null> */
            public array $bodies = [];

            public function request(string $method, string $url, array $headers, ?array $body, int $timeoutSeconds): array
            {
                $this->bodies[] = $body;

                return ['status' => 200, 'body' => (string) json_encode([
                    'model' => 'fixture/model',
                    'choices' => [['message' => ['content' => 'Готово.']]],
                ])];
            }
        };
    }
}
