<?php

require_once __DIR__ . '/vendor/autoload.php';

$config = require __DIR__ . '/config.php';

$env = getenv('APP_ENV') ?: 'development';

return [
    'paths' => [
        'migrations' => __DIR__ . '/database/migrations',
    ],
    'environments' => [
        'default_migration_path' => __DIR__ . '/database/migrations',
        'default_environment' => $env,
        $env => [
            'adapter' => 'mysql',
            'name' => $config->getString('DB_NAME'),
            'connection' => null,
            'host' => $config->getString('DB_HOST'),
            'user' => $config->getString('DB_USER'),
            'pass' => $config->getString('DB_PASS'),
            'charset' => $config->getString('DB_CHARSET', 'utf8mb4'),
            'port' => 3306,
        ],
    ],
];
