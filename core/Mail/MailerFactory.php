<?php

declare(strict_types=1);

namespace PsyTest\Core\Mail;

/**
 * Выбор отправителя по конфигурации.
 *
 * Пустой `MAIL_HOST` — не ошибка, а рабочее состояние разработки: письмо
 * тогда не уходит наружу, а в лог попадает только факт отправки. Так локальная
 * проверка никогда не отправляет настоящее письмо по чужому адресу.
 */
final class MailerFactory
{
    public static function fromConfig(object $config): MailerInterface
    {
        /** @var array{host: string, port: int, user: string, pass: string, encryption: string} $smtp */
        $smtp = $config->mailConfig();

        if ($smtp['host'] === '') {
            return new LogMailer($config->logPath(), $config->mailDebugFileEnabled());
        }

        return new SmtpMailer($smtp, $config->mailFrom(), $config->appName());
    }
}
