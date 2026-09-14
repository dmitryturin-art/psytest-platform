<?php

declare(strict_types=1);

namespace PsyTest\Core\Mail;

use PHPMailer\PHPMailer\Exception as PhpMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Отправка через локальный `mail()` хостинга (`MAIL_TRANSPORT=mail`).
 *
 * На shared-hosting Beget письма с адреса собственного домена уходят через
 * системный sendmail без пароля: это первый вариант для staging. Если
 * доставка окажется ненадёжной, переключение на SMTP — только настройками.
 */
final class PhpMailMailer implements MailerInterface
{
    public function __construct(
        private readonly string $from,
        private readonly string $fromName = '',
    ) {
    }

    public function send(string $to, string $subject, string $textBody): void
    {
        $mailer = new PHPMailer(true);

        try {
            $mailer->isMail();
            $mailer->CharSet = PHPMailer::CHARSET_UTF8;
            $mailer->setFrom($this->from, $this->fromName);
            $mailer->addAddress($to);
            $mailer->isHTML(false);
            $mailer->Subject = $subject;
            $mailer->Body = $textBody;

            $mailer->send();
        } catch (PhpMailerException $exception) {
            // Как и в SmtpMailer: наружу только факт неудачи (ER §9).
            throw new \RuntimeException('Не удалось отправить письмо.', 0);
        }
    }
}
