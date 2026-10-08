<?php

declare(strict_types=1);

namespace PsyTest\Core;

use PsyTest\Modules\ResultSection;
use PsyTest\Modules\TestModuleInterface;

/**
 * Turns a completed invited case into owner-readable questionnaire rows.
 *
 * Stored answers stay untouched: this is a view-only mapping back to the
 * currently supported module's question and option texts.
 */
final class InvitedCasePresenter
{
    /**
     * Подписи партнёров пары.
     *
     * Словарь тот же, что в блоках сравнения: «начавший» получил ссылку
     * первым и отправил приглашение, «приглашённый» прошёл по этой ссылке.
     * Порядок канонический — он же в `pair_comparisons` и во внешнем разборе.
     *
     * @var array<int, string>
     */
    private const POSITION_LABELS = [
        1 => 'Партнёр 1 — начавший опросник',
        2 => 'Партнёр 2 — приглашённый участник',
    ];

    /**
     * Owner cards reuse a module's basic result components, but must never
     * expose a client-side bearer-link action such as Lazarus pair invitation.
     *
     * @param array<string, mixed> $results
     * @return list<ResultSection>
     */
    public function resultSections(TestModuleInterface $module, array $results): array
    {
        // Признак просмотра специалистом: модуль может добавить в карточку
        // кейса то, что респонденту не показывается (IPIP — таблица сырых
        // баллов). Тот же приём, что `is_pdf` для печати.
        return array_values(array_filter(
            $module->buildSections($results + ['is_specialist_view' => true]),
            static fn (ResultSection $section): bool => $section->type !== ResultSection::TYPE_PAIR_INVITE,
        ));
    }

    /**
     * Парное прохождение в карточке кейса (07.K1b).
     *
     * Карточка кейса, входящего в пару, обязана читаться как пара: тот же
     * парный результат, что видит клиент, и обе анкеты — иначе специалист
     * видит половину материала и принимает его за индивидуальный кейс.
     *
     * Ничего не считается заново: парные секции приходят готовыми из
     * `ResultPresenter::pairViewData()`, а анкеты — через тот же `answers()`.
     * Вторая сессия остаётся чужой: сюда попадают только её ответы, без
     * идентификаторов, токена и любых действий над ней.
     *
     * @param array{position: int, partner_position: int, sections: list<ResultSection>} $pair
     * @param array<string|int, mixed> $caseAnswers Ответы партнёра этого кейса.
     * @param array<string|int, mixed> $partnerAnswers Ответы второго партнёра.
     * @return array<string, mixed>
     */
    public function pair(
        TestModuleInterface $module,
        array $pair,
        array $caseAnswers,
        array $partnerAnswers,
    ): array {
        $own = [
            'position' => $pair['position'],
            'label' => self::POSITION_LABELS[$pair['position']],
            'is_case' => true,
            'rows' => $this->answers($module, $caseAnswers),
        ];
        $other = [
            'position' => $pair['partner_position'],
            'label' => self::POSITION_LABELS[$pair['partner_position']],
            'is_case' => false,
            'rows' => $this->answers($module, $partnerAnswers),
        ];

        return [
            'position' => $pair['position'],
            'partner_position' => $pair['partner_position'],
            'label' => self::POSITION_LABELS[$pair['position']],
            'partner_label' => self::POSITION_LABELS[$pair['partner_position']],
            'sections' => $pair['sections'],
            // Канонический порядок: сначала начавший опросник, затем приглашённый.
            'questionnaires' => $pair['position'] === 1 ? [$own, $other] : [$other, $own],
        ];
    }

    /** Клинические шкалы профиля в порядке бланка: 1–9, 0. */
    private const PROFILE_CLINICAL_CODES = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'];

    /** Норма на бланке профиля — та же, что в легенде графика: 30–70T. */
    private const PROFILE_NORM_MIN = 30;
    private const PROFILE_NORM_MAX = 70;

    /**
     * Раскладка результата в карточке кейса (04.D3): что видно сразу, а что
     * свёрнуто, и краткая сводка для результата с профилем.
     *
     * Ничего не считается заново: сводка только выбирает уже посчитанные
     * модулем значения из данных секций — контрольные шкалы, T-баллы и
     * уровни из таблицы основных шкал, тип и код профиля из интерпретации.
     * Граница «вне нормы» — та же, что в легенде канонического графика.
     *
     * - Результат с графиком профиля: сразу виден график и сводка, всё
     *   остальное — в раскрывающихся блоках; блок контрольных шкал открыт,
     *   если протокол недостоверен.
     * - Простой результат (уровень по шкале): виден целиком, свёрнуты только
     *   таблицы по пунктам.
     *
     * @param list<ResultSection> $sections
     * @return array{
     *     summary: list<ResultSection>,
     *     details: list<array{section: ResultSection, key: string, open: bool, remember: bool}>,
     *     profile: array<string, mixed>|null
     * }
     */
    public function workspace(array $sections): array
    {
        $hasProfile = false;
        foreach ($sections as $section) {
            if ($section->type === ResultSection::TYPE_PROFILE_CHART) {
                $hasProfile = true;
            }
        }

        $summary = [];
        $details = [];
        foreach ($sections as $index => $section) {
            $folded = $hasProfile
                ? $section->type !== ResultSection::TYPE_PROFILE_CHART
                : $section->type === ResultSection::TYPE_SCALES_TABLE;
            if (!$folded) {
                $summary[] = $section;
                continue;
            }

            $invalid = $section->type === ResultSection::TYPE_VALIDITY && empty($section->data['is_valid']);
            $details[] = [
                'section' => $section,
                'key' => $section->type . '-' . $index,
                // Недостоверный протокол не прячется и не запоминается свёрнутым.
                'open' => $invalid,
                'remember' => !$invalid,
            ];
        }

        return [
            'summary' => $summary,
            'details' => $details,
            'profile' => $hasProfile ? $this->profileSummary($sections) : null,
        ];
    }

