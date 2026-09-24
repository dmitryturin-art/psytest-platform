<?php

declare(strict_types=1);

namespace PsyTest\Core;

use PsyTest\Core\Ai\AiReportRepository;
use PsyTest\Core\Ai\AiReportRevisionService;
use PsyTest\Core\Ai\Prompt;
use PsyTest\Modules\TestModuleInterface;

/**
 * Единая сборка выгрузки кейса специалиста (07.K5j).
 *
 * Владелец просил получать результат вместе с интерпретациями одним куском —
 * чтобы сохранить или распечатать. Документ собирается здесь один раз и для
 * PDF, и для версии под печать: иначе два выхода неминуемо разошлись бы по
 * составу, и специалист не знал бы, что именно он отдаёт.
 *
 * Ничего не считается заново: базовый результат приходит теми же секциями
 * модуля, что видит клиент, парная часть — из `ResultPresenter::pairViewData()`,
 * анкеты — из `InvitedCasePresenter::answers()`. Канонический график СМИЛ здесь
 * не перерисовывается; печатная страница берёт тот же блок, что и карточка,
 * а PDF — тот же путь `ResultSectionRenderer`, которым собирается PDF результата.
 *
 * Идентификаторы сессии, bearer-токены и ссылки `/result/` в документ не
 * попадают: он предназначен для печати и пересылки, и токен в нём означал бы
 * выданный доступ (PRODUCT_RULES §11).
 */
final class CaseExportPresenter
{
    /** Скрытое поле формы: по нему видно, что снятые галочки сняты специально. */
    public const FORM_MARKER = 'export_options';

    public const DISCLAIMER = 'Результаты данного тестирования носят ознакомительный характер '
        . 'и не заменяют очную консультацию специалиста.';

    public function __construct(
        private readonly Database $db,
        private readonly SessionManager $sessions,
    ) {
    }

    /**
     * Выбор специалиста из формы «Выгрузить».
     *
     * Без маркера формы (прямая ссылка, закладка) действуют значения по
     * умолчанию: оба разбора входят, анкеты и заметка — нет. С маркером
     * снятая галочка означает именно «не включать»: браузер не присылает
     * невыбранный checkbox, и отличить его от «параметр не передан» можно
     * только так.
     *
     * @param array<string, mixed> $query
     * @return array{
     *     include_professional: bool,
     *     include_clear: bool,
     *     include_answers: bool,
     *     include_note: bool
     * }
     */
    public static function options(array $query): array
    {
        $submitted = isset($query[self::FORM_MARKER]);
        $flag = static fn (string $key, bool $default): bool => $submitted
            ? ($query[$key] ?? null) === '1'
            : $default;

        return [
            'include_professional' => $flag('include_professional', true),
            'include_clear' => $flag('include_clear', true),
            'include_answers' => $flag('include_answers', false),
            'include_note' => $flag('include_note', false),
        ];
    }

    /**
     * Готовый документ выгрузки.
     *
     * @param array<string, mixed> $case Кейс из `TestInviteService::claimedCaseForOwner()`.
     * @param array{
     *     include_professional: bool,
     *     include_clear: bool,
     *     include_answers: bool,
     *     include_note: bool
     * } $options
     * @return array<string, mixed>
     */
    public function build(array $case, TestModuleInterface $module, array $options): array
    {
        $sessionId = (string) $case['id'];
        $presenter = new InvitedCasePresenter();
        // Выгрузка совпадает со страницей кейса: тот же актуальный результат.
        $case = $this->sessions->withFreshResults($case, $module);

        /** @var array<string, mixed> $results */
        $results = is_array($case['calculated_results'] ?? null) ? $case['calculated_results'] : [];
        /** @var array<string|int, mixed> $answers */
        $answers = is_array($case['answers'] ?? null) ? $case['answers'] : [];

        $pair = $this->pair($sessionId, $module, $presenter, $answers, $options['include_answers']);

        return [
            'header' => [
                'test_name' => (string) ($case['test_name'] ?? ''),
                // Подпись клиента — та, что специалист выбрал сам; имени,
                // email и идентификаторов в документе нет.
                'client_label' => $this->text($case['client_label'] ?? null),
                'completed_at' => $this->text($case['completed_at'] ?? null),
                'prepared_by' => 'Подготовил: специалист',
                'confidential' => 'Конфиденциально',
            ],
            'sections' => $presenter->resultSections($module, $results),
            'pair' => $pair,
            'answers' => $options['include_answers'] && $pair === null
                ? $presenter->answers($module, $answers)
                : [],
            'professional' => $options['include_professional']
                ? $this->professional($sessionId, $module)
                : null,
            'clear' => $options['include_clear'] ? $this->clear($sessionId, $module) : null,
            'note' => $options['include_note'] ? $this->text($case['owner_note'] ?? null) : null,
            'generated_at' => date('d.m.Y H:i'),
            'disclaimer' => self::DISCLAIMER,
            'options' => $options,
        ];
    }

