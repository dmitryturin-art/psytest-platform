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
    /**
     * @param array<int|string, mixed> $answers
     * @return list<string>
     */
    public static function validate(TestModuleInterface $module, array $answers, bool $complete): array
    {
        $schema = $module->getAnswerSchema();
        $questions = $module->getQuestions();
        $errors = [];

        [, $answerKeys] = self::keySets($schema, $questions);
        $allowedByKey = self::allowedValues($schema, $questions);
        $isDual = $schema['key_template'] === 'dual';

        foreach ($answers as $key => $value) {
            if (in_array((string) $key, $schema['extra_keys'], true)) {
                continue;
            }
            if (!isset($answerKeys[(string) $key])) {
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

        return array_values(array_unique($errors));
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
