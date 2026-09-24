#!/usr/bin/env php
<?php

/**
 * Обновление дополнительных шкал СМИЛ в сохранённых результатах (05.S4a).
 *
 * Реестр дополнительных шкал вырос после прохождения части кейсов (35 → 105).
 * Скрипт проходит завершённые сессии СМИЛ и досчитывает по сохранённым ответам
 * только блок `additional_scores`; базовый профиль, достоверность, индексы и
 * интерпретация не пересчитываются. То же самое делается и при чтении
 * результата, скрипт лишь обновляет архив заранее.
 *
 * Использование:
 *   php bin/smil-refresh-additional.php [--dry-run]
 *
 * Выводятся только счётчики — без идентификаторов, токенов и данных сессий.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PsyTest\Core\Database;
use PsyTest\Core\ModuleLoader;
use PsyTest\Core\ResultRefresher;

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);
$unknown = array_diff(array_slice($argv, 1), ['--dry-run']);
if ($unknown !== []) {
    fwrite(STDERR, "Использование: php bin/smil-refresh-additional.php [--dry-run]\n");
    exit(2);
}

try {
    $module = (new ModuleLoader())->discover()->getModule('smil');
    if ($module === null) {
        fwrite(STDERR, "Модуль smil не найден.\n");
        exit(1);
    }

    $counts = (new ResultRefresher(Database::getInstance()))->refreshAll('smil', $module, $dryRun);
} catch (\Throwable $e) {
    // Сообщение исключения может содержать параметры подключения — только класс.
    fwrite(STDERR, 'Ошибка: ' . $e::class . "\n");
    exit(1);
}

echo $dryRun ? "Пробный прогон (без записи в БД)\n" : "Обновление выполнено\n";
echo ($dryRun ? 'Будет обновлено: ' : 'Обновлено: ') . $counts['updated'] . "\n";
echo 'Уже актуально: ' . $counts['current'] . "\n";
echo 'Без ответов: ' . $counts['no_answers'] . "\n";
echo 'Ошибка: ' . $counts['failed'] . "\n";

exit($counts['failed'] > 0 ? 1 : 0);
