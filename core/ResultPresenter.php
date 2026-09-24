<?php

declare(strict_types=1);

namespace PsyTest\Core;

use PsyTest\Core\Ai\AiReportRepository;
use PsyTest\Core\Ai\AiReportRevisionService;
use PsyTest\Core\Ai\AiSettings;
use PsyTest\Core\Ai\Prompt;
use PsyTest\Core\Ai\PromptRegistry;
use PsyTest\Modules\ResultSection;
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
     * Порядок партнёров в сравнении канонический и не зависит от того, чью
     * страницу мы собираем: `session_1_id` — начавший опросник, `session_2_id`
     * — приглашённый. Раньше сюда подставлялась текущая сессия как «первая», и
     * у приглашённого партнёра его собственные оценки оказывались подписаны
     * «Начавший». Тот же канонический порядок используют `pairViewData()`,
     * `AiReportContextBuilder` и маршруты `/pair/{id}`.
     *
     * Чтобы страница знала, какая колонка принадлежит смотрящему, рядом
     * кладётся `pair_viewer_position` (1 или 2). Числа и scoring от этого не
     * меняются: `comparePairResults()` получает те же два набора результатов.
     *
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function results(array $session, TestModuleInterface $module): array
    {
        // Сохранённый результат мог быть посчитан на прежнем реестре
        // дополнительных шкал: страница и PDF берут его через единую точку.
        $session = $this->sessions->withFreshResults($session, $module);
        /** @var array<string, mixed> $results */
        $results = $session['calculated_results'] ?? [];
        if (!$module->supportsPairMode()) {
            return $results;
        }

        $comparison = $this->sessions->getPairComparisonBySession((string) $session['id']);
        if ($comparison === null) {
            return $results;
        }

        $sessionId = (string) $session['id'];
        $firstId = (string) $comparison['session_1_id'];
        $secondId = (string) $comparison['session_2_id'];
        $position = $firstId === $sessionId ? 1 : 2;

        $partnerSessionId = $position === 1 ? $secondId : $firstId;
        $partnerSession = $this->sessions->getSessionById($partnerSessionId);
        if ($partnerSession !== null) {
            $partnerSession = $this->sessions->withFreshResults($partnerSession, $module);
        }
        /** @var array<string, mixed> $partnerResults */
        $partnerResults = $partnerSession['calculated_results'] ?? [];

        $results['pair_comparison'] = $position === 1
            ? $module->comparePairResults($results, $partnerResults)
            : $module->comparePairResults($partnerResults, $results);
        $results['pair_viewer_position'] = $position;

        return $results;
    }

    /**
     * Парная часть страницы результата без клиентских действий и токена.
     *
     * Кабинет специалиста показывает тот же парный результат, что и клиент, но
     * не имеет права показать bearer-ссылку: поэтому наружу отдаются только
     * парные секции (график и сравнение), без приглашения партнёру и без
     * каких-либо полей сессии. Расчёт остаётся один — `comparePairResults()`.
     *
     * Порядок партнёров канонический: `session_1_id` — тот, кто начал
     * опросник, `session_2_id` — приглашённый. Ровно так же пара уходит во
     * внешний разбор (`AiReportContextBuilder`), и ровно так подписаны
     * колонки блока сравнения.
     *
     * @param array<string, mixed> $session
     * @return array{
     *     position: int,
     *     partner_position: int,
     *     partner_session_id: string,
     *     sections: list<ResultSection>
     * }|null
     */
    public function pairViewData(array $session, TestModuleInterface $module): ?array
    {
        if (!$module->supportsPairMode()) {
            return null;
        }

        $comparison = $this->sessions->getPairComparisonBySession((string) $session['id']);
        if ($comparison === null) {
            return null;
        }

        $firstId = (string) $comparison['session_1_id'];
        $secondId = (string) $comparison['session_2_id'];
        $first = $this->sessions->getSessionById($firstId);
        $second = $this->sessions->getSessionById($secondId);
        if ($first === null || $second === null) {
            return null;
        }
        $first = $this->sessions->withFreshResults($first, $module);
        $second = $this->sessions->withFreshResults($second, $module);

        $position = $firstId === (string) $session['id'] ? 1 : 2;

        /** @var array<string, mixed> $results */
        $results = $first['calculated_results'] ?? [];
        $results['pair_comparison'] = $module->comparePairResults(
            $first['calculated_results'] ?? [],
            $second['calculated_results'] ?? [],
        );

        $pairTypes = [ResultSection::TYPE_PAIR_CHART, ResultSection::TYPE_PAIR_COMPARISON];
        $sections = array_values(array_filter(
            $module->buildSections($results),
            static fn (ResultSection $section): bool => in_array($section->type, $pairTypes, true),
        ));

        return [
            'position' => $position,
            'partner_position' => $position === 1 ? 2 : 1,
            'partner_session_id' => $position === 1 ? $secondId : $firstId,
            'sections' => $sections,
        ];
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
            // Черновики, их статусы и профессиональное заключение сюда не
            // попадают вовсе (K0b). Единственное, что клиент может увидеть, —
            // версия, которую специалист явно опубликовал (D-054).
            return [
                'restricted' => true,
                'mode' => $mode,
                'kinds' => [],
                'readonly' => $readonly,
                'ai_disabled' => !(new AiSettings($this->db))->isAiEnabled(),
                'published' => $this->publishedReport((string) $session['id']),
            ];
        }

        $registry = PromptRegistry::default($this->db);
        $reports = new AiReportRepository($this->db);
        // Выключатель владельца (07.WP9): кнопку заказа показывать нечестно —
        // задание всё равно ушло бы в отказ. Готовые разборы остаются видны.
        $aiDisabled = !(new AiSettings($this->db))->isAiEnabled();

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

        return $kinds === [] ? null : [
            'mode' => $mode,
            'kinds' => $kinds,
            'readonly' => $readonly,
            'ai_disabled' => $aiDisabled,
        ];
    }

    /**
     * Одобренная специалистом редакция разбора для страницы клиента.
     *
     * Рендерится тем же белым списком разметки, что и остальной разбор: текст
     * пришёл от модели, пусть и после правки человеком.
     *
     * @return array{html: string, published_at: string}|null
     */
    public function publishedReport(string $sessionId): ?array
    {
        $published = (new AiReportRevisionService($this->db))->publishedContent($sessionId);

        return $published === null ? null : [
            'html' => ReportMarkdown::toHtml($published['content']),
            'published_at' => $published['published_at'],
        ];
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
     * @return array{sections: list<mixed>, includes_pair_comparison: bool, published_report_html: string}
     */
    public function pdfSections(array $session, TestModuleInterface $module): array
    {
        $results = $this->results($session, $module);
        $includesPairComparison = isset($results['pair_comparison']);
        $results['is_pdf'] = true;

        return [
            'sections' => $module->buildSections($results),
            'includes_pair_comparison' => $includesPairComparison,
            'published_report_html' => $this->publishedReportPdfHtml($session),
        ];
    }

    /**
     * Раздел «Разбор специалиста» в печатном документе.
     *
     * Клиент специалиста получает ровно ту редакцию, которую специалист
     * опубликовал; без публикации в PDF ничего не добавляется (D-054).
     *
     * @param array<string, mixed> $session
     */
    private function publishedReportPdfHtml(array $session): string
    {
        if (($session['retention_class'] ?? null) !== RetentionPolicy::THERAPIST_CASE) {
            return '';
        }

        $published = $this->publishedReport((string) $session['id']);

        return $published === null
            ? ''
            : '<div class="results-section results-section--specialist-report">'
                . '<h2 class="section-title">Разбор специалиста</h2>'
                . '<div class="section-body">' . $published['html'] . '</div>'
                . '</div>';
    }
}
