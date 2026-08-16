<?php

declare(strict_types=1);

namespace PsyTest\Core;

use PsyTest\Modules\TestModuleInterface;

final class AnswerValidator
{
    /**
     * @param array<int|string, mixed> $answers
     * @return list<string>
     */
    public static function validate(TestModuleInterface $module, array $answers, bool $complete): array
    {
        $metadata = $module->getMetadata();
        $questions = $module->getQuestions();
        $type = $metadata['answer_type'] ?? 'options';
        $errors = [];

        if ($type === 'ternary') {
            $ids = array_flip(array_map(static fn (array $q): string => (string) $q['id'], $questions));
            foreach ($answers as $key => $value) {
                if ($key === 'gender') {
                    continue;
                }
                if (!isset($ids[(string) $key]) || !in_array((string) $value, ['0', '1', '2'], true)) {
                    $errors[] = 'invalid_answer';
                    break;
                }
            }
            if ($complete && count(array_intersect_key($answers, $ids)) !== count($ids)) {
                $errors[] = 'incomplete_answers';
            }
            if (($metadata['requires_demographics']['gender'] ?? false) && !in_array($answers['gender'] ?? null, ['male', 'female'], true)) {
                $errors[] = 'invalid_gender';
            }
            return array_values(array_unique($errors));
        }

        if ($type === 'scale10') {
            $keys = [];
            foreach ($questions as $q) {
                $keys[(string) $q['id'] . '_self'] = true;
                $keys[(string) $q['id'] . '_partner'] = true;
            }
            foreach ($answers as $key => $value) {
                if ($key === 'gender') {
                    continue;
                }
                if (!isset($keys[(string) $key]) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1 || (int) $value > 10) {
                    $errors[] = 'invalid_answer';
                    break;
                }
            }
            if ($complete && count(array_intersect_key($answers, $keys)) !== count($keys)) {
                $errors[] = 'incomplete_answers';
            }
            return array_values(array_unique($errors));
        }

        $allowed = [];
        foreach ($questions as $q) {
            $allowed[(string) $q['id']] = array_map(static fn (array $o): string => (string) $o['value'], $q['options'] ?? []);
        }
        foreach ($answers as $key => $value) {
            if ($key === 'gender' || $key === 'age') {
                continue;
            }
            if (!isset($allowed[(string) $key]) || !in_array((string) $value, $allowed[(string) $key], true)) {
                $errors[] = 'invalid_answer';
                break;
            }
        }
        if ($complete && count(array_intersect_key($answers, $allowed)) !== count($allowed)) {
            $errors[] = 'incomplete_answers';
        }
        return array_values(array_unique($errors));
    }
}
