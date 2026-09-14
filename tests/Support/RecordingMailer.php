<?php

declare(strict_types=1);

namespace PsyTest\Tests\Support;

use PsyTest\Core\Mail\MailerInterface;

/**
 * Отправитель для проверок: письмо никуда не уходит, а остаётся в памяти.
 *
 * Тесты не ходят в сеть и не отправляют настоящих писем — иначе прогон gate
 * рассылал бы почту по адресам из фикстур.
 */
final class RecordingMailer implements MailerInterface
{
    /** @var list<array{to: string, subject: string, body: string}> */
    public array $sent = [];

    public function send(string $to, string $subject, string $textBody): void
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $textBody];
    }

    /**
     * Токен последней ссылки входа.
     *
     * Он нужен проверкам ровно потому, что нигде больше не появляется: в базе
     * лежит только его хеш.
     */
    public function lastLoginToken(): ?string
    {
        $last = end($this->sent);
        if ($last === false) {
            return null;
        }

        return preg_match('#/account/login/([a-f0-9]{64})#', $last['body'], $matches) === 1
            ? $matches[1]
            : null;
    }
}
