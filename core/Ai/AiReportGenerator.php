<?php

declare(strict_types=1);

namespace PsyTest\Core\Ai;

use PsyTest\Core\ModuleLoader;
use PsyTest\Core\SessionManager;

/**
 * Превращает задание в готовый разбор.
 *
 * Здесь нет ни клинической логики, ни знания о конкретных методиках: контекст
 * собирает сам модуль (`aiReportContext()`), текст промпта приходит из реестра,
 * вызов делает адаптер провайдера. Задача этого класса — связать их и честно
 * записать исход.
 */
final class AiReportGenerator
{
    public function __construct(
        private readonly AiReportRepository $reports,
        private readonly SessionManager $sessions,
        private readonly ModuleLoader $modules,
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
            $context = $this->buildContext($report);
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
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    private function buildContext(array $report): array
    {
        $session = $this->sessions->getSessionById((string) $report['session_id']);
        if ($session === null) {
            throw new AiProviderException('Сессия разбора не найдена.');
        }

        $module = $this->modules->getModule((string) $report['test_slug']);
        if ($module === null) {
            throw new AiProviderException("Методика «{$report['test_slug']}» не найдена.");
        }

        $mode = (string) $report['mode'];
        $results = $mode === 'pair'
            ? $this->pairResults($module, $session)
            : (array) $session['calculated_results'];

        if ($results === []) {
            throw new AiProviderException('Результат сессии пуст — разбирать нечего.');
        }

        $context = $module->aiReportContext($results, $mode);
        if ($context === null) {
            throw new AiProviderException("Методика «{$report['test_slug']}» не отдаёт данные в режиме «{$mode}».");
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $session
     *
     * @return array<string, mixed>
     */
    private function pairResults(object $module, array $session): array
    {
        $comparison = $this->sessions->getPairComparisonBySession((string) $session['id']);
        if ($comparison === null) {
            throw new AiProviderException('Парное сравнение для этой сессии не найдено.');
        }

        $first = $this->sessions->getSessionById((string) $comparison['session_1_id']);
        $second = $this->sessions->getSessionById((string) $comparison['session_2_id']);

        if ($first === null || $second === null) {
            throw new AiProviderException('Одна из сессий пары не найдена.');
        }

        return $module->comparePairResults(
            (array) $first['calculated_results'],
            (array) $second['calculated_results'],
        );
    }
}
