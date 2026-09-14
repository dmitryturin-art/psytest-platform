<?php

declare(strict_types=1);

namespace PsyTest\Tests\Support;

use PsyTest\Core\Mail\MailerInterface;
use RuntimeException;

/**
 * Отправитель, который всегда падает.
 *
 * Нужен ровно для одной проверки: недоступный SMTP не должен превращаться в
 * ошибку страницы входа, иначе ответ формы начал бы отличаться в зависимости
 * от того, что происходит с конкретным адресом.
 */
final class FailingMailer implements MailerInterface
{
    public function send(string $to, string $subject, string $textBody): void
    {
        throw new RuntimeException('SMTP unavailable');
    }
}
