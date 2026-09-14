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

    /**
     * Снимок промпта для задания (аудит R2).
     *
     * Сохраняется всё, что читает `AiClient::complete` — текст, статус и
     * разрешение на контекст специалиста, — чтобы обработчик воссоздал ровно
     * тот промпт, который был опубликован при постановке, не обращаясь ни к
     * манифесту, ни к файлам.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'key' => $this->key(),
            'test' => $this->test,
            'mode' => $this->mode,
            'kind' => $this->kind,
            'version' => $this->version,
            'status' => $this->status,
            'text' => $this->text,
            'allows_owner_context' => $this->allowsOwnerContext,
            'source' => $this->source,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @throws \InvalidArgumentException если снимок неполон — молча подставлять
     *                                   «что-нибудь» здесь нельзя.
     */
    public static function fromSnapshot(array $snapshot): self
    {
        foreach (['test', 'mode', 'kind', 'version', 'status', 'text'] as $required) {
            if (!isset($snapshot[$required])) {
                throw new \InvalidArgumentException("В снимке промпта нет поля «{$required}».");
            }
        }

        return new self(
            test: (string) $snapshot['test'],
            mode: (string) $snapshot['mode'],
            kind: (string) $snapshot['kind'],
            version: (int) $snapshot['version'],
            status: (string) $snapshot['status'],
            text: (string) $snapshot['text'],
            allowsOwnerContext: (bool) ($snapshot['allows_owner_context'] ?? false),
            source: (string) ($snapshot['source'] ?? ''),
        );
    }
}
