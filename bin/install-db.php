<?php
/**
 * Database Schema Installer
 * 
 * CLI script to install/update database schema
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$configLoader = require __DIR__ . '/../config.php';
$dbConfig = $configLoader->db();

// Connect without database selection first
$dsn = "mysql:host={$dbConfig['host']};charset={$dbConfig['charset']}";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    // Connect without database selection first
    $dsn = "mysql:host={$dbConfig['host']};charset={$dbConfig['charset']}";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], $options);

    // Create database if not exists
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbConfig['name']}` CHARACTER SET {$dbConfig['charset']} COLLATE utf8mb4_unicode_ci");
    
    echo "Installing database schema...\n";
    
    // Reconnect to the specific database
    $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']};charset={$dbConfig['charset']}";
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], $options);

    // Read and execute schema
    $schemaFile = __DIR__ . '/../database/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new RuntimeException("Schema file not found: $schemaFile");
    }

    $sql = file_get_contents($schemaFile);

    // Execute statements one by one (skip comments and empty lines)
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($stmt) => !empty($stmt) && !str_starts_with(trim($stmt), '--') && !str_starts_with(trim($stmt), 'SET ')
    );

    foreach ($statements as $statement) {
        if (empty(trim($statement))) {
            continue;
        }
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            // Ignore "already exists" errors
            if (!str_contains($e->getMessage(), 'already exists')) {
                throw $e;
            }
        }
    }

    echo "✓ Database schema installed successfully!\n";
    echo "  Database: {$dbConfig['name']}\n";
    echo "  Host: {$dbConfig['host']}\n";

} catch (PDOException $e) {
    echo "✗ Database installation failed: " . $e->getMessage() . "\n";
    exit(1);
}
