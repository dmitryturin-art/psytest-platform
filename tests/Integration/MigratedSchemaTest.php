<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Database;

/**
 * Validates the schema that Phinx has actually applied to the test database.
 *
 * GitHub Actions runs `composer migrate` before PHPUnit on MySQL 5.7 and 8.0,
 * so this test covers both the migration chain and its resulting schema.
 */
#[Group('database')]
final class MigratedSchemaTest extends TestCase
{
    private PDO $connection;

    protected function setUp(): void
    {
        $this->connection = Database::getInstance()->getConnection();
    }

    public function testIncrementalMigrationAndDecommissioningContractsExistInAppliedSchema(): void
    {
        $this->assertColumns('test_sessions', ['retention_class']);
        $this->assertIndex('test_sessions', 'idx_retention_created', false);
        $this->assertIndex('test_sessions', 'uq_partner_token', true);

        $this->assertMissingTable('ai_processing_consents');
        $this->assertMissingTable('crisis_resources');

        foreach (['test_sessions', 'activity_log'] as $table) {
            $this->assertMissingColumn($table, 'ip_address');
            $this->assertMissingColumn($table, 'user_agent');
        }
    }

    /**
     * @param list<string> $expectedColumns
     */
    private function assertColumns(string $table, array $expectedColumns): void
    {
        $columns = $this->columnNames($table);

        foreach ($expectedColumns as $column) {
            self::assertContains($column, $columns, "Missing {$table}.{$column} in migrated schema.");
        }
    }

    private function assertMissingColumn(string $table, string $column): void
    {
        self::assertNotContains(
            $column,
            $this->columnNames($table),
            "Unexpected {$table}.{$column} in migrated schema."
        );
    }

    private function assertMissingTable(string $table): void
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $statement->execute([$table]);

        self::assertSame(0, (int) $statement->fetchColumn(), "Unexpected {$table} table in migrated schema.");
    }

    private function assertIndex(string $table, string $index, bool $unique): void
    {
        $statement = $this->connection->query('SHOW INDEX FROM `' . $table . '`');
        self::assertNotFalse($statement);
        $indexes = $statement->fetchAll(PDO::FETCH_ASSOC);

        foreach ($indexes as $definition) {
            if (($definition['Key_name'] ?? null) === $index) {
                self::assertSame($unique ? '0' : '1', (string) ($definition['Non_unique'] ?? ''), "Unexpected uniqueness for {$table}.{$index}.");
                return;
            }
        }

        self::fail("Missing {$table}.{$index} in migrated schema.");
    }

    /**
     * @return list<string>
     */
    private function columnNames(string $table): array
    {
        $statement = $this->connection->query('SHOW COLUMNS FROM `' . $table . '`');
        self::assertNotFalse($statement);

        return array_map(
            static fn (array $column): string => (string) $column['Field'],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }
}
