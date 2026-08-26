<?php

declare(strict_types=1);

namespace PsyTest\Core\Ai;

/**
 * Ответ провайдера вместе с тем, что нужно для воспроизводимости отчёта:
 * какая модель фактически отвечала и сколько токенов ушло.
 *
 * Различие между запрошенной и фактической моделью существенно: router вроде
 * `openrouter/free` сам выбирает исполнителя, и без фактического имени модели
 * отчёт нельзя ни повторить, ни объяснить.
 */
final class AiCompletion
{
    public function __construct(
        public readonly string $text,
        public readonly string $requestedModel,
        public readonly string $servedModel,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
    ) {
    }

    public function usedRouter(): bool
    {
        return $this->servedModel !== '' && $this->servedModel !== $this->requestedModel;
    }

    /** @return array<string, string|int> */
    public function provenance(): array
    {
        return [
            'requested_model' => $this->requestedModel,
            'served_model' => $this->servedModel,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
        ];
    }
}
