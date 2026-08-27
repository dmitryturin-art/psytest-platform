#!/usr/bin/env php
<?php

/**
 * Ссылка доступа к методике, закрытой приглашением.
 *
 * Методика с `visibility = invite` не показывается в каталоге, и её страница
 * отвечает «не найдено» без ключа. Этот скрипт показывает готовую ссылку,
 * умеет закрывать и открывать методику и перевыпускать ключ.
 *
 *   php bin/test-access-link.php                      — все закрытые методики
 *   php bin/test-access-link.php --test=smil           — ссылка на одну
 *   php bin/test-access-link.php --test=smil --rotate  — новый ключ, старые ссылки перестают работать
 *   php bin/test-access-link.php --test=smil --open    — вернуть в публичный каталог
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PsyTest\Core\Database;

$options = getopt('', ['test::', 'rotate', 'open', 'close']);
$root = dirname(__DIR__);
chdir($root);
$config = require $root . '/config.php';
$db = Database::getInstance();

$slug = isset($options['test']) ? (string) $options['test'] : null;
$appUrl = rtrim((string) $config->appUrl(), '/');

function showRow(array $test, string $appUrl): void
{
    printf("%-14s %s\n", $test['slug'], $test['name']);
    if (($test['visibility'] ?? 'public') !== 'invite') {
        echo "  видимость: публичная, показывается в каталоге\n\n";

        return;
    }

    if (($test['access_key'] ?? '') === '') {
        echo "  видимость: закрытая, но ключ не задан — методика недоступна никому\n";
        echo "  выпустить ключ: php bin/test-access-link.php --test={$test['slug']} --rotate\n\n";

        return;
    }

    echo "  видимость: закрытая, в каталоге не показывается\n";
    echo "  ссылка:    {$appUrl}/test/{$test['slug']}?key={$test['access_key']}\n\n";
}

if ($slug === null) {
    $tests = $db->select("SELECT * FROM tests WHERE is_active = 1 ORDER BY sort_order, name");
    $invite = array_filter($tests, static fn (array $t): bool => ($t['visibility'] ?? 'public') === 'invite');

    if ($invite === []) {
        echo "Закрытых методик нет: все показываются в каталоге.\n";
        exit(0);
    }

    foreach ($invite as $test) {
        showRow($test, $appUrl);
    }
    exit(0);
}

$test = $db->selectOne("SELECT * FROM tests WHERE slug = ?", [$slug]);
if (!$test) {
    fwrite(STDERR, "Методика «{$slug}» не найдена.\n");
    exit(1);
}

if (isset($options['open'])) {
    $db->update('tests', ['visibility' => 'public', 'access_key' => null], 'slug = ?', [$slug]);
    echo "Методика «{$slug}» открыта: снова показывается в каталоге и доступна без ключа.\n";
    exit(0);
}

if (isset($options['close']) || isset($options['rotate'])) {
    $key = bin2hex(random_bytes(24));
    $db->update('tests', ['visibility' => 'invite', 'access_key' => $key], 'slug = ?', [$slug]);
    echo isset($options['rotate'])
        ? "Ключ перевыпущен: прежние ссылки больше не работают.\n\n"
        : "Методика закрыта.\n\n";
    $test = $db->selectOne("SELECT * FROM tests WHERE slug = ?", [$slug]);
}

showRow((array) $test, $appUrl);