    /**
     * @param list<ResultSection> $sections
     * @return array<string, mixed>
     */
    private function profileSummary(array $sections): array
    {
        $validity = null;
        $scales = [];
        $profileType = null;
        $codeType = null;
        foreach ($sections as $section) {
            if ($section->type === ResultSection::TYPE_VALIDITY && $validity === null) {
                $validity = $section->data;
            }
            if ($section->type === ResultSection::TYPE_SCALES_TABLE && $scales === [] && is_array($section->data['scales'] ?? null)) {
                $scales = $section->data['scales'];
            }
            if ($section->type === ResultSection::TYPE_INTERPRETATION) {
                $profileType = is_string($section->data['profile_type_name'] ?? null) ? $section->data['profile_type_name'] : null;
                $codeType = is_string($section->data['code_type'] ?? null) && $section->data['code_type'] !== '' ? $section->data['code_type'] : null;
            }
        }

        $outside = [];
        foreach ($scales as $scale) {
            if (!is_array($scale) || !in_array((string) ($scale['code'] ?? ''), self::PROFILE_CLINICAL_CODES, true)) {
                continue;
            }
            $t = (int) ($scale['t_score'] ?? 0);
            if ($t > self::PROFILE_NORM_MAX || $t < self::PROFILE_NORM_MIN) {
                $outside[] = [
                    'code' => (string) $scale['code'],
                    'name' => (string) ($scale['name'] ?? ''),
                    't_score' => $t,
                    'level_name' => (string) ($scale['level_name'] ?? ''),
                    'above' => $t > self::PROFILE_NORM_MAX,
                ];
            }
        }
        // Сначала выше нормы по убыванию T, затем ниже нормы по возрастанию.
        usort($outside, static function (array $a, array $b): int {
            if ($a['above'] !== $b['above']) {
                return $a['above'] ? -1 : 1;
            }

            return $a['above'] ? $b['t_score'] <=> $a['t_score'] : $a['t_score'] <=> $b['t_score'];
        });

        return [
            'validity' => $validity === null ? null : [
                'is_valid' => !empty($validity['is_valid']),
                'L' => $validity['L_score'] ?? null,
                'F' => $validity['F_score'] ?? null,
                'K' => $validity['K_score'] ?? null,
                'warnings' => is_array($validity['warnings'] ?? null) ? array_values($validity['warnings']) : [],
            ],
            'outside' => $outside,
            'norm_min' => self::PROFILE_NORM_MIN,
            'norm_max' => self::PROFILE_NORM_MAX,
            'profile_type' => $profileType,
            'code_type' => $codeType,
        ];
    }

    /**
     * @param array<string|int, mixed> $answers
     * @return list<array<string, string|int|null>>
     */
    public function answers(TestModuleInterface $module, array $answers): array
    {
        $rows = [];
        foreach ($module->getQuestions() as $position => $question) {
            $id = (string) ($question['id'] ?? $position + 1);
            $row = [
                'number' => $position + 1,
                'question' => $this->questionText($question, $answers),
            ];

            if (($question['dual'] ?? false) === true) {
                $row['self_answer'] = $this->rating($this->answer($answers, $id . '_self'));
                $row['partner_answer'] = $this->rating($this->answer($answers, $id . '_partner'));
            } else {
                $answer = $this->answer($answers, $id);
                $option = $this->option($question, $answer);
                $row['answer'] = $option['text'] ?? $this->ternaryAnswer($answer);
                $row['score'] = isset($option['value']) ? (string) $option['value'] : null;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $question
     * @param array<string|int, mixed> $answers
     */
    private function questionText(array $question, array $answers): string
    {
        if (is_string($question['text'] ?? null) && $question['text'] !== '') {
            return $question['text'];
        }

        $gender = $this->answer($answers, 'gender');
        if ($gender === 'female' && is_string($question['text_female'] ?? null)) {
            return $question['text_female'];
        }
        if ($gender === 'male' && is_string($question['text_male'] ?? null)) {
            return $question['text_male'];
        }

        return is_string($question['text_male'] ?? null)
            ? $question['text_male']
            : (is_string($question['text_female'] ?? null) ? $question['text_female'] : 'Вопрос недоступен');
    }

    /** @param array<string|int, mixed> $answers */
    private function answer(array $answers, string $key): mixed
    {
        return $answers[$key] ?? $answers[(int) $key] ?? null;
    }

    /**
     * @param array<string, mixed> $question
     * @return array<string, mixed>|null
     */
    private function option(array $question, mixed $answer): ?array
    {
        if (!is_array($question['options'] ?? null)) {
            return null;
        }

        foreach ($question['options'] as $option) {
            if (is_array($option) && (string) ($option['value'] ?? '') === (string) $answer) {
                return $option;
            }
        }

        return null;
    }

    private function ternaryAnswer(mixed $answer): string
    {
        return match ((string) $answer) {
            '1', 'true' => 'Верно',
            '0', 'false' => 'Неверно',
            '2' => 'Не знаю',
            default => 'Нет ответа',
        };
    }

    private function rating(mixed $answer): string
    {
        return is_numeric($answer) ? (string) $answer . ' из 10' : 'Нет ответа';
    }
}
