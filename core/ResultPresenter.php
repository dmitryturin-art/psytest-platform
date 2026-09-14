<?php

declare(strict_types=1);

namespace PsyTest\Core;

use PsyTest\Core\Ai\AiReportRepository;
use PsyTest\Core\Ai\Prompt;
use PsyTest\Core\Ai\PromptRegistry;
use PsyTest\Modules\TestModuleInterface;

/**
 * Сборка данных страницы результата — один раз для всех входов.
 *
 * Результат открывается двумя путями: по bearer-ссылке и по владению
 * аккаунтом. Страница при этом обязана быть одной и той же, поэтому логика
 * секций, парного сравнения и блока разбора живёт здесь, а контроллеры лишь
 * решают, кому показывать.
 */
final class ResultPresenter
{
    public function __construct(
        private readonly Database $db,
        private readonly SessionManager $sessions,
    ) {
    }

    /**
     * Рассчитанные результаты вместе с парным сравнением, если оно собрано.
     *
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function results(array $session, TestModuleInterface $module): array
    {
        /** @var array<string, mixed> $results */
        $results = $session['calculated_results'] ?? [];
        if (!$module->supportsPairMode()) {
            return $results;
        }

        $comparison = $this->sessions->getPairComparisonBySession((string) $session['id']);
        if ($comparison === null) {
            return $results;
        }

        $partnerSessionId = (string) $comparison['session_1_id'] === (string) $session['id']
            ? (string) $comparison['session_2_id']
            : (string) $comparison['session_1_id'];
        $partnerSession = $this->sessions->getSessionById($partnerSessionId);

        $results['pair_comparison'] = $module->comparePairResults(
            $results,
            $partnerSession['calculated_results'] ?? [],
        );

        return $results;
    }

    /**
     * Парный разбор делается, когда сравнение уже собрано; иначе одиночный.
     *
     * @param array<string, mixed> $session
     */
    public function reportMode(array $session): string
    {
        return $this->sessions->getPairComparisonBySession((string) $session['id']) !== null
            ? 'pair'
            : 'individual';
    }

    /**
     * Данные блока расширенного разбора.
     *
     * Блок показывается, только если для этой методики и режима действительно
     * опубликован промпт: иначе посетителю предлагалась бы кнопка, которая
     * ничего не сделает.
     *
     * `$readonly` включается в кабинете: заказать разбор можно только там, где
     * у посетителя есть сама ссылка результата, потому что форма заказа несёт
     * bearer-токен, а в кабинет он не выводится.
     *
     * @param array<string, mixed> $session
     * @return array<string, mixed>|null
     */
    public function reportViewData(string $slug, array $session, bool $readonly = false): ?array
    {
        $mode = $this->reportMode($session);
        if (($session['retention_class'] ?? null) === RetentionPolicy::THERAPIST_CASE) {
            return ['restricted' => true, 'mode' => $mode, 'kinds' => [], 'readonly' => $readonly];
        }

        $registry = PromptRegistry::default();
        $reports = new AiReportRepository($this->db);

        $kinds = [];
        foreach ([Prompt::KIND_CLEAR, Prompt::KIND_PROFESSIONAL] as $kind) {
            if ($registry->published($slug, $mode, $kind) === null) {
                continue;
            }

            $report = $reports->findFor((string) $session['id'], $mode, $kind);

            $kinds[] = [
                'kind' => $kind,
                'title' => $kind === Prompt::KIND_CLEAR ? 'Понятный разбор' : 'Профессиональное заключение',
                'status' => $report['status'] ?? 'none',
                'html' => ($report['status'] ?? '') === AiReportRepository::STATUS_READY
                    ? ReportMarkdown::toHtml((string) $report['content'])
                    : null,
                'failure_reason' => $report['failure_reason'] ?? null,
            ];
        }

        return $kinds === [] ? null : ['mode' => $mode, 'kinds' => $kinds, 'readonly' => $readonly];
    }

    /**
     * Полный набор переменных шаблона `result-layout`.
     *
     * @param array<string, mixed> $session
     * @param array<string, mixed> $test
     * @return array<string, mixed>
     */
    public function viewData(
        array $session,
        array $test,
        TestModuleInterface $module,
        string $resultBase,
        bool $accountView = false,
    ): array {
        $results = $this->results($session, $module);

        return [
            'test' => $test,
            'session' => $session,
            'sections' => $module->buildSections($results),
            'results' => $results,
            'clinical_safety_notice' => ClinicalSafetyNotice::fromResults($results),
            'ai_report' => $this->reportViewData((string) $test['slug'], $session, $accountView),
            'result_base' => $resultBase,
            'account_view' => $accountView,
        ];
    }

    /**
     * Секции для печати.
     *
     * Пометка `is_pdf` гасит приглашение партнёру: ссылка-приглашение в
     * распечатанном документе бессмысленна и опасна.
     *
     * @param array<string, mixed> $session
     * @return array{sections: list<mixed>, includes_pair_comparison: bool}
     */
    public function pdfSections(array $session, TestModuleInterface $module): array
    {
        $results = $this->results($session, $module);
        $includesPairComparison = isset($results['pair_comparison']);
        $results['is_pdf'] = true;

        return [
            'sections' => $module->buildSections($results),
            'includes_pair_comparison' => $includesPairComparison,
        ];
    }
}
