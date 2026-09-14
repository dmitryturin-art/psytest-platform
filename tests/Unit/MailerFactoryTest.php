<?php

declare(strict_types=1);

namespace PsyTest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\Mail\LogMailer;
use PsyTest\Core\Mail\MailerFactory;
use PsyTest\Core\Mail\PhpMailMailer;
use PsyTest\Core\Mail\SmtpMailer;

final class MailerFactoryTest extends TestCase
{
    public function testTransportIsChosenByConfigurationOnly(): void
    {
        self::assertInstanceOf(LogMailer::class, MailerFactory::fromConfig($this->config('smtp', '')));
        self::assertInstanceOf(SmtpMailer::class, MailerFactory::fromConfig($this->config('smtp', 'smtp.example.test')));
        self::assertInstanceOf(PhpMailMailer::class, MailerFactory::fromConfig($this->config('mail', '')));
    }

    private function config(string $transport, string $host): object
    {
        return new class ($transport, $host) {
            public function __construct(private readonly string $transport, private readonly string $host)
            {
            }

            public function mailTransport(): string
            {
                return $this->transport;
            }

            /** @return array{host: string, port: int, user: string, pass: string, encryption: string} */
            public function mailConfig(): array
            {
                return ['host' => $this->host, 'port' => 587, 'user' => '', 'pass' => '', 'encryption' => 'tls'];
            }

            public function mailFrom(): string
            {
                return 'info@example.test';
            }

            public function appName(): string
            {
                return 'PsyTest';
            }

            public function logPath(): string
            {
                return sys_get_temp_dir();
            }

            public function mailDebugFileEnabled(): bool
            {
                return false;
            }
        };
    }
}
