<?php

declare(strict_types=1);

namespace PsyTest\Core\Ai;

use PsyTest\Core\ModuleLoader;
use PsyTest\Core\SessionManager;

/**
 * Сбор разрешённого контекста для внешнего ИИ.
 *
 * Вынесен из обработчика, потому что контекст строится при постановке задания
 * и замораживается в нём (аудит R2): иначе провайдер получил бы результат,
 * изменившийся после того, как посетитель нажал кнопку.
 *
 * Клинической логики здесь нет: что именно уходит наружу, решает сам модуль в
 * `aiReportContext()` (PRODUCT_RULES §6 и §11).
 */
final class AiReportContextBuilder
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly ModuleLoader $modules,
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AiProviderException если разбирать нечего или модуль не отдаёт режим.
     */
    public function build(string $sessionId, string $testSlug, string $mode): array
    {
        $session = $this->sessions->getSessionById($sessionId);
        if ($session === null) {
            throw new AiProviderException('Сессия разбора не найдена.');
        }

        $module = $this->modules->getModule($testSlug);
        if ($module === null) {
            throw new AiProviderException("Методика «{$testSlug}» не найдена.");
        }

        $results = $mode === 'pair'
            ? $this->pairResults($module, $session)
            : (array) $session['calculated_results'];

        if ($results === []) {
            throw new AiProviderException('Результат сессии пуст — разбирать нечего.');
        }

        $context = $module->aiReportContext($results, $mode);
        if ($context === null) {
            throw new AiProviderException("Методика «{$testSlug}» не отдаёт данные в режиме «{$mode}».");
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
