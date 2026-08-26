<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Порт БД задаётся через DB_PORT и по умолчанию равен 3306.
 *
 * Нужен, чтобы полный gate можно было прогнать на MySQL 5.7 в Docker рядом
 * с локальной базой разработчика (bin/local-gate.sh), не трогая .env проекта.
 */
final class DatabasePortConfigurationTest extends TestCase
{
    private function config(): object
    {
        // config.php читает окружение при каждом require, поэтому берём свежий экземпляр.
        return require dirname(__DIR__) . '/config.php';
    }

    public function testDsnCarriesTheDefaultPortWhenNothingIsConfigured(): void
    {
        putenv('DB_PORT');

        $config = $this->config();

        self::assertSame(3306, $config->db()['port']);
        self::assertStringContainsString('port=3306', $config->dsn());
    }

    public function testDsnFollowsAnExplicitPort(): void
    {
        putenv('DB_PORT=13357');

        try {
            $config = $this->config();

            self::assertSame(13357, $config->db()['port']);
            self::assertStringContainsString('port=13357', $config->dsn());
        } finally {
            putenv('DB_PORT');
        }
    }

    public function testPhinxUsesTheSamePortSource(): void
    {
        $phinx = (string) file_get_contents(dirname(__DIR__) . '/phinx.php');

        self::assertStringContainsString("'port' => \$config->getInt('DB_PORT', 3306)", $phinx);
        self::assertStringNotContainsString("'port' => 3306,", $phinx, 'Порт не должен быть зашит мимо конфигурации.');
    }
}
