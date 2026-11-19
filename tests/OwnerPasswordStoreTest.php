<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\OwnerPasswordStore;

/**
 * Смена пароля из кабинета пишет в `.env` только Argon2id-хэш и не трогает
 * остальные настройки: конфигурация сервера не должна пострадать от смены
 * пароля, а открытый пароль не должен попадать в файл.
 */
final class OwnerPasswordStoreTest extends TestCase
{
    private string $dir;
    private string $envPath;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/psytest-owner-password-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->envPath = $this->dir . '/.env';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @unlink($this->envPath);
        @rmdir($this->dir);
    }

    private function writeEnv(string $contents): void
    {
        file_put_contents($this->envPath, $contents);
        chmod($this->envPath, 0600);
    }

    public function testReplacesOnlyThePasswordLineAndStoresArgon2id(): void
    {
        $this->writeEnv(
            "APP_ENV=production\n"
            . "DB_HOST=localhost\n"
            . OwnerPasswordStore::KEY . "=\$argon2id\$oldhash\n"
            . "DB_NAME=psytest\n"
        );

        (new OwnerPasswordStore($this->envPath))->save('n7w-password');

        $contents = (string) file_get_contents($this->envPath);
        self::assertStringContainsString('APP_ENV=production', $contents);
        self::assertStringContainsString('DB_HOST=localhost', $contents);
        self::assertStringContainsString('DB_NAME=psytest', $contents);
        self::assertStringNotContainsString('oldhash', $contents);
        self::assertStringNotContainsString('n7w-password', $contents, 'Открытый пароль не должен попадать в .env.');

        self::assertSame(1, substr_count($contents, OwnerPasswordStore::KEY . '='));
        $hash = $this->readHash($contents);
        self::assertSame('argon2id', password_get_info($hash)['algoName']);
        self::assertTrue(password_verify('n7w-password', $hash));
        self::assertSame(0600, fileperms($this->envPath) & 0777);
    }

    public function testAppendsTheKeyWhenTheEnvHasNone(): void
    {
        $this->writeEnv("APP_ENV=production\nDB_HOST=localhost\n");

        (new OwnerPasswordStore($this->envPath))->save('long-enough-password');

        $contents = (string) file_get_contents($this->envPath);
        self::assertStringContainsString('DB_HOST=localhost', $contents);
        self::assertTrue(password_verify('long-enough-password', $this->readHash($contents)));
    }

    public function testRejectsShortPasswordAndKeepsTheFile(): void
    {
        $this->writeEnv(OwnerPasswordStore::KEY . "=old\n");

        try {
            (new OwnerPasswordStore($this->envPath))->save('short');
            self::fail('Короткий пароль должен быть отклонён.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString((string) OwnerPasswordStore::MIN_LENGTH, $e->getMessage());
        }

        self::assertSame(OwnerPasswordStore::KEY . "=old\n", (string) file_get_contents($this->envPath));
    }

    public function testFailsWhenTheEnvFileIsMissing(): void
    {
        $store = new OwnerPasswordStore($this->envPath);
        self::assertFalse($store->isWritable());

        $this->expectException(\RuntimeException::class);
        $store->save('long-enough-password');
    }

    public function testIsWritableSeesAnExistingEnv(): void
    {
        $this->writeEnv("APP_ENV=production\n");

        self::assertTrue((new OwnerPasswordStore($this->envPath))->isWritable());
    }

    private function readHash(string $contents): string
    {
        foreach (explode("\n", $contents) as $line) {
            if (str_starts_with($line, OwnerPasswordStore::KEY . '=')) {
                return substr($line, strlen(OwnerPasswordStore::KEY) + 1);
            }
        }

        self::fail('Строка с хэшем не найдена.');
    }
}
