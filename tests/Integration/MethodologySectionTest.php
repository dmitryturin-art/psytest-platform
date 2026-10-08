<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Controllers\OwnerController;
use PsyTest\Core\Ai\AiTestSettings;
use PsyTest\Core\Ai\Prompt;
use PsyTest\Core\Ai\PromptRegistry;
use PsyTest\Core\Database;
use PsyTest\Core\FormOnce;
use PsyTest\Core\ModuleLoader;
use PsyTest\Core\OwnerDashboardAuthenticator;

/**
 * Раздел «Методики» (07.K14): сохранение галочек одной методики сразу при
 * щелчке и сводка по методике для списка и её страницы.
 *
 * Провайдер здесь не вызывается вовсе: ни сохранение галочек, ни сводка его
 * не трогают.
 */
#[Group('database')]
final class MethodologySectionTest extends TestCase
{
    private const TEST = 'beck-anxiety';

    private Database $db;

    /** @var array<string, array{report_enabled: bool, send_item_answers: bool}> */
    private array $savedSettings = [];

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        if (session_status() === PHP_SESSION_NONE) {
            @session_start(['use_cookies' => 0, 'cache_limiter' => '']);
        }
        $this->savedSettings = (new AiTestSettings($this->db))->all();
        $this->cleanStubs();
        $this->db->delete('ai_test_settings', 'test_slug = ?', [self::TEST]);
        $_SESSION['psytest_owner_dashboard_authenticated_at'] = time();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($_SERVER['HTTP_ACCEPT'], $_SESSION['psytest_owner_dashboard_authenticated_at'], $_SESSION['psytest_owner_dashboard_flash']);
        http_response_code(200);
        $this->cleanStubs();
        $this->db->execute('DELETE FROM ai_test_settings');
        foreach ($this->savedSettings as $slug => $row) {
            $this->db->insert('ai_test_settings', [
                'test_slug' => $slug,
                'report_enabled' => $row['report_enabled'] ? 1 : 0,
                'send_item_answers' => $row['send_item_answers'] ? 1 : 0,
            ]);
        }
        $this->db->execute("DELETE FROM activity_log WHERE action = 'ai_test_settings_changed'");
    }

    private function cleanStubs(): void
    {
        $this->db->delete('prompt_versions', 'test = ?', [self::TEST]);
        $this->db->delete('prompt_publications', 'test = ?', [self::TEST]);
    }

    public function testEnablingFromThePageSavesAtOnceCreatesStubsAndAsksForAReload(): void
    {
        $key = $this->issueKey();
        $_POST = ['form_key' => $key, 'report_enabled' => '1'];

        $payload = $this->save(self::TEST);

        self::assertSame('success', $payload['type']);
        self::assertSame('Сохранено.', $payload['message']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', (string) $payload['form_key']);
        self::assertNotSame($key, $payload['form_key'], 'Следующий щелчок уходит с новым одноразовым ключом.');
        self::assertSame('/admin/tests/beck-anxiety#prompts', $payload['reload']);

        $settings = new AiTestSettings($this->db);
        self::assertTrue($settings->isReportEnabled(self::TEST));
        self::assertFalse($settings->sendsItemAnswers(self::TEST));
        $registry = PromptRegistry::default($this->db);
        foreach ([Prompt::KIND_CLEAR, Prompt::KIND_PROFESSIONAL] as $kind) {
            self::assertTrue($registry->hasKey(self::TEST, 'individual', $kind));
            self::assertNull($registry->published(self::TEST, 'individual', $kind), 'Заготовка остаётся черновиком.');
        }
        // Сообщение о заготовках показывается в разделе «Промпты» после перерисовки.
        self::assertSame('prompts', $_SESSION['psytest_owner_dashboard_flash']['section'] ?? null);
        self::assertStringContainsString('Созданы две заготовки промптов', (string) ($_SESSION['psytest_owner_dashboard_flash']['message'] ?? ''));

        // Второй щелчок — по галочке ответов по пунктам: заготовки уже есть, перерисовка не нужна.
        unset($_SESSION['psytest_owner_dashboard_flash']);
        $_POST = ['form_key' => (string) $payload['form_key'], 'report_enabled' => '1', 'send_item_answers' => '1'];
        $second = $this->save(self::TEST);
        self::assertSame('success', $second['type']);
        self::assertNull($second['reload']);
        self::assertArrayNotHasKey('psytest_owner_dashboard_flash', $_SESSION);
        self::assertTrue((new AiTestSettings($this->db))->sendsItemAnswers(self::TEST));
        self::assertSame(2, (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM prompt_versions WHERE test = ?', [self::TEST])['n']);
    }

    public function testSwitchingOffClearsItemAnswersAndStaleKeyIsRefused(): void
    {
        (new AiTestSettings($this->db))->save(self::TEST, true, true);

        // Снятая первая галочка: вторая выключена в браузере и в форму не попадает.
        $_POST = ['form_key' => $this->issueKey()];
        $payload = $this->save(self::TEST);
        self::assertSame('success', $payload['type']);
        $settings = new AiTestSettings($this->db);
        self::assertFalse($settings->isReportEnabled(self::TEST));
        self::assertFalse($settings->sendsItemAnswers(self::TEST));

        $_POST = ['form_key' => str_repeat('a', 32), 'report_enabled' => '1'];
        $stale = $this->save(self::TEST);
        self::assertSame('error', $stale['type']);
        self::assertSame(409, http_response_code());
        self::assertStringContainsString('Обновите', (string) $stale['message']);
        self::assertFalse((new AiTestSettings($this->db))->isReportEnabled(self::TEST), 'Устаревший ключ ничего не меняет.');
    }

    public function testMethodologyRowsDescribeStatusPromptsAndItems(): void
    {
        $rows = [];
        $controller = $this->controller();
        $method = new \ReflectionMethod($controller, 'methodologyRow');
        $active = array_fill_keys(array_map('strval', array_keys((new ModuleLoader(null, $this->db))->discover()->getActiveModules())), true);
        foreach (['smil', 'lazarus', self::TEST] as $slug) {
            /** @var array<string, mixed> $row */
            $row = $method->invoke($controller, $slug, PromptRegistry::default($this->db), $active);
            $rows[$slug] = $row;
        }

        self::assertSame('разбор предлагается', $rows['smil']['status_label']);
        self::assertSame(['individual'], $rows['smil']['modes']);
        self::assertSame('published', $rows['smil']['prompts']['individual']['clear']['state']);
        self::assertSame(2, $rows['smil']['prompts']['individual']['clear']['published_version']);
        // Файловый черновик v3 (07.G1) в списке и карточках не всплывает — только в истории версий.
        self::assertNull($rows['smil']['prompts']['individual']['clear']['draft_version']);
        self::assertContains(3, PromptRegistry::default($this->db)->availableVersions('smil', 'individual', Prompt::KIND_CLEAR));
        self::assertSame('/admin/tests/smil/prompts/individual/clear', $rows['smil']['prompts']['individual']['clear']['path']);

        self::assertSame(['individual', 'pair'], $rows['lazarus']['modes']);
        self::assertSame('всегда', $rows['lazarus']['items_label']);

        self::assertSame('разбор выключен', $rows[self::TEST]['status_label']);
        self::assertFalse($rows[self::TEST]['prompts']['individual']['clear']['exists']);
        self::assertSame('нет', $rows[self::TEST]['items_label']);
        self::assertSame('/admin/tests/beck-anxiety', $rows[self::TEST]['path']);
    }

    public function testOwnerDraftsAndStubsAreHintedButFileDraftsAreNot(): void
    {
        $registry = PromptRegistry::default($this->db);
        $controller = $this->controller();
        $state = new \ReflectionMethod($controller, 'promptState');

        // Заготовка: черновик без опубликованной версии, помечен как заготовка.
        \PsyTest\Core\Ai\PromptStubSeeder::default($registry)->ensureFor(self::TEST, 'BAI');
        /** @var array<string, mixed> $stub */
        $stub = $state->invoke($controller, PromptRegistry::default($this->db), self::TEST, 'individual', Prompt::KIND_CLEAR);
        self::assertSame(PromptRegistry::STUB_FIRST_VERSION, $stub['draft_version']);
        self::assertTrue($stub['draft_is_stub']);

        // Правка из кабинета поверх опубликованной заготовки — обычный черновик с номером.
        $fresh = PromptRegistry::default($this->db);
        $fresh->publishVersion(self::TEST, 'individual', Prompt::KIND_CLEAR, PromptRegistry::STUB_FIRST_VERSION);
        $next = $fresh->createOwnerVersion(self::TEST, 'individual', Prompt::KIND_CLEAR, 'Правка.', null, false);
        /** @var array<string, mixed> $edited */
        $edited = $state->invoke($controller, PromptRegistry::default($this->db), self::TEST, 'individual', Prompt::KIND_CLEAR);
        self::assertSame(PromptRegistry::STUB_FIRST_VERSION, $edited['published_version']);
        self::assertSame($next, $edited['draft_version']);
        self::assertFalse($edited['draft_is_stub']);
    }

    private function issueKey(): string
    {
        $session = &$_SESSION;

        return (new FormOnce($session))->issue(OwnerController::PROMPT_TESTS_FORM);
    }

    /** @return array<string, mixed> */
    private function save(string $test): array
    {
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $controller = $this->controller();

        ob_start();
        try {
            $controller->saveMethodologyAi($test);
        } finally {
            $output = (string) ob_get_clean();
        }

        /** @var array<string, mixed> */
        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }

    private function controller(): OwnerController
    {
        $reflection = new \ReflectionClass(OwnerController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $base = new \ReflectionClass(\PsyTest\Controllers\BaseController::class);

        $set = static function (\ReflectionClass $class, object $target, string $property, mixed $value): void {
            $class->getProperty($property)->setValue($target, $value);
        };
        $set($base, $controller, 'db', $this->db);
        $set($base, $controller, 'moduleLoader', (new ModuleLoader(null, $this->db))->discover());
        $set($reflection, $controller, 'isProduction', false);
        $set($reflection, $controller, 'authenticator', new OwnerDashboardAuthenticator(
            $this->db,
            password_hash('integration-only', PASSWORD_ARGON2ID),
            3600,
            5,
            600,
        ));

        return $controller;
    }
}
