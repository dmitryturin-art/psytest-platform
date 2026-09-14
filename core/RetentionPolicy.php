<?php

declare(strict_types=1);

namespace PsyTest\Core;

use DateTimeImmutable;

/**
 * Product-level lifecycle constants. Assignment of therapist_case is a
 * privileged backend action; public test creation always defaults anonymous.
 */
final class RetentionPolicy
{
    public const ANONYMOUS = 'anonymous';
    public const THERAPIST_CASE = 'therapist_case';

    /**
     * Результат, который посетитель явно сохранил в своём кабинете.
     *
     * Класс назначается только кнопкой самого посетителя и снимается при
     * отвязке или удалении аккаунта; автоматическая 180-дневная очистка к нему
     * не применяется, пока связь существует (D-053).
     */
    public const ACCOUNT = 'account';

    public function __construct(private readonly int $anonymousRetentionDays = 180)
    {
        if ($anonymousRetentionDays < 1) {
            throw new \InvalidArgumentException('Anonymous retention must be at least one day.');
        }
    }

    public function anonymousRetentionDays(): int
    {
        return $this->anonymousRetentionDays;
    }

    public function anonymousCutoff(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify("-{$this->anonymousRetentionDays} days");
    }

    public static function isKnownClass(string $retentionClass): bool
    {
        return in_array($retentionClass, [self::ANONYMOUS, self::THERAPIST_CASE, self::ACCOUNT], true);
    }
}
