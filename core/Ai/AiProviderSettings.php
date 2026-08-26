<?php

declare(strict_types=1);

namespace PsyTest\Core\Ai;

/**
 * Настройки провайдера ИИ (D-039).
 *
 * Провайдером считается любой сервис с OpenAI-совместимым `chat/completions`,
 * поэтому смена провайдера — это смена base URL и ключа, а не правка кода.
 * Ключ живёт только в environment и никогда не попадает ни в Git, ни в вывод.
 */
final class AiProviderSettings
{
    public const DEFAULT_BASE_URL = 'https://openrouter.ai/api/v1';
    public const DEFAULT_MODEL = 'openrouter/free';
    public const DEFAULT_TIMEOUT_SECONDS = 120;

    public function __construct(
        public readonly string $baseUrl,
        private readonly string $apiKey,
        public readonly string $model,
        public readonly int $timeoutSeconds,
    ) {
    }

    public static function fromConfig(object $config): self
    {
        // AI_* — актуальные имена. OPENROUTER_* поддерживаются как исторические:
        // ключ уже лежит в .env под старым именем, ломать это незачем.
        $key = (string) $config->getString('AI_API_KEY', '');
        if ($key === '') {
            $key = (string) $config->getString('OPENROUTER_API_KEY', '');
        }

        $model = (string) $config->getString('AI_MODEL', '');
        if ($model === '') {
            $model = (string) $config->getString('OPENROUTER_MODEL', self::DEFAULT_MODEL);
        }

        return new self(
            baseUrl: rtrim((string) $config->getString('AI_BASE_URL', self::DEFAULT_BASE_URL), '/'),
            apiKey: $key,
            model: $model !== '' ? $model : self::DEFAULT_MODEL,
            timeoutSeconds: max(5, (int) $config->getInt('AI_TIMEOUT_SECONDS', self::DEFAULT_TIMEOUT_SECONDS)),
        );
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->baseUrl !== '';
    }

    /**
     * Ключ отдаётся только тому, кто формирует заголовок запроса.
     */
    public function authorizationHeader(): string
    {
        return 'Bearer ' . $this->apiKey;
    }

    /**
     * Описание настроек для журнала и кабинета — без ключа.
     *
     * @return array<string, string|int|bool>
     */
    public function describe(): array
    {
        return [
            'base_url' => $this->baseUrl,
            'model' => $this->model,
            'timeout_seconds' => $this->timeoutSeconds,
            'key_present' => $this->apiKey !== '',
        ];
    }
}
