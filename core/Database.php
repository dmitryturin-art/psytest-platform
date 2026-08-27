<?php

/**
 * Database Connection Wrapper
 *
 * PDO-based database abstraction with security features
 */

declare(strict_types=1);

namespace PsyTest\Core;

use PDO;
use PDOException;
use PDOStatement;

class Database
{
    private static ?Database $instance = null;
    private PDO $connection;
    private array $config;

    private function __construct(array $config)
    {
        $this->config = $config;
        $this->connect();
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(array $config = []): self
    {
        if (self::$instance === null) {
            if (empty($config)) {
                $configLoader = require __DIR__ . '/../config.php';
                $config = $configLoader->db();
            }
            self::$instance = new self($config);
        }

        return self::$instance;
    }

    /**
     * Establish database connection
     */
    private function connect(): void
    {
        try {
            // DB_PORT по умолчанию 3306; отличается, когда база поднята рядом
            // с локальной — например MySQL 5.7 в Docker для bin/local-gate.sh.
            $port = (int) ($this->config['port'] ?? 3306);
            $dsn = "mysql:host={$this->config['host']};port={$port};dbname={$this->config['name']};charset=utf8mb4";

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ];

            $this->connection = new PDO(
                $dsn,
                $this->config['user'],
                $this->config['pass'],
                $options
            );

            // Set collation after connection
            $this->connection->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

        } catch (PDOException $e) {
            error_log('Database connection failed [' . $e->getCode() . ']');
            throw new PDOException("Database connection failed");
        }
    }

    /**
     * Get PDO connection
     */
    public function getConnection(): PDO
    {
        return $this->connection;
    }

    /**
     * Execute a SELECT query
     *
     * @param string $sql SQL query with placeholders
     * @param array $params Query parameters
     * @return array Query results
     */
    public function select(string $sql, array $params = []): array
    {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Execute a SELECT query and fetch single row
     *
     * @param string $sql SQL query with placeholders
     * @param array $params Query parameters
     * @return array|null Single row or null if not found
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->execute($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Execute an INSERT, UPDATE, or DELETE query
     *
     * @param string $sql SQL query with placeholders
     * @param array $params Query parameters
     * @return int Number of affected rows or last insert ID
     */
    public function execute(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);

            return $stmt;
        } catch (PDOException $e) {
            if (!self::isConnectionLost($e)) {
                error_log('Database query failed [' . $e->getCode() . ']');

                throw $e;
            }

            // Внутри транзакции повторять запрос нельзя: новое соединение не
            // знает о начатой транзакции, и повтор выполнился бы вне её, молча
            // разорвав атомарность. Отказ обязан дойти до вызывающего.
            //
            // Но соединение всё равно восстанавливается: транзакция потеряна в
            // любом случае, а без переподключения объект остался бы навсегда
            // непригодным — PDO продолжает считать, что транзакция открыта,
            // и все последующие запросы падали бы здесь же.
            if ($this->connection->inTransaction()) {
                error_log('Database connection lost inside a transaction');
                $this->connect();

                throw $e;
            }

            // Соединение закрыл сервер, пока мы были заняты. На рабочем хостинге
            // `wait_timeout` равен 30 секундам, а подготовка ИИ-разбора занимает
            // больше двух минут — к моменту записи результата соединения уже нет.
            //
            // Повтор здесь безопасен именно для этого случая: раз соединение
            // потеряно, запрос до сервера не дошёл и выполниться не мог.
            $this->connect();

            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);

            return $stmt;
        }
    }

    /**
     * Потеряно ли соединение с сервером.
     *
     * Определяется только по коду драйвера: 2006 («server has gone away») и
     * 2013 («lost connection»). Текст исключения здесь принципиально не
     * разбирается — драйверное сообщение может содержать значения параметров
     * запроса, то есть данные респондентов, и в этом файле его не трогают
     * вовсе (это же стережёт `DatabaseErrorLoggingTest`).
     */
    private static function isConnectionLost(PDOException $e): bool
    {
        return in_array((int) ($e->errorInfo[1] ?? 0), [2006, 2013], true);
    }

    /**
     * Insert a record and return the ID
     *
     * @param string $table Table name
     * @param array $data Associative array of column => value
     * @return string Last insert ID
     */
    public function insert(string $table, array $data): string
    {
        $columns = implode(', ', array_map(fn ($col) => "`$col`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = "INSERT INTO `$table` ($columns) VALUES ($placeholders)";
        $this->execute($sql, array_values($data));

        return $this->connection->lastInsertId();
    }

    /**
     * Update records in a table
     *
     * @param string $table Table name
     * @param array $data Associative array of column => value
     * @param string $where WHERE clause (with placeholders)
     * @param array $whereParams WHERE clause parameters
     * @return int Number of affected rows
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $setParts = [];
        foreach (array_keys($data) as $column) {
            $setParts[] = "`$column` = ?";
        }
        $setClause = implode(', ', $setParts);

        $sql = "UPDATE `$table` SET $setClause WHERE $where";
        $params = array_merge(array_values($data), $whereParams);

        $stmt = $this->execute($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Delete records from a table
     *
     * @param string $table Table name
     * @param string $where WHERE clause (with placeholders)
     * @param array $params WHERE clause parameters
     * @return int Number of affected rows
     */
    public function delete(string $table, string $where, array $params = []): int
    {
        $sql = "DELETE FROM `$table` WHERE $where";
        $stmt = $this->execute($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Begin a transaction
     */
    public function beginTransaction(): bool
    {
        return $this->connection->beginTransaction();
    }

    /**
     * Commit a transaction
     */
    public function commit(): bool
    {
        return $this->connection->commit();
    }

    /**
     * Rollback a transaction
     */
    public function rollback(): bool
    {
        return $this->connection->rollBack();
    }

    /**
     * Check if a transaction is active
     */
    public function inTransaction(): bool
    {
        return $this->connection->inTransaction();
    }

    /**
     * Quote a string for safe use in queries
     */
    public function quote(string $string): string
    {
        return $this->connection->quote($string);
    }

    /**
     * Prevent cloning
     */
    private function __clone()
    {
    }

    /**
     * Prevent unserialization
     */
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
