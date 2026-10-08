#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Пересоздать заготовку промпта из текущего универсального шаблона.
 *
 * Нужен, когда шаблон `prompts/_universal/*.md` изменился после того, как
 * заготовка для методики уже была создана (07.WP10b). Создаёт НОВУЮ версию
 * владельца с текстом шаблона; ничего не публикует и не удаляет. Если у ключа
 * уже есть опубликованная версия, скрипт отказывает: тогда правку делает
 * владелец на странице промпта.
 *
 * Использование: php bin/prompt-reseed-stub.php <test> <kind>   (kind: clear|professional)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PsyTest\Core\Ai\Prompt;
use PsyTest\Core\Ai\PromptRegistry;
use PsyTest\Core\Ai\PromptStubSeeder;
use PsyTest\Core\Database;
use PsyTest\Core\ModuleLoader;

$test = $argv[1] ?? '';
$kind = $argv[2] ?? '';
if ($test === '' || !in_array($kind, [Prompt::KIND_CLEAR, Prompt::KIND_PROFESSIONAL], true)) {
    fwrite(STDERR, "Использование: php bin/prompt-reseed-stub.php <test> <clear|professional>\n");
    exit(2);
}

$db = Database::getInstance();
$module = (new ModuleLoader(null, $db))->discover()->getModule($test);
if ($module === null) {
    fwrite(STDERR, "Методика «{$test}» не найдена.\n");
    exit(1);
}

$registry = PromptRegistry::default($db);
$mode = PromptStubSeeder::MODE;
if (!$registry->hasKey($test, $mode, $kind)) {
    fwrite(STDERR, "Ключа {$test} | {$mode} | {$kind} нет: сначала включите методику в кабинете.\n");
    exit(1);
}
if ($registry->published($test, $mode, $kind) !== null) {
    fwrite(STDERR, "У ключа уже есть опубликованная версия: правьте её на странице промпта.\n");
    exit(1);
}

$seeder = PromptStubSeeder::default($registry);
$text = $seeder->template($kind, (string) ($module->getMetadata()['name'] ?? $test));
$version = $registry->createOwnerVersion(
    $test,
    $mode,
    $kind,
    $text,
    'заготовка обновлена из универсального шаблона',
    $kind === Prompt::KIND_PROFESSIONAL,
);

echo "Создана версия {$version} для {$test} | {$mode} | {$kind} (черновик, не опубликована).\n";
