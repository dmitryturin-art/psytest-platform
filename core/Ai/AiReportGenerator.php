<?php

declare(strict_types=1);

namespace PsyTest\Core\Ai;

/**
 * Превращает задание в готовый разбор.
 *
 * Здесь нет ни клинической логики, ни знания о конкретных методиках: вход
 * задания заморожен при постановке (аудит R2), вызов делает адаптер провайдера.
 * Задача этого класса — связать их и честно записать исход.
 *
 * Задания, поставленные до появления снимков, обрабатываются по-прежнему —
 * через реестр промптов и живой результат сессии.
 */
final class AiReportGenerator
{
    public function __construct(
        private readonly AiReportRepository $reports,
        private readonly AiReportContextBuilder $contextBuilder,
        private readonly PromptRegistry $prompts,
        private readonly AiClient $client,
    ) {
    }

    /**
     * @param array<string, mixed> $report Задание, уже взятое в работу.
     */
    public function process(array $report): void
    {
        $id = (string) $report['id'];

        try {
            [$prompt, $context] = $this->input($report);

            $ownerContext = $report['owner_context'] ?? null;
            $completion = $this->client->complete($prompt, $context, is_string($ownerContext) ? $ownerContext : null);

            $this->reports->markReady($id, $completion);
        } catch (AiProviderException $e) {
            $this->reports->markFailed($id, $e->getMessage());
        } catch (\Throwable $e) {
            // Любой иной сбой тоже обязан закрыть задание: иначе оно останется
            // висеть в работе и заблокирует повтор.
            $this->reports->markFailed($id, 'Внутренняя ошибка при подготовке разбора: ' . $e->getMessage());
        }
    }

    /**
     * Что именно уходит провайдеру.
     *
     * @param array<string, mixed> $report
     *
     * @return array{0: Prompt, 1: array<string, mixed>}
     */
    private function input(array $report): array
    {
        $context = self::decodeSnapshot($report['context_snapshot'] ?? null);
        $promptSnapshot = self::decodeSnapshot($report['prompt_snapshot'] ?? null);

        if ($context !== null && $promptSnapshot !== null) {
            // Снимок самодостаточен: промпт восстанавливается без реестра и
            // файлов, а проверка публикации уже была сделана при постановке.
            return [Prompt::fromSnapshot($promptSnapshot), $context];
        }

        // Задание старше миграции снимков. Запись одной строкой, без каких-либо
        // клинических данных: в логах не должно быть ни контекста, ни отчёта.
        error_log(sprintf('AI report %s: legacy job without snapshot', $report['id']));

        $prompt = $this->prompts->published(
            (string) $report['test_slug'],
            (string) $report['mode'],
            (string) $report['report_kind'],
        );

        if ($prompt === null) {
            throw new AiProviderException(
                "Промпт «{$report['prompt_key']}» не опубликован — разбор не делается.",
            );
        }

        return [
            $prompt,
            $this->contextBuilder->build(
                (string) $report['session_id'],
                (string) $report['test_slug'],
                (string) $report['mode'],
            ),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeSnapshot(mixed $raw): ?array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) && $decoded !== [] ? $decoded : null;
    }
}
