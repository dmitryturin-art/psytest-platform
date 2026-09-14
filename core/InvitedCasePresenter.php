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
        return array_values(array_filter(
            $module->buildSections($results),
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
