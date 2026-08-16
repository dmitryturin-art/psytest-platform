#!/usr/bin/env php
<?php
/**
 * Session Cleanup Script
 * 
 * Remove expired sessions (run via cron daily)
 * Example cron: 0 3 * * * php /path/to/bin/cleanup-sessions.sh
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PsyTest\Core\Database;
use PsyTest\Core\RetentionPolicy;
use PsyTest\Core\SessionLifecycleService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Level;

// Setup logging
$configLoader = require __DIR__ . '/../config.php';
$logger = new Logger('cleanup');
$logPath = $configLoader->logPath();
if (!is_dir($logPath)) {
    mkdir($logPath, 0755, true);
}
$logger->pushHandler(new StreamHandler($logPath . '/cleanup.log', Level::Info));

try {
    $db = Database::getInstance();
    
    $policy = new RetentionPolicy($configLoader->anonymousRetentionDays());
    $lifecycle = new SessionLifecycleService($db, $policy);
    $deletedCount = $lifecycle->purgeExpiredAnonymousSessions(new DateTimeImmutable());
    
    // Clean up old activity logs (older than 90 days)
    $logCutoff = date('Y-m-d H:i:s', strtotime('-90 days'));
    $sql = "DELETE FROM activity_log WHERE created_at < :cutoff";
    $stmt = $db->execute($sql, ['cutoff' => $logCutoff]);
    
    $logger->info("Cleanup completed", [
        'sessions_deleted' => $deletedCount,
        'retention_days' => $policy->anonymousRetentionDays(),
    ]);
    
    echo "✓ Cleanup completed: $deletedCount anonymous sessions removed\n";
    
} catch (Exception $e) {
    $logger->error("Cleanup failed: " . $e->getMessage());
    echo "✗ Cleanup failed: " . $e->getMessage() . "\n";
    exit(1);
}
