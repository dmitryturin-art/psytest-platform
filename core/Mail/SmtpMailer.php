<?php

declare(strict_types=1);

namespace PsyTest\Core\Mail;

use PHPMailer\PHPMailer\Exception as PhpMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Отправка через внешний SMTP (Beget) настройками из `MAIL_*`.
 *
 * Письмо всегда простой текст и всегда от `MAIL_FROM`: адрес получателя
 * приходит из формы входа, и никакой другой пользовательский ввод в конверт
 * не попадает.
 */
final class SmtpMailer implements MailerInterface
{
    /**
     * @param array{host: string, port: int, user: string, pass: string, encryption: string} $smtp
     */
    public function __construct(
        private readonly array $smtp,
        private readonly string $from,
        private readonly string $fromName = '',
    ) {
    }

    public function send(string $to, string $subject, string $textBody): void
    {
        $mailer = new PHPMailer(true);

        try {
            $mailer->isSMTP();
            $mailer->Host = $this->smtp['host'];
            $mailer->Port = $this->smtp['port'];
            $mailer->CharSet = PHPMailer::CHARSET_UTF8;

            if ($this->smtp['user'] !== '') {
                $mailer->SMTPAuth = true;
                $mailer->Username = $this->smtp['user'];
                $mailer->Password = $this->smtp['pass'];
            }

            $encryption = strtolower($this->smtp['encryption']);
            if ($encryption === 'ssl' || $encryption === 'smtps') {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption !== '' && $encryption !== 'none') {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mailer->setFrom($this->from, $this->fromName);
            $mailer->addAddress($to);
            $mailer->isHTML(false);
            $mailer->Subject = $subject;
            $mailer->Body = $textBody;

            $mailer->send();
        } catch (PhpMailerException $exception) {
            // Текст исключения PHPMailer содержит адрес и диалог с сервером,
            // поэтому наружу уходит только факт неудачи (ER §9).
            throw new \RuntimeException('Не удалось отправить письмо.', 0);
        }
    }
}
