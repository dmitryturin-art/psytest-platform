#!/usr/bin/env php
<?php

/**
 * Предпросмотр ИИ-разбора на синтетической фикстуре.
 *
 * PRODUCT_RULES §6: владелец правит промпт, проверяет его на обезличенных
 * fixtures и только потом публикует. Этот скрипт — та самая проверка: он
 * берёт синтетические ответы, считает их обычным модулем, отдаёт модели
 * ровно ту нагрузку, что уйдёт в бою, и сохраняет отчёт в файл.
 *
 * Реальные данные респондентов сюда не попадают и попадать не должны.
 *
 * Использование:
 *   php bin/ai-report-preview.php --test=lazarus --mode=individual --kind=clear
 *   php bin/ai-report-preview.php --test=lazarus --kind=professional --model=qwen/qwen3.7-plus
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PsyTest\Core\Ai\AiClient;
use PsyTest\Core\Ai\AiProviderSettings;
use PsyTest\Core\Ai\CurlTransport;
use PsyTest\Core\Ai\PromptRegistry;
use PsyTest\Core\ModuleLoader;

$options = getopt('', ['test:', 'mode::', 'kind::', 'model::', 'context::', 'out::']);

$testSlug = $options['test'] ?? null;
if (!is_string($testSlug)) {
    fwrite(STDERR, "Укажите методику: --test=lazarus\n");
    exit(1);
}

$mode = (string) ($options['mode'] ?? 'individual');
$kind = (string) ($options['kind'] ?? 'clear');
$ownerContext = isset($options['context']) ? (string) $options['context'] : null;

$root = dirname(__DIR__);
chdir($root);
$config = require $root . '/config.php';

$module = (new ModuleLoader(null, null))->discover()->getModule($testSlug);
if ($module === null) {
    fwrite(STDERR, "Методика «{$testSlug}» не найдена.\n");
    exit(1);
}

/**
 * Детерминированные синтетические ответы: один и тот же вход при каждом запуске,
 * иначе сравнивать промпты между собой бессмысленно.
 */
function syntheticAnswers(object $module, string $slug): array
{
    $questions = $module->getQuestions();
    $answers = [];

    if ($slug === 'lazarus') {
        $self = [8, 7, 4, 9, 6, 3, 8, 5, 7, 9, 4, 6, 8, 7, 5, 9];
        $partner = [7, 8, 8, 6, 7, 7, 6, 8, 5, 7, 8, 6, 7, 5, 8, 6];
        foreach ($questions as $i => $q) {
            $answers[$q['id'] . '_self'] = $self[$i % count($self)];
            $answers[$q['id'] . '_partner'] = $partner[$i % count($partner)];
        }

        return $answers;
    }

    $schema = $module->getAnswerSchema();
    foreach ($questions as $i => $q) {
        $values = array_column($q['options'] ?? [], 'value');
        $answers[$q['id']] = $values === [] ? ($i % 3) : $values[$i % count($values)];
    }
    if ($schema['requires_gender'] ?? false) {
        $answers['gender'] = 'female';
    }

    return $answers;
}

$answers = syntheticAnswers($module, $testSlug);
$results = $module->calculateResults($answers);

if ($mode === 'pair') {
    $second = $module->calculateResults(array_map(
        static fn ($v) => is_int($v) ? max(1, min(10, $v - 2)) : $v,
        $answers,
    ));
    $results = $module->comparePairResults($results, $second);
}

$context = $module->aiReportContext($results, $mode);
if ($context === null) {
    fwrite(STDERR, "Методика «{$testSlug}» ещё не объявила, что отдаёт ИИ в режиме «{$mode}».\n");
    exit(1);
}

$prompt = PromptRegistry::default()->forReview($testSlug, $mode, $kind);
if ($prompt === null) {
    fwrite(STDERR, "В реестре нет промпта «{$testSlug} | {$mode} | {$kind}».\n");
    exit(1);
}
if (!$prompt->isPublished()) {
    fwrite(STDERR, "Промпт «{$prompt->key()}» ещё черновик — предпросмотр возможен, но в бою он не используется.\n");
}

if (isset($options['model'])) {
    putenv('AI_MODEL=' . $options['model']);
}
$settings = AiProviderSettings::fromConfig($config);

$payload = json_encode($context, JSON_UNESCAPED_UNICODE);
printf("методика:  %s | %s | %s (промпт v%d, %s)\n", $testSlug, $mode, $kind, $prompt->version, $prompt->status);
printf("модель:    %s\n", $settings->model);
printf("нагрузка:  %d байт\n", strlen((string) $payload));
if ($ownerContext !== null) {
    printf("контекст специалиста: %d символов\n", mb_strlen($ownerContext));
}
echo str_repeat('-', 70), PHP_EOL;

$startedAt = microtime(true);
$completion = (new AiClient($settings, new CurlTransport()))->complete($prompt, $context, $ownerContext);
$elapsed = round(microtime(true) - $startedAt, 1);

$outDir = $options['out'] ?? ($root . '/tmp/ai-reports');
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

$modelSlug = str_replace(['/', ':'], '-', $settings->model);
$file = sprintf('%s/%s-%s-%s--%s.md', $outDir, $testSlug, $mode, $kind, $modelSlug);

$header = sprintf(
    "<!-- предпросмотр на синтетических данных; реальных ответов здесь нет\nпромпт: %s v%d\nзапрошена модель: %s\nответила модель: %s\nтокенов: вход %d, выход %d\nвремя: %s c\n-->\n\n",
    $prompt->key(),
    $prompt->version,
    $completion->requestedModel,
    $completion->servedModel,
    $completion->promptTokens,
    $completion->completionTokens,
    $elapsed,
);

file_put_contents($file, $header . $completion->text);

printf("ответила:  %s\n", $completion->servedModel);
printf("токенов:   вход %d, выход %d, время %s c\n", $completion->promptTokens, $completion->completionTokens, $elapsed);
printf("отчёт:     %s (%d символов)\n", $file, mb_strlen($completion->text));
