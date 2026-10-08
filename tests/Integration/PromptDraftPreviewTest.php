<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Controllers\OwnerController;
use PsyTest\Core\Database;
use PsyTest\Core\ModuleLoader;
use PsyTest\Core\OwnerDashboardAuthenticator;

/**
 * Предпросмотр несохранённого черновика промпта (07.WP9b).
 *
 * Эндпоинт обязан показать запрос по присланному тексту и ничего не записать:
 * ни версию промпта, ни публикацию. Провайдер не вызывается — транспорта в
 * этом пути нет вовсе.
 */
#[Group('database')]
final class PromptDraftPreviewTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        if (session_status() === PHP_SESSION_NONE) {
            @session_start(['use_cookies' => 0, 'cache_limiter' => '']);
        }
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($_SESSION['psytest_owner_dashboard_authenticated_at']);
        http_response_code(200);
    }

    public function testDraftIsRenderedAsTheModelRequestAndNothingIsPersisted(): void
    {
        $this->signIn();
        $versions = $this->rows('prompt_versions');
        $publications = $this->rows('prompt_publications');
        $_POST = ['text' => "  Черновик {{ не подстановка }} {x}\r\n\r\n    с отступом\r\n"];

        $payload = $this->call('smil', 'individual', 'clear');

        self::assertNull($payload['error']);
        self::assertSame("  Черновик {{ не подстановка }} {x}\n\n    с отступом\n", $payload['system']);
        self::assertStringContainsString('"test": "smil"', (string) $payload['user']);
        self::assertGreaterThan(0, $payload['context_length']);
        self::assertSame(mb_strlen($payload['system']), $payload['system_length']);

        self::assertSame($versions, $this->rows('prompt_versions'));
        self::assertSame($publications, $this->rows('prompt_publications'));
    }

    public function testUnknownKeyIsNotFoundAndOversizedDraftIsRefused(): void
    {
        $this->signIn();

        $_POST = ['text' => 'x'];
        $payload = $this->call('no-such-test', 'individual', 'clear');
        self::assertSame(404, http_response_code());
        self::assertSame('not found', $payload['error']);

        http_response_code(200);
        $_POST = ['text' => str_repeat('я', OwnerController::PROMPT_DRAFT_MAX_LENGTH + 1)];
        $payload = $this->call('smil', 'individual', 'clear');
        self::assertSame(422, http_response_code());
        self::assertNotEmpty($payload['error']);
    }

    private function signIn(): void
    {
        $_SESSION['psytest_owner_dashboard_authenticated_at'] = time();
    }

    /** @return array<string, mixed> */
    private function call(string $test, string $mode, string $kind): array
    {
        $reflection = new \ReflectionClass(OwnerController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $base = new \ReflectionClass(\PsyTest\Controllers\BaseController::class);

        $set = static function (\ReflectionClass $class, object $target, string $property, mixed $value): void {
            $prop = $class->getProperty($property);
            $prop->setValue($target, $value);
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

        ob_start();
        try {
            $controller->promptDraftPreview($test, $mode, $kind);
        } finally {
            $output = (string) ob_get_clean();
        }

        /** @var array<string, mixed> */
        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }

    private function rows(string $table): int
    {
        return (int) $this->db->selectOne("SELECT COUNT(*) AS c FROM {$table}")['c'];
    }
}
