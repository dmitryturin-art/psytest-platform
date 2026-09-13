<?php

declare(strict_types=1);

namespace PsyTest\Core;

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
