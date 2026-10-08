<?php

declare(strict_types=1);

namespace PsyTest\Core\Ai;

/**
 * Выполняет взятый в работу пробный разбор (07.K14a).
 *
 * Провайдеру уходит только то, что лежит в снимке строки: промпт и
 * синтетический контекст. Сессий и клиентов этот путь не знает.
 */
final class AiTrialRunner
{
    public function __construct(
        private readonly AiTrialRepository $trials,
        private readonly AiClient $client,
    ) {
    }

    /**
     * @param array<string, mixed> $trial
     */
    public function process(array $trial): void
    {
        $id = (string) $trial['id'];

        try {
            $prompt = json_decode((string) $trial['prompt_snapshot'], true, 512, JSON_THROW_ON_ERROR);
            $context = json_decode((string) $trial['context_snapshot'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($prompt) || !is_array($context)) {
                throw new AiProviderException('Снимок пробного разбора повреждён.');
            }

            $this->trials->markReady($id, $this->client->complete(Prompt::fromSnapshot($prompt), $context));
        } catch (AiProviderException $e) {
            $this->trials->markFailed($id, $e->getMessage());
        } catch (\Throwable) {
            // Текст исключения во внешний вывод не идёт: причина владельцу общая.
            $this->trials->markFailed($id, 'Внутренняя ошибка при пробном разборе.');
        }
    }
}
