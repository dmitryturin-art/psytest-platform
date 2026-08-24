<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Database;

/**
 * Validates the schema that Phinx has actually applied to the test database.
 *
 * GitHub Actions runs `composer migrate` before PHPUnit on MySQL 5.7 and 8.0,
 * so this test covers both the migration chain and its resulting schema.
 */
final class MigratedSchemaTest extends TestCase
{
    private PDO $connection;

    protected function setUp(): void
    {
        $this->connection = Database::getInstance()->getConnection();
    }

    public function testIncrementalMigrationContractsExistInAppliedSchema(): void
    {
        $this->assertColumns('test_sessions', ['retention_class']);
        $this->assertIndex('test_sessions', 'idx_retention_created', false);
        $this->assertIndex('test_sessions', 'uq_partner_token', true);

        $this->assertColumns('crisis_resources', [
            'country_code',
            'language_code',
            'resource_type',
            'display_name',
            'contact_or_url',
            'official_source_url',
            'verified_at',
            'verified_by',
            'active',
        ]);
        $this->assertIndex('crisis_resources', 'idx_country_language_active', false);
        $this->assertIndex('crisis_resources', 'idx_active_verified', false);

        $this->assertColumns('ai_processing_consents', [
            'session_id',
            'checkout_reference',
            'notice_version',
            'provider_code',
            'report_kind',
            'allowed_data',
            'consented_at',
            'revoked_at',
        ]);
        $this->assertMissingColumns('ai_processing_consents', ['ip_address', 'user_agent']);
        $this->assertIndex('ai_processing_consents', 'uq_ai_consent_checkout', true);
        $this->assertForeignKeyDeleteRule('ai_processing_consents', 'session_id', 'test_sessions', 'CASCADE');
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

    /**
     * @param list<string> $unexpectedColumns
     */
    private function assertMissingColumns(string $table, array $unexpectedColumns): void
    {
        $columns = $this->columnNames($table);

        foreach ($unexpectedColumns as $column) {
            self::assertNotContains($column, $columns, "Unexpected {$table}.{$column} in migrated schema.");
        }
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

    private function assertForeignKeyDeleteRule(
        string $table,
        string $column,
        string $referencedTable,
        string $deleteRule,
    ): void {
        $statement = $this->connection->prepare(
            'SELECT REFERENCED_TABLE_NAME, DELETE_RULE
             FROM information_schema.REFERENTIAL_CONSTRAINTS AS constraints
             INNER JOIN information_schema.KEY_COLUMN_USAGE AS keys_usage
               ON constraints.CONSTRAINT_SCHEMA = keys_usage.CONSTRAINT_SCHEMA
              AND constraints.CONSTRAINT_NAME = keys_usage.CONSTRAINT_NAME
             WHERE keys_usage.TABLE_SCHEMA = DATABASE()
               AND keys_usage.TABLE_NAME = ?
               AND keys_usage.COLUMN_NAME = ?'
        );
        $statement->execute([$table, $column]);
        $foreignKey = $statement->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($foreignKey, "Missing foreign key for {$table}.{$column}.");
        self::assertSame($referencedTable, $foreignKey['REFERENCED_TABLE_NAME']);
        self::assertSame($deleteRule, $foreignKey['DELETE_RULE']);
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
