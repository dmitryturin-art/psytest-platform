<?php

declare(strict_types=1);

namespace PsyTest\Core\Mail;

/**
 * Отправка одного письма простым текстом.
 *
 * HTML-варианта нет намеренно: единственное письмо платформы — ссылка входа,
 * а простой текст нечему исполнять у получателя.
 */
interface MailerInterface
{
    public function send(string $to, string $subject, string $textBody): void;
}
