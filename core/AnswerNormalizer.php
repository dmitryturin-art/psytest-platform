<?php

declare(strict_types=1);

namespace PsyTest\Core;

use PsyTest\Modules\TestModuleInterface;

/**
 * Приводит присланные ответы к виду, в котором они хранятся.
 *
 * Обычный вопрос — скаляр, как и раньше: числовая строка становится целым,
 * остальное — признаком «да/нет». Вопрос типа `multi` (несколько вариантов) —
 * список значений выбранных вариантов без повторов в порядке вариантов вопроса.
 *
 * Нормализатор ничего не отклоняет: всё, что не похоже на допустимый ответ
 * (массив на обычный вопрос, скаляр или вложенный массив на multi-вопрос,
 * слишком длинный список), он оставляет как есть, а отказ даёт AnswerValidator.
 */
final class AnswerNormalizer
{
    /** Верхняя граница длины присланного списка: дальше он не разбирается вовсе. */
    public const MAX_MULTI_ITEMS = 64;

    /**
     * Ответы из HTML-формы (`$_POST['answers']`): значения приходят строками.
     *
     * @return array<int|string, mixed>
     */
    public static function fromForm(TestModuleInterface $module, mixed $answers): array
    {
        if (!is_array($answers)) {
            return [];
        }

        $multi = self::multiQuestions($module);
        $normalized = [];
        foreach ($answers as $questionId => $answer) {
            if (isset($multi[(string) $questionId])) {
                $normalized[$questionId] = self::multiValue($multi[(string) $questionId], $answer);
            } elseif (is_array($answer)) {
                $normalized[$questionId] = $answer;
            } elseif (is_numeric($answer)) {
                $normalized[$questionId] = (int) $answer;
            } else {
                $normalized[$questionId] = $answer === 'true' || $answer === true;
            }
        }

        return $normalized;
    }

    /**
     * Ответы промежуточного сохранения (JSON): скаляры не трогаются,
     * списки multi-вопросов приводятся к хранимому виду.
     *
     * @param array<int|string, mixed> $answers
     * @return array<int|string, mixed>
     */
    public static function fromJson(TestModuleInterface $module, array $answers): array
    {
        $multi = self::multiQuestions($module);
        if ($multi === []) {
            return $answers;
        }

        foreach ($answers as $questionId => $answer) {
            if (isset($multi[(string) $questionId])) {
                $answers[$questionId] = self::multiValue($multi[(string) $questionId], $answer);
            }
        }

        return $answers;
    }

    /** @param array<string, mixed> $question */
    public static function isMulti(array $question): bool
    {
        return ($question['type'] ?? null) === 'multi';
    }

    /**
     * @return array<string, array<string, mixed>> id вопроса => вопрос типа multi
     */
    private static function multiQuestions(TestModuleInterface $module): array
    {
        if ($module->getAnswerSchema()['key_template'] !== 'plain') {
            return [];
        }

        $multi = [];
        foreach ($module->getQuestions() as $question) {
            if (self::isMulti($question)) {
                $multi[(string) $question['id']] = $question;
            }
        }

        return $multi;
    }

    /**
     * @param array<string, mixed> $question
     */
    private static function multiValue(array $question, mixed $answer): mixed
    {
        if (!is_array($answer) || !array_is_list($answer) || count($answer) > self::MAX_MULTI_ITEMS) {
            return $answer;
        }

        $chosen = [];
        foreach ($answer as $item) {
            if (!is_int($item) && !is_string($item)) {
                return $answer;
            }
            $chosen[(string) $item] = $item;
        }

        $values = [];
        foreach ((array) ($question['options'] ?? []) as $option) {
            $key = (string) $option['value'];
            if (array_key_exists($key, $chosen)) {
                $values[] = $option['value'];
                unset($chosen[$key]);
            }
        }

        // Неизвестные значения остаются в хвосте: их отклонит валидатор.
        return array_merge($values, array_values($chosen));
    }
}
