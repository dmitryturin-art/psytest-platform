<?php

declare(strict_types=1);

namespace PsyTest\Core\Mail;

/**
 * Запасной отправитель, когда SMTP не настроен (`MAIL_HOST` пуст).
 *
 * В `mail.log` попадают только адрес и тема: тело письма содержит ссылку
 * входа, а она — bearer-credential, и в обычном логе ей места нет (ER §9).
 *
 * Отдельный debug-режим пишет полный текст в другой файл и существует
 * исключительно для локальной проверки сценария в браузере; в production он
 * выключен на уровне конфигурации.
 */
final class LogMailer implements MailerInterface
{
    public function __construct(
        private readonly string $logDirectory,
        private readonly bool $debugFileEnabled = false,
    ) {
    }

    public function send(string $to, string $subject, string $textBody): void
    {
        $this->append('mail.log', sprintf("[%s] to=%s subject=%s\n", date('c'), $to, $subject));

        if ($this->debugFileEnabled) {
            $this->append(
                'mail-debug.log',
                sprintf("[%s] to=%s subject=%s\n%s\n---\n", date('c'), $to, $subject, $textBody),
            );
        }
    }

    private function append(string $filename, string $line): void
    {
        if (!is_dir($this->logDirectory) && !mkdir($this->logDirectory, 0755, true) && !is_dir($this->logDirectory)) {
            throw new \RuntimeException('Не удалось подготовить каталог логов.');
        }

        file_put_contents($this->logDirectory . '/' . $filename, $line, FILE_APPEND | LOCK_EX);
    }
}
