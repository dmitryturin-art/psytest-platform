<?php

declare(strict_types=1);

namespace PsyTest\Core\Ai;

/**
 * Одна версия system prompt для сочетания «методика + режим + вид отчёта».
 *
 * PRODUCT_RULES §6: универсального клинического промпта нет. Каждый ключ имеет
 * собственную версионированную формулировку, и профессиональный вариант никогда
 * не смягчается общим фильтром.
 */
final class Prompt
{
    public const KIND_PROFESSIONAL = 'professional';
    public const KIND_CLEAR = 'clear';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    public function __construct(
        public readonly string $test,
        public readonly string $mode,
        public readonly string $kind,
        public readonly int $version,
        public readonly string $status,
        public readonly string $text,
        public readonly bool $allowsOwnerContext,
        public readonly string $source,
    ) {
    }

    /** Ключ вида «smil | individual | professional» (PRODUCT_RULES §6). */
    public function key(): string
    {
        return self::keyFor($this->test, $this->mode, $this->kind);
    }

    public static function keyFor(string $test, string $mode, string $kind): string
    {
        return $test . ' | ' . $mode . ' | ' . $kind;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
