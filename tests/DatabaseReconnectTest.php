<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Database;

/**
 * Восстановление соединения после простоя.
 *
 * На рабочем хостинге `wait_timeout` равен 30 секундам, а подготовка ИИ-разбора
 * занимает больше двух минут: к моменту записи результата соединения уже нет.
 * Из-за этого первый разбор на сервере не сохранился вовсе.
 *
 * Разрыв здесь устраивается так же, как его устраивает сервер, — **снаружи**,
 * отдельным соединением. Убить собственное соединение нельзя: повтор запроса
 * переподключился бы и убил уже новое.
 */
#[Group('database')]
final class DatabaseReconnectTest extends TestCase
{
    private function connectionId(Database $db): int
    {
        return (int) $db->selectOne('SELECT CONNECTION_ID() AS id')['id'];
    }

    /** Обрывает чужое соединение, как это делает сервер по таймауту. */
    private function killFromOutside(int $connectionId): void
    {
        $config = (require dirname(__DIR__) . '/config.php')->db();
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'] ?? 3306,
            $config['name'],
        );

        $outside = new \PDO($dsn, $config['user'], $config['pass'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $outside->exec('KILL ' . $connectionId);
        unset($outside);

        // Серверу нужен момент, чтобы соединение действительно закрылось.
        usleep(200_000);
    }

    public function testQuerySurvivesAConnectionClosedByTheServer(): void
    {
        $db = Database::getInstance();
        $before = $this->connectionId($db);

        $this->killFromOutside($before);

        $row = $db->selectOne('SELECT 1 AS alive');

        self::assertSame(1, (int) $row['alive'], 'Запрос после разрыва должен пройти на новом соединении.');
        self::assertNotSame($before, $this->connectionId($db), 'Соединение обязано быть новым, а не прежним.');
    }

    public function testWriteAfterALongPauseStillLands(): void
    {
        // Тот самый случай: долгий вызов провайдера, потом запись результата.
        $db = Database::getInstance();
        $this->killFromOutside($this->connectionId($db));

        $count = $db->selectOne('SELECT COUNT(*) AS c FROM tests');

        self::assertGreaterThan(0, (int) $count['c'], 'Запись и чтение после разрыва обязаны работать.');
    }

    public function testConnectionLossInsideATransactionIsNotHiddenByARetry(): void
    {
        // Переподключение внутри транзакции молча разорвало бы атомарность:
        // новое соединение о начатой транзакции не знает.
        $db = Database::getInstance();

        $db->beginTransaction();
        $this->killFromOutside($this->connectionId($db));

        try {
            $db->execute('SELECT 1');
            self::fail('Отказ внутри транзакции обязан дойти до вызывающего, а не прятаться за повтором.');
        } catch (\PDOException) {
            self::assertTrue(true);
        } finally {
            try {
                $db->rollback();
            } catch (\PDOException) {
                // Соединения уже нет — откатывать нечего.
            }
        }
    }
}
