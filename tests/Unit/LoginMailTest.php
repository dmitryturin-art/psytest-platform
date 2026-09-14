<?php

declare(strict_types=1);

namespace PsyTest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\Mail\LogMailer;
use PsyTest\Core\VisitorAccountService;

final class LoginMailTest extends TestCase
{
    private string $logDirectory;

    protected function setUp(): void
    {
        $this->logDirectory = sys_get_temp_dir() . '/psytest-mail-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logDirectory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->logDirectory);
    }

    public function testLoginMailCarriesTheLinkItsLifetimeAndAWayOutForSomeoneWhoDidNotAskForIt(): void
    {
        $token = str_repeat('ab', 32);
        $body = VisitorAccountService::loginBody('https://psytest.test/', $token);

        self::assertStringContainsString('https://psytest.test/account/login/' . $token, $body);
        self::assertStringContainsString('15 минут', $body);
        self::assertStringContainsString('один раз', $body);
        self::assertStringContainsString('не запрашивали вход', $body);
        self::assertSame('Вход в PsyTest', VisitorAccountService::loginSubject());
    }

    public function testLogMailerRecordsTheDeliveryButNeverTheLoginLink(): void
    {
        $token = str_repeat('cd', 32);
        $body = VisitorAccountService::loginBody('https://psytest.test', $token);

        (new LogMailer($this->logDirectory))->send('visitor@example.test', 'Вход в PsyTest', $body);

        $log = (string) file_get_contents($this->logDirectory . '/mail.log');
        self::assertStringContainsString('visitor@example.test', $log);
        self::assertStringContainsString('Вход в PsyTest', $log);
        self::assertStringNotContainsString($token, $log, 'Ссылка входа — credential, и в обычном логе ей не место.');
        self::assertFileDoesNotExist($this->logDirectory . '/mail-debug.log');
    }

    public function testDebugModeWritesTheWholeLetterToItsOwnFileForLocalChecksOnly(): void
    {
        $token = str_repeat('ef', 32);
        $body = VisitorAccountService::loginBody('https://psytest.test', $token);

        (new LogMailer($this->logDirectory, true))->send('visitor@example.test', 'Вход в PsyTest', $body);

        self::assertStringNotContainsString($token, (string) file_get_contents($this->logDirectory . '/mail.log'));
        self::assertStringContainsString($token, (string) file_get_contents($this->logDirectory . '/mail-debug.log'));
    }

    public function testDebugModeIsImpossibleInProduction(): void
    {
        $config = (string) file_get_contents(dirname(__DIR__, 2) . '/config.php');

        self::assertStringContainsString('return !$this->isProduction() && $this->getBool(\'MAIL_DEBUG_FILE\', false);', $config);
    }
}
