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
use PsyTest\Core\SessionManager;
use PsyTest\Core\TestInviteService;
use PsyTest\Core\TherapistCaseService;
use PsyTest\Core\TherapistClientService;
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
    $lifecycle = new SessionLifecycleService($db, $policy, $configLoader->pdfStoragePath());
    $deletedCount = $lifecycle->purgeExpiredAnonymousSessions(new DateTimeImmutable());
    
    // Корзина приглашений (07.K8): кейсы, пролежавшие там дольше срока,
    // стираются окончательно — ответы, результат, разборы, выгрузки.
    $invites = new TestInviteService($db, new SessionManager($db));
    $clients = new TherapistClientService($db, $lifecycle);
    $cases = new TherapistCaseService($db, $lifecycle, $invites, $clients);
    $trashThreshold = (new DateTimeImmutable())->modify('-' . TestInviteService::TRASH_RETENTION_DAYS . ' days');
    $trash = $cases->purgeTrash($trashThreshold);

    // Корзина карточек клиентов (07.K11): после приглашений, чтобы уже
    // стёртые кейсы не мешали, а вместе с карточкой ушло всё остальное.
    $clientTrash = $clients->purgeTrash($trashThreshold);

    // Clean up old activity logs (older than 90 days)
    $logCutoff = date('Y-m-d H:i:s', strtotime('-90 days'));
    $sql = "DELETE FROM activity_log WHERE created_at < :cutoff";
    $stmt = $db->execute($sql, ['cutoff' => $logCutoff]);
    
    $logger->info("Cleanup completed", [
        'sessions_deleted' => $deletedCount,
        'retention_days' => $policy->anonymousRetentionDays(),
        'invites_purged' => $trash['invites'],
        'cases_purged' => $trash['cases'],
        'purge_failed' => $trash['failed'],
        'clients_purged' => $clientTrash['clients'],
        'clients_purge_failed' => $clientTrash['failed'],
        'trash_retention_days' => TestInviteService::TRASH_RETENTION_DAYS,
    ]);
    
    echo "✓ Cleanup completed: $deletedCount anonymous sessions removed, "
        . "{$trash['invites']} trashed invites purged ({$trash['cases']} cases), "
        . "{$clientTrash['clients']} trashed clients purged\n";
    
} catch (Exception $e) {
    $logger->error("Cleanup failed: " . $e->getMessage());
    echo "✗ Cleanup failed: " . $e->getMessage() . "\n";
    exit(1);
}
