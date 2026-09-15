<?php

declare(strict_types=1);

namespace PsyTest\Core\Ai;

use PsyTest\Modules\TestModuleInterface;

/**
 * Синтетический контекст методики для предпросмотра промпта (07.WP9).
 *
 * Владелец должен видеть запрос ровно в том виде, в каком он уйдёт провайдеру,
 * но на странице промптов не может быть ни одной реальной сессии: это рабочий
 * инструмент, а не просмотр чужих результатов (PRODUCT_RULES §11).
 *
 * Поэтому ответы придумываются здесь — детерминированно, по объявленной модулем
 * схеме ответов, без обращения к БД. Дальше работает обычный путь: модуль сам
 * считает результат и сам решает, что из него отдать наружу в
 * `aiReportContext()`. Никакой параллельной «фикстурной» логики контекста нет,
 * иначе предпросмотр показывал бы не то, что уходит боевым запросом.
 */
final class PromptFixtureContext
{
    /**
     * @return array<string, mixed>
     *
     * @throws AiProviderException если методика не отдаёт данные в этом режиме
     */
    public static function build(TestModuleInterface $module, string $mode): array
    {
        // Тот же путь, что и в AiReportContextBuilder: для пары модуль сначала
        // сводит результаты двух участников, и уже это сведение проходит через
        // aiReportContext(). Иначе предпросмотр показывал бы другую нагрузку.
        $results = $mode === 'pair'
            ? $module->comparePairResults(
                $module->calculateResults(self::answers($module, 0)),
                $module->calculateResults(self::answers($module, 3)),
            )
            : $module->calculateResults(self::answers($module, 0));

        $context = $module->aiReportContext($results, $mode);

        if ($context === null) {
            throw new AiProviderException('Методика не отдаёт данные в этом режиме — предпросмотр невозможен.');
        }

        return $context;
    }

    /**
     * Детерминированные ответы по схеме модуля.
     *
     * @return array<string, int|string>
     */
    private static function answers(TestModuleInterface $module, int $shift): array
    {
        $schema = $module->getAnswerSchema();
        $questions = $module->getQuestions();
        $dual = $schema['key_template'] === 'dual';
        $answers = [];

        foreach (array_values($questions) as $index => $question) {
            $id = (string) $question['id'];
            $value = self::value($schema, $question, $index + $shift);

            if ($dual) {
                $answers[$id . '_self'] = $value;
                $answers[$id . '_partner'] = self::value($schema, $question, $index + $shift + 2);
                continue;
            }

            $answers[$id] = $value;
        }

        foreach ((array) $schema['extra_keys'] as $extra) {
            if ($extra === 'gender') {
                $answers['gender'] = 'female';
            }
            if ($extra === 'age' && $schema['requires_age']) {
                $answers['age'] = (string) ((int) $schema['age_range']['min'] + 16);
            }
        }

        return $answers;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $question
     */
    private static function value(array $schema, array $question, int $seed): int|string
    {
        $allowed = match ((string) ($schema['answer_type'] ?? 'options')) {
            'ternary' => ['0', '1', '2'],
            'scale10' => ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10'],
            default => array_map(
                static fn (array $option): string => (string) $option['value'],
                array_values((array) ($question['options'] ?? [])),
            ),
        };

        if ($allowed === []) {
            return 0;
        }

        return $allowed[$seed % count($allowed)];
    }
}
