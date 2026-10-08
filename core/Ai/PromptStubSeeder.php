<?php

declare(strict_types=1);

namespace PsyTest\Core\Ai;

/**
 * Заготовки промптов для методики, у которой их ещё нет (07.WP10, D-056).
 *
 * Когда владелец включает ИИ-разбор методики, в «Промптах» должны появиться
 * два ключа — понятный разбор и профессиональное заключение, — чтобы их можно
 * было отредактировать и опубликовать. Текст берётся из универсального
 * шаблона `prompts/_universal/`, в который подставляется название методики.
 *
 * Заготовка — всегда черновик: заказ по ней невозможен, пока владелец её не
 * опубликует (PRODUCT_RULES §6: у каждого ключа свой проверенный текст).
 * Существующие ключи (СМИЛ, Лазарус, уже созданные заготовки) не трогаются.
 */
final class PromptStubSeeder
{
    public const NOTE = 'заготовка из универсального шаблона';
    public const MODE = 'individual';

    public function __construct(
        private readonly PromptRegistry $registry,
        private readonly string $templatesPath,
    ) {
    }

    public static function default(PromptRegistry $registry): self
    {
        return new self($registry, dirname(__DIR__, 2) . '/prompts/_universal');
    }

    /**
     * Создать недостающие заготовки; повторный вызов ничего не создаёт.
     *
     * @return list<string> ключи созданных заготовок
     */
    public function ensureFor(string $test, string $testName): array
    {
        $created = [];

        foreach ([Prompt::KIND_CLEAR, Prompt::KIND_PROFESSIONAL] as $kind) {
            if ($this->registry->hasKey($test, self::MODE, $kind)) {
                continue;
            }

            $version = $this->registry->seedOwnerDraft(
                $test,
                self::MODE,
                $kind,
                $this->template($kind, $testName),
                self::NOTE,
                // Клинический контекст специалиста адресован специалисту: его
                // принимает только профессиональное заключение, как у СМИЛ и
                // Лазаруса.
                $kind === Prompt::KIND_PROFESSIONAL,
            );

            if ($version !== null) {
                $created[] = Prompt::keyFor($test, self::MODE, $kind);
            }
        }

        return $created;
    }

    public function template(string $kind, string $testName): string
    {
        $path = sprintf('%s/%s.%s.v1.md', $this->templatesPath, self::MODE, $kind);
        $text = @file_get_contents($path);
        if ($text === false) {
            throw new \RuntimeException('Универсальный шаблон промпта не найден.');
        }

        return str_replace('{{test_name}}', $testName, $text);
    }
}
