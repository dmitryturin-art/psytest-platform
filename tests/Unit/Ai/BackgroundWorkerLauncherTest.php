<?php

declare(strict_types=1);

namespace PsyTest\Tests\Unit\Ai;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\Ai\BackgroundWorkerLauncher;

/**
 * Запуск обработчика очереди отдельным процессом (07.K5a3).
 *
 * Реальные процессы здесь не запускаются: команда собирается лаунчером и
 * отдаётся подменённому раннеру, поэтому тест проверяет именно её форму —
 * отсоединение, экранирование и отсутствие секретов.
 */
final class BackgroundWorkerLauncherTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/psytest-worker-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/storage/logs', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (['/storage/cache/ai-worker.lock', '/storage/cache', '/storage/logs', '/storage', ''] as $path) {
            $full = $this->root . $path;
            if (is_file($full)) {
                unlink($full);
            } elseif (is_dir($full)) {
                @rmdir($full);
            }
        }
    }

    /**
     * Без пути к CLI PHP фоновый запуск невозможен, и лаунчер обязан сказать
     * об этом честно: вызывающий код остаётся на прежнем пути через
     * ResponseFinisher, а не теряет задание молча.
     */
    public function testWithoutAConfiguredPhpBinaryNothingIsLaunched(): void
    {
        $commands = [];
        $launcher = $this->launcher('', $commands);

        self::assertFalse($launcher->launch(2));
        self::assertSame([], $commands);
    }

    public function testLaunchesADetachedWorkerWithTheRequestedLimit(): void
    {
        $commands = [];
        $launcher = $this->launcher('/usr/local/bin/php8.3', $commands);

        self::assertTrue($launcher->launch(2));
        self::assertCount(1, $commands);

        $command = $commands[0];

        self::assertStringContainsString('nohup', $command);
        self::assertStringContainsString('/usr/local/bin/php8.3', $command);
        self::assertStringContainsString($this->root . '/bin/generate-ai-reports.php', $command);
        self::assertStringContainsString('--limit=2', $command);
        self::assertStringContainsString('>> ', $command);
        self::assertStringContainsString($this->root . '/storage/logs/ai-worker.log', $command);
        self::assertStringContainsString('2>&1', $command);
        self::assertStringEndsWith('&', $command);
    }

    /**
     * Аргументы экранируются: путь из окружения не должен превращаться в
     * дописанную команду.
     */
    public function testEveryArgumentIsShellEscaped(): void
    {
        $commands = [];
        $launcher = $this->launcher('/opt/php 8.3/bin/php; rm -rf /', $commands);

        self::assertTrue($launcher->launch(1));

        $command = $commands[0];
        self::assertStringContainsString(escapeshellarg('/opt/php 8.3/bin/php; rm -rf /'), $command);
        self::assertStringNotContainsString('php; rm -rf /', str_replace(escapeshellarg('/opt/php 8.3/bin/php; rm -rf /'), '', $command));
    }

    /**
     * В команде нет ни ключа провайдера, ни пароля БД: скрипт читает `.env`
     * сам, а список процессов виден всему хостингу.
     */
    public function testTheCommandCarriesNoSecrets(): void
    {
        $commands = [];
        $launcher = $this->launcher('/usr/local/bin/php8.3', $commands);
        $launcher->launch(2);

        foreach (['AI_API_KEY', 'DB_PASS', 'ENCRYPTION_KEY', 'OPENROUTER_API_KEY', 'sk-'] as $secret) {
            self::assertStringNotContainsString($secret, $commands[0]);
        }
    }

    /**
     * Защита от шторма: двойной клик или два заказа подряд не должны плодить
     * процессы. Лишний воркер безвреден — `claimNext` атомарен, — но и не нужен.
     */
    public function testASecondLaunchWithinTheThrottleWindowStartsNoProcess(): void
    {
        $commands = [];
        $launcher = $this->launcher('/usr/local/bin/php8.3', $commands);

        self::assertTrue($launcher->launch(2));
        // Вызывающему всё равно отвечаем «запущено»: работа уже в очереди и
        // уже подхвачена запущенным процессом, доделывать её в запросе нечего.
        self::assertTrue($launcher->launch(2));

        self::assertCount(1, $commands, 'В окне троттлинга запускается ровно один процесс.');
    }

    public function testThrottleWindowIsSharedBetweenSeparateWebRequests(): void
    {
        $commands = [];

        self::assertTrue($this->launcher('/usr/local/bin/php8.3', $commands)->launch(1));
        self::assertTrue($this->launcher('/usr/local/bin/php8.3', $commands)->launch(1));

        self::assertCount(1, $commands);
    }

    /** @param list<string> $commands */
    private function launcher(string $phpBinary, array &$commands): BackgroundWorkerLauncher
    {
        return new BackgroundWorkerLauncher(
            $phpBinary,
            $this->root,
            $this->root . '/storage/logs',
            static function (string $command) use (&$commands): void {
                $commands[] = $command;
            },
        );
    }
}
