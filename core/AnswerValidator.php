<?php

declare(strict_types=1);

namespace PsyTest\Core;

use PsyTest\Modules\TestModuleInterface;

/**
 * Validates a submitted answer set against the module's declarative schema
 * (Module API v2). The validator itself has no per-module knowledge — every
 * rule comes from TestModuleInterface::getAnswerSchema() and getQuestions().
 */
final class AnswerValidator
{
    /** Значения, которыми дополняется схема модуля, если он их не объявил. */
    private const SCHEMA_DEFAULTS = [
        'requires_age' => false,
        'age_range' => ['min' => 13, 'max' => 100],
    ];

    /**
     * @param array<int|string, mixed> $answers
     * @return list<string>
     */
    public static function validate(TestModuleInterface $module, array $answers, bool $complete): array
    {
        // Модуль может вернуть схему без новых ключей — так делает любой модуль,
        // написанный до их появления. Общий слой дополняет её значениями по
        // умолчанию, а не падает на отсутствующем индексе.
        $schema = $module->getAnswerSchema() + self::SCHEMA_DEFAULTS;
        $questions = $module->getQuestions();
        $errors = [];

        [, $answerKeys] = self::keySets($schema, $questions);
        $allowedByKey = self::allowedValues($schema, $questions);
        $isDual = $schema['key_template'] === 'dual';
        $multi = $isDual ? [] : self::multiQuestions($questions);

        foreach ($answers as $key => $value) {
            if (in_array((string) $key, $schema['extra_keys'], true)) {
                continue;
            }
            if (!isset($answerKeys[(string) $key])) {
                $errors[] = 'invalid_answer';
                break;
            }
            if (isset($multi[(string) $key])) {
                // Несколько вариантов: список значений, а не скаляр.
                $error = self::multiError($multi[(string) $key], $value, $complete);
                if ($error === 'invalid_answer') {
                    $errors[] = $error;
                    break;
                }
                if ($error !== null) {
                    $errors[] = $error;
                }
                continue;
            }
            if (!is_scalar($value)) {
                // Массив на обычный вопрос — отказ, а не приведение к строке.
                $errors[] = 'invalid_answer';
                break;
            }
            $baseKey = $isDual ? preg_replace('/_(self|partner)$/', '', (string) $key) : (string) $key;
            if (!in_array((string) $value, $allowedByKey[$baseKey] ?? [], true)) {
                $errors[] = 'invalid_answer';
                break;
            }
        }

        if ($complete && count(array_intersect_key($answers, $answerKeys)) !== count($answerKeys)) {
            $errors[] = 'incomplete_answers';
        }

        if ($schema['requires_gender'] && !in_array($answers['gender'] ?? null, ['male', 'female'], true)) {
            $errors[] = 'invalid_gender';
        }

        if ($schema['requires_age'] && !self::isAgeInRange($answers['age'] ?? null, $schema)) {
            $errors[] = 'invalid_age';
        }

        return array_values(array_unique($errors));
    }

    /**
     * Убирает из набора ответов служебные ключи схемы (пол, возраст).
     *
     * Валидатор значений для них не проверяет, поэтому из промежуточного
     * сохранения они не принимаются вовсе: анкета идёт отдельным полем
     * `demographics` и проходит собственную очистку.
     *
     * @param array<int|string, mixed> $answers
     * @return array<int|string, mixed>
     */
    public static function withoutExtraKeys(TestModuleInterface $module, array $answers): array
    {
        $extra = $module->getAnswerSchema()['extra_keys'];

        return array_diff_key($answers, array_flip($extra));
    }

    /**
     * Проверка промежуточного сохранения (07.K12): только ключи и значения ответов.
     *
     * Пол и возраст приходят отдельным полем `demographics`, а не внутри набора
     * ответов, поэтому требование «пол обязателен» к частичному сохранению не
     * относится: оно проверяется при завершении. Без этого автосохранение
     * методик с обязательным полом отвечало 422 на каждый ответ.
     *
     * @param array<int|string, mixed> $answers
     * @return list<string>
     */
    public static function validatePartial(TestModuleInterface $module, array $answers): array
    {
        return array_values(array_diff(self::validate($module, $answers, false), ['invalid_gender', 'invalid_age']));
    }

