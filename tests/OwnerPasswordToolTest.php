<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\Database;
use PsyTest\Core\OwnerDashboardAuthenticator;

/**
 * Инструмент смены пароля кабинета обязан выдавать ровно ту строку .env,
 * которую OwnerDashboardAuthenticator признаёт настроенной.
 */
final class OwnerPasswordToolTest extends TestCase
{
    private const PREFIX = 'OWNER_DASHBOARD_PASSWORD_HASH=';

    /** @return array{0: int, 1: string, 2: string} */
    private function generate(string $password): array
    {
        $script = dirname(__DIR__) . '/bin/owner-password.php';

        $process = proc_open(
            [PHP_BINARY, $script, '--stdin'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        self::assertIsResource($process);

        fwrite($pipes[0], $password . "\n");
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    public function testGeneratedLineIsAcceptedByTheDashboardAuthenticator(): void
    {
        $password = 'walkthrough-passphrase';
        [$exitCode, $stdout] = $this->generate($password);

        self::assertSame(0, $exitCode);
        self::assertStringStartsWith(self::PREFIX, $stdout);

        $hash = trim(substr(trim($stdout), strlen(self::PREFIX)));
        self::assertSame('argon2id', password_get_info($hash)['algoName']);
        self::assertTrue(password_verify($password, $hash));

        $authenticator = new OwnerDashboardAuthenticator(
            $this->createStub(Database::class),
            $hash,
            120 * 60,
            10,
            15 * 60,
        );

        self::assertTrue($authenticator->isConfigured(), 'Кабинет должен считать такой хэш настроенным.');
    }

    public function testEmptyPasswordIsRefusedWithoutPrintingALine(): void
    {
        [$exitCode, $stdout, $stderr] = $this->generate('');

        self::assertSame(1, $exitCode);
        self::assertStringNotContainsString(self::PREFIX, $stdout);
        self::assertStringContainsString('Пустой пароль', $stderr);
    }

    public function testShortPasswordWarnsButStillProducesAUsableLine(): void
    {
        [$exitCode, $stdout, $stderr] = $this->generate('1234');

        self::assertSame(0, $exitCode);
        self::assertStringStartsWith(self::PREFIX, $stdout);
        self::assertStringContainsString('короче 8 символов', $stderr);
    }
}
