#!/usr/bin/env php
<?php

/**
 * Фоновая подготовка ИИ-разборов.
 *
 * Отчёт делается около двух минут, поэтому веб-запрос его не ждёт: страница
 * лишь ставит задание, а работу выполняет этот скрипт по расписанию — рядом
 * с уже настроенной ночной очисткой сессий.
 *
 *   php bin/generate-ai-reports.php            — обработать до трёх заданий
 *   php bin/generate-ai-reports.php --limit=1  — ровно одно
 *   php bin/generate-ai-reports.php --once     — то же, что --limit=1
 *
 * Скрипт не запускает несколько экземпляров одной работы: задание берётся
 * условным UPDATE, и второй запуск просто не увидит занятого.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PsyTest\Core\Ai\AiClient;
use PsyTest\Core\Ai\AiProviderSettings;
use PsyTest\Core\Ai\AiReportGenerator;
use PsyTest\Core\Ai\AiReportRepository;
use PsyTest\Core\Ai\CurlTransport;
use PsyTest\Core\Ai\PromptRegistry;
use PsyTest\Core\Database;
use PsyTest\Core\ModuleLoader;
use PsyTest\Core\SessionManager;

$root = dirname(__DIR__);
chdir($root);
$config = require $root . '/config.php';

$options = getopt('', ['limit::', 'once', 'request::', 'mode::', 'kind::', 'context::']);
$limit = isset($options['once']) ? 1 : max(1, (int) ($options['limit'] ?? 3));

$log = static function (string $message): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
};

$settings = AiProviderSettings::fromConfig($config);
if (!$settings->isConfigured()) {
    $log('Провайдер ИИ не настроен: нет ключа или адреса. Задания не берутся.');
    exit(0);
}

$db = Database::getInstance();
$reports = new AiReportRepository($db);

$released = $reports->releaseStuck();
if ($released !== []) {
    $log(sprintf('Возвращено в очередь зависших заданий: %d', count($released)));
}

$generator = new AiReportGenerator(
    $reports,
    new SessionManager($db),
    (new ModuleLoader(null, $db))->discover(),
    PromptRegistry::default(),
    new AiClient($settings, new CurlTransport()),
);

// Поставить задание по ссылке результата. Нужно, пока на странице нет кнопки:
// иначе разбор нечем запустить, кроме как править базу руками.
if (isset($options['request'])) {
    $token = (string) $options['request'];
    $mode = (string) ($options['mode'] ?? 'individual');
    $kind = (string) ($options['kind'] ?? 'clear');

    $sessions = new SessionManager($db);
    $session = $sessions->getSessionByResultToken($token);
    if ($session === null) {
        $log('Результат по такому токену не найден.');
        exit(1);
    }

    $test = $db->selectOne('SELECT slug FROM tests WHERE id = ?', [$session['test_id']]);
    $slug = (string) ($test['slug'] ?? '');

    $prompt = PromptRegistry::default()->published($slug, $mode, $kind);
    if ($prompt === null) {
        $log("Промпт «{$slug} | {$mode} | {$kind}» не опубликован — задание не ставится.");
        exit(1);
    }

    $job = $reports->request(
        (string) $session['id'],
        $slug,
        $mode,
        $kind,
        $prompt,
        isset($options['context']) ? (string) $options['context'] : null,
    );

    $log(sprintf('Задание %s: %s, статус %s', $job['id'], $prompt->key(), $job['status']));
}

$processed = 0;

for ($i = 0; $i < $limit; $i++) {
    $job = $reports->claimNext();
    if ($job === null) {
        break;
    }

    $log(sprintf('Разбор %s: %s | %s | %s, попытка %d', $job['id'], $job['test_slug'], $job['mode'], $job['report_kind'], $job['attempts']));

    $startedAt = microtime(true);
    $generator->process($job);
    $finished = $reports->find((string) $job['id']);
    $elapsed = round(microtime(true) - $startedAt, 1);

    $processed++;

    if (($finished['status'] ?? '') === AiReportRepository::STATUS_READY) {
        $log(sprintf(
            '  готов за %s c: %s, символов %d, токенов вход %d / выход %d',
            $elapsed,
            $finished['served_model'] ?: '—',
            mb_strlen((string) $finished['content']),
            (int) $finished['prompt_tokens'],
            (int) $finished['completion_tokens'],
        ));
    } else {
        $log(sprintf('  не получилось за %s c: %s', $elapsed, $finished['failure_reason'] ?? 'причина не записана'));
    }
}

$log($processed === 0 ? 'Заданий в очереди нет.' : sprintf('Обработано заданий: %d', $processed));