    /**
     * Возраст приходит из формы строкой и до сих пор не проверялся вовсе:
     * он числился «лишним ключом» и пропускался. Для методик, где возраст
     * задан обязательным, это значило, что на сервер можно прислать что угодно.
     *
     * @param array<string, mixed> $schema
     */
    private static function isAgeInRange(mixed $age, array $schema): bool
    {
        if (!is_int($age) && !(is_string($age) && preg_match('/^\d{1,3}$/', $age) === 1)) {
            return false;
        }

        $range = $schema['age_range'];

        return (int) $age >= $range['min'] && (int) $age <= $range['max'];
    }

    /**
     * @param list<array<string, mixed>> $questions
     *
     * @return array<string, array<string, mixed>> id вопроса => вопрос типа multi
     */
    private static function multiQuestions(array $questions): array
    {
        $multi = [];
        foreach ($questions as $question) {
            if (AnswerNormalizer::isMulti($question)) {
                $multi[(string) $question['id']] = $question;
            }
        }

        return $multi;
    }

    /**
     * Проверка ответа на вопрос «несколько вариантов».
     *
     * Допустим только плоский список значений из вариантов вопроса. Повторы
     * не считаются ошибкой (хранимый вид без повторов даёт AnswerNormalizer),
     * но исключающий вариант («ничего из перечисленного») не сочетается с
     * другими. Пустой список допустим только в промежуточном сохранении: там
     * он означает «выбор снят».
     *
     * @param array<string, mixed> $question
     */
    private static function multiError(array $question, mixed $value, bool $complete): ?string
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > AnswerNormalizer::MAX_MULTI_ITEMS) {
            return 'invalid_answer';
        }

        $exclusiveByValue = [];
        foreach ((array) ($question['options'] ?? []) as $option) {
            $exclusiveByValue[(string) $option['value']] = ($option['exclusive'] ?? false) === true;
        }

        $chosen = [];
        foreach ($value as $item) {
            if ((!is_int($item) && !is_string($item)) || !isset($exclusiveByValue[(string) $item])) {
                return 'invalid_answer';
            }
            $chosen[(string) $item] = true;
        }

        if (count($chosen) > 1) {
            foreach (array_keys($chosen) as $item) {
                if ($exclusiveByValue[(string) $item]) {
                    return 'invalid_answer';
                }
            }
        }

        return $chosen === [] && $complete ? 'incomplete_answers' : null;
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<array<string, mixed>> $questions
     *
     * @return array{0: array<string, bool>, 1: array<string, bool>}
     */
    private static function keySets(array $schema, array $questions): array
    {
        $questionKeys = [];
        $answerKeys = [];
        foreach ($questions as $q) {
            $id = (string) $q['id'];
            $questionKeys[$id] = true;
            if ($schema['key_template'] === 'dual') {
                $answerKeys[$id . '_self'] = true;
                $answerKeys[$id . '_partner'] = true;
            } else {
                $answerKeys[$id] = true;
            }
        }

        return [$questionKeys, $answerKeys];
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<array<string, mixed>> $questions
     *
     * @return array<string, list<string>> Question id (as string) => allowed answer values (as strings).
     */
    private static function allowedValues(array $schema, array $questions): array
    {
        $type = $schema['answer_type'];
        $uniform = null;
        if ($type === 'ternary') {
            $uniform = ['0', '1', '2'];
        } elseif ($type === 'scale10') {
            $uniform = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10'];
        }

        $map = [];
        foreach ($questions as $q) {
            $id = (string) $q['id'];
            if ($uniform !== null) {
                $map[$id] = $uniform;
                continue;
            }
            $values = [];
            foreach ($q['options'] ?? [] as $option) {
                $values[] = (string) $option['value'];
            }
            $map[$id] = $values;
        }

        return $map;
    }
}
