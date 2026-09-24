<?php

declare(strict_types=1);

namespace PsyTest\Core\Ai;

use PsyTest\Core\ModuleLoader;
use PsyTest\Core\SessionManager;
use PsyTest\Modules\TestModuleInterface;

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
    /**
     * @param AiSettings|null $ownerSettings Настройки кабинета; сейчас из них
     *                                       берётся режим глоссария СМИЛ (07.G6).
     *                                       Без них режим — полный, как до пакета.
     */
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly ModuleLoader $modules,
        private readonly ?AiSettings $ownerSettings = null,
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

        // Контекст замораживается в задании, поэтому берётся уже актуальный
        // результат — тот же, что на странице и в PDF (05.S4a).
        $results = $mode === 'pair'
            ? $this->pairResults($module, $session)
            : (array) $this->sessions->withFreshResults($session, $module)['calculated_results'];

        if ($results === []) {
            throw new AiProviderException('Результат сессии пуст — разбирать нечего.');
        }

        $context = $module->aiReportContext($results, $mode);
        if ($context === null) {
            throw new AiProviderException("Методика «{$testSlug}» не отдаёт данные в режиме «{$mode}».");
        }

        // Сжатие идёт поверх готовой нагрузки: модуль решает, что вообще
        // уходит наружу, а настройка владельца — сколько из этого пояснять.
        return SmilGlossaryCompactor::fromSettings($this->ownerSettings)->apply($context);
    }

    /**
     * @param array<string, mixed> $session
     *
     * @return array<string, mixed>
     */
    private function pairResults(TestModuleInterface $module, array $session): array
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
            (array) $this->sessions->withFreshResults($first, $module)['calculated_results'],
            (array) $this->sessions->withFreshResults($second, $module)['calculated_results'],
        );
    }
}
