#!/usr/bin/env php
<?php

/**
 * Генератор строки OWNER_DASHBOARD_PASSWORD_HASH для .env кабинета владельца.
 *
 * Кабинет включается только Argon2id-хэшем в серверном .env; сам пароль нигде
 * не хранится — ни в Git, ни в базе, ни в логах. Этот скрипт печатает готовую
 * строку, которую остаётся вставить в .env вместо старой.
 *
 * Использование:
 *   php bin/owner-password.php              — спросит пароль, ввод не отображается
 *   echo 'пароль' | php bin/owner-password.php --stdin
 */

declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function readHidden(string $prompt): string
{
    fwrite(STDERR, $prompt);

    // Без stty (не-TTY окружение) пароль пришлось бы читать с эхом — это
    // означало бы показать его на экране и оставить в скроллбэке.
    $stty = @shell_exec('stty -g 2>/dev/null');
    if (!is_string($stty) || trim($stty) === '') {
        fail(PHP_EOL . 'Терминал не поддерживает скрытый ввод. Используйте: echo \'пароль\' | php bin/owner-password.php --stdin');
    }

    shell_exec('stty -echo');
    $value = fgets(STDIN);
    shell_exec('stty ' . escapeshellarg(trim($stty)));
    fwrite(STDERR, PHP_EOL);

    return is_string($value) ? $value : '';
}

$useStdin = in_array('--stdin', $argv, true);

$password = $useStdin ? (string) fgets(STDIN) : readHidden('Новый пароль кабинета: ');
$password = rtrim($password, "\r\n");

if ($password === '') {
    fail('Пустой пароль не принимается.');
}

if (!$useStdin) {
    $repeat = rtrim(readHidden('Повторите пароль: '), "\r\n");
    if (!hash_equals($password, $repeat)) {
        fail('Пароли не совпадают — ничего не сгенерировано.');
    }
}

$hash = password_hash($password, PASSWORD_ARGON2ID);

// Хэш обязан пройти те же две проверки, что делает OwnerDashboardAuthenticator:
// распознаваться как argon2id и подтверждать исходный пароль.
$info = password_get_info($hash);
if (($info['algoName'] ?? null) !== 'argon2id') {
    fail('Эта сборка PHP не даёт Argon2id — кабинет такой хэш не примет.');
}

if (!password_verify($password, $hash)) {
    fail('Хэш не подтверждает пароль — строка не выводится.');
}

if (mb_strlen($password) < 8) {
    fwrite(STDERR, 'Предупреждение: пароль короче 8 символов. Страница входа публична, ограничение — 10 попыток за 15 минут.' . PHP_EOL);
}

echo 'OWNER_DASHBOARD_PASSWORD_HASH=' . $hash . PHP_EOL;
