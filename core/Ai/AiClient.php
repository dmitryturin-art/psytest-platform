<?php

declare(strict_types=1);

namespace PsyTest\Core\Ai;

/**
 * Клиент к OpenAI-совместимому провайдеру (D-039).
 *
 * Умеет ровно две вещи: попросить разбор и показать каталог моделей для
 * выпадающего списка в кабинете. Никакой клинической логики здесь нет —
 * system prompt приходит из реестра, полезная нагрузка из модуля.
 */
final class AiClient
{
    /** Статусы, при которых повтор осмыслен: перегрузка пула и сбои на стороне провайдера. */
    private const RETRYABLE_STATUSES = [408, 409, 425, 429, 500, 502, 503, 504];

    /**
     * @param callable(int): void|null $sleeper Пауза между попытками; подменяется в тестах,
     *                                          чтобы они не ждали по-настоящему.
     */
    public function __construct(
        private readonly AiProviderSettings $settings,
        private readonly AiTransport $transport,
        private readonly int $maxAttempts = 3,
        private $sleeper = null,
    ) {
    }

    /**
     * @param array<string, mixed> $context Структурированная нагрузка модуля.
     * @param string|null $ownerContext Клинический контекст специалиста, если промпт его допускает.
     */
    public function complete(Prompt $prompt, array $context, ?string $ownerContext = null): AiCompletion
    {
        if (!$prompt->isPublished()) {
            throw new AiProviderException("Промпт «{$prompt->key()}» не опубликован — вызов запрещён.");
        }

        if ($ownerContext !== null && trim($ownerContext) !== '' && !$prompt->allowsOwnerContext) {
            throw new AiProviderException("Промпт «{$prompt->key()}» не принимает клинический контекст специалиста.");
        }

        $this->requireConfigured();

        $messages = [
            ['role' => 'system', 'content' => $prompt->text],
            ['role' => 'user', 'content' => $this->renderContext($context)],
        ];

        if ($ownerContext !== null && trim($ownerContext) !== '') {
            $messages[] = [
                'role' => 'user',
                'content' => "Клинический контекст от специалиста:\n" . trim($ownerContext),
            ];
        }

        $response = $this->send('POST', '/chat/completions', [
            'model' => $this->settings->model,
            'messages' => $messages,
        ]);

        $text = $response['choices'][0]['message']['content'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new AiProviderException('Провайдер вернул пустой ответ.');
        }

        return new AiCompletion(
            text: trim($text),
            requestedModel: $this->settings->model,
            servedModel: (string) ($response['model'] ?? ''),
            promptTokens: (int) ($response['usage']['prompt_tokens'] ?? 0),
            completionTokens: (int) ($response['usage']['completion_tokens'] ?? 0),
        );
    }

    /**
     * Каталог моделей провайдера. Владелец выбирает из списка либо вписывает
     * идентификатор вручную — список нужен для удобства, а не как ограничение.
     *
     * @return list<AiModel>
     */
    public function models(): array
    {
        $this->requireConfigured();

        $response = $this->send('GET', '/models', null);
        $models = [];

        foreach ($response['data'] ?? [] as $item) {
            if (!isset($item['id'])) {
                continue;
            }

            $pricing = $item['pricing'] ?? [];
            $models[] = new AiModel(
                id: (string) $item['id'],
                name: (string) ($item['name'] ?? $item['id']),
                contextLength: (int) ($item['context_length'] ?? 0),
                isFree: (float) ($pricing['prompt'] ?? 1) === 0.0 && (float) ($pricing['completion'] ?? 1) === 0.0,
            );
        }

        return $models;
    }

    private function requireConfigured(): void
    {
        if (!$this->settings->isConfigured()) {
            throw new AiProviderException('Провайдер ИИ не настроен: нет ключа или адреса. Ключ задаётся только в environment.');
        }
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $body): array
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            $result = $this->transport->request(
                $method,
                $this->settings->baseUrl . $path,
                [
                    'Authorization' => $this->settings->authorizationHeader(),
                    'Content-Type' => 'application/json',
                ],
                $body,
                $this->settings->timeoutSeconds,
            );

            $status = $result['status'];

            if ($status >= 200 && $status < 300) {
                $decoded = json_decode($result['body'], true);
                if (!is_array($decoded)) {
                    throw new AiProviderException('Провайдер вернул нечитаемый ответ.');
                }

                return $decoded;
            }

            // Бесплатные эндпоинты живут в общем пуле и регулярно отвечают 429
            // «перегружено, повторите» — без повтора такой поток нерабочий.
            if (in_array($status, self::RETRYABLE_STATUSES, true) && $attempt < $this->maxAttempts) {
                $this->pause($attempt);
                continue;
            }

            // Тело ошибки провайдера не пересказывается: в нём может оказаться
            // эхо запроса, то есть клинические данные. Называется только категория.
            throw new AiProviderException(sprintf(
                'Провайдер ответил HTTP %d (%s); попыток: %d.',
                $status,
                self::describeStatus($status),
                $attempt,
            ));
        }
    }

    private function pause(int $attempt): void
    {
        $seconds = min(8, 2 ** ($attempt - 1));

        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);

            return;
        }

        sleep($seconds);
    }

    private static function describeStatus(int $status): string
    {
        return match (true) {
            $status === 401 => 'ключ отклонён',
            // 403 не обязательно про ключ: OpenRouter отвечает так на запросы
            // с адреса нашего хостинга ещё до проверки ключа (проверено 27.08).
            $status === 403 => 'доступ запрещён — ключ либо адрес отправителя',
            $status === 402 => 'недостаточно средств на счёте провайдера',
            $status === 404 => 'модель недоступна по текущей политике данных аккаунта',
            $status === 429 => 'модель перегружена или превышен лимит запросов',
            $status >= 500 => 'сбой на стороне провайдера',
            default => 'запрос отклонён',
        };
    }

    /** @param array<string, mixed> $context */
    private function renderContext(array $context): string
    {
        return json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}
