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
     * @param AiTestSettings|null $testSettings Галочки методики (07.WP10): по
     *                                          ним к контексту добавляются ответы
     *                                          по пунктам. Без них — никогда.
     */
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly ModuleLoader $modules,
        private readonly ?AiSettings $ownerSettings = null,
        private readonly ?AiTestSettings $testSettings = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AiProviderException если разбирать нечего или модуль не отдаёт режим.
     */
    public function build(string $sessionId, string $testSlug, string $mode): array
    {
        $session = $this->sessions->getRetainedSessionById($sessionId);
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
        $context = SmilGlossaryCompactor::fromSettings($this->ownerSettings)->apply($context);

        // Ответы по пунктам — только по второй галочке владельца (07.WP10).
        if ($this->testSettings !== null && $this->testSettings->sendsItemAnswers($testSlug)) {
            $context = self::withItems($module, $context, $mode, (array) ($session['answers'] ?? []));
        }

        return $context;
    }

    /**
     * Привести готовую нагрузку к текущей галочке «ответы по пунктам».
     *
     * Нужна заданиям, поставленным до того, как владелец снял галочку: их
     * снимок заморожен с `items`, но наружу они уже уйти не должны. Модули,
     * у которых ответы по пунктам — часть утверждённого контекста (Лазарус),
     * не трогаются. Без настроек методик нагрузка не меняется.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function enforceItemPolicy(string $testSlug, string $mode, array $context): array
    {
        if ($this->testSettings === null || !array_key_exists('items', $context)) {
            return $context;
        }

        $module = $this->modules->getModule($testSlug);
        if ($module !== null && $module->aiReportSendsItemsAlways()) {
            return $context;
        }

        if ($mode === 'individual' && $this->testSettings->sendsItemAnswers($testSlug)) {
            return $context;
        }

        unset($context['items']);

        return $context;
    }

    /**
     * Добавить ответы по пунктам, если модуль сам их не отдаёт (07.WP10).
     *
     * Лазарус уже кладёт `items` в свой контекст — его нагрузка не меняется.
     * Для пары единой формы ответов нет: пары модули описывают сами.
     *
     * @param array<string, mixed> $context
     * @param array<int|string, mixed> $answers
     *
     * @return array<string, mixed>
     */
    public static function withItems(TestModuleInterface $module, array $context, string $mode, array $answers): array
    {
        if ($mode !== 'individual' || array_key_exists('items', $context)) {
            return $context;
        }

        $context['items'] = $module->aiReportItems($answers);

        return $context;
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

        $first = $this->sessions->getRetainedSessionById((string) $comparison['session_1_id']);
        $second = $this->sessions->getRetainedSessionById((string) $comparison['session_2_id']);

        if ($first === null || $second === null) {
            throw new AiProviderException('Одна из сессий пары не найдена.');
        }

        return $module->comparePairResults(
            (array) $this->sessions->withFreshResults($first, $module)['calculated_results'],
            (array) $this->sessions->withFreshResults($second, $module)['calculated_results'],
        );
    }
}