    /**
     * Парная часть документа: совмещённый профиль, сравнение и обе анкеты.
     *
     * Вторая сессия остаётся чужой — из неё берутся только ответы, без
     * идентификатора, токена и прав.
     *
     * @param array<string|int, mixed> $caseAnswers
     * @return array<string, mixed>|null
     */
    private function pair(
        string $sessionId,
        TestModuleInterface $module,
        InvitedCasePresenter $presenter,
        array $caseAnswers,
        bool $includeAnswers,
    ): ?array {
        $session = $this->sessions->getSessionById($sessionId);
        if ($session === null) {
            return null;
        }

        $pair = (new ResultPresenter($this->db, $this->sessions))->pairViewData($session, $module);
        if ($pair === null) {
            return null;
        }

        $partner = $this->sessions->getSessionById($pair['partner_session_id']);
        if ($partner === null) {
            return null;
        }

        /** @var array<string|int, mixed> $partnerAnswers */
        $partnerAnswers = is_array($partner['answers'] ?? null) ? $partner['answers'] : [];
        $data = $presenter->pair($module, $pair, $caseAnswers, $partnerAnswers);

        if (!$includeAnswers) {
            $data['questionnaires'] = [];
        }

        // Идентификатор второй сессии дальше этого метода не уходит.
        unset($data['partner_session_id']);

        return $data;
    }

    /**
     * Профессиональное заключение — последняя готовая версия.
     *
     * Правится оно не через редактор (PRODUCT_RULES §4), поэтому берётся
     * последняя ревизия отчёта; если её нет, остаётся текст задания.
     *
     * @return array{html: string}|null
     */
    private function professional(string $sessionId, TestModuleInterface $module): ?array
    {
        $report = $this->report($sessionId, $module, Prompt::KIND_PROFESSIONAL);
        if ($report === null) {
            return null;
        }

        $latest = (new AiReportRevisionService($this->db))->latest((string) $report['id']);
        $markdown = (string) ($latest['content'] ?? $report['content'] ?? '');

        return trim($markdown) === '' ? null : ['html' => ReportMarkdown::toHtml($markdown)];
    }

    /**
     * Понятный разбор — опубликованная версия, иначе последний черновик.
     *
     * Пометка обязательна: специалист должен видеть в документе, читал ли это
     * клиент. Неопубликованная правка помечается черновиком и остаётся
     * материалом специалиста — сам файл он отдаёт клиенту или нет вручную.
     *
     * @return array{html: string, published: bool, revision_no: int, date: string}|null
     */
    private function clear(string $sessionId, TestModuleInterface $module): ?array
    {
        $report = $this->report($sessionId, $module, Prompt::KIND_CLEAR);
        if ($report === null) {
            return null;
        }

        $revisions = new AiReportRevisionService($this->db);
        $published = $revisions->published((string) $report['id']);
        if ($published !== null) {
            return [
                'html' => ReportMarkdown::toHtml($published['content']),
                'published' => true,
                'revision_no' => $published['revision_no'],
                'date' => $this->date($published['published_at']),
            ];
        }

        $latest = $revisions->latest((string) $report['id']);
        $markdown = (string) ($latest['content'] ?? $report['content'] ?? '');
        if (trim($markdown) === '') {
            return null;
        }

        return [
            'html' => ReportMarkdown::toHtml($markdown),
            'published' => false,
            'revision_no' => (int) ($latest['revision_no'] ?? 1),
            'date' => $this->date($latest['created_at'] ?? null),
        ];
    }

    /**
     * Готовое задание нужного вида для этого кейса.
     *
     * @return array<string, mixed>|null
     */
    private function report(string $sessionId, TestModuleInterface $module, string $kind): ?array
    {
        $session = $this->sessions->getSessionById($sessionId);
        $mode = $session === null
            ? 'individual'
            : (new ResultPresenter($this->db, $this->sessions))->reportMode($session);

        $report = (new AiReportRepository($this->db))->findFor($sessionId, $mode, $kind);

        return $report !== null && (string) $report['status'] === AiReportRepository::STATUS_READY
            ? $report
            : null;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function date(mixed $value): string
    {
        $raw = is_string($value) ? strtotime($value) : false;

        return $raw === false ? '' : date('d.m.Y H:i', $raw);
    }
}
