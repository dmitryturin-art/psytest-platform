#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Classifies a newline-delimited list of changed paths for GitHub Actions.
 *
 * Fast tests run for every change. The MySQL 5.7/8.0 matrix is additionally
 * required when migrations, persistence code, DB tests, dependencies, or the
 * CI classifier itself change. Every push to main still forces the matrix in
 * the workflow, so an omitted path cannot reach deployment without DB checks.
 */

$databasePaths = [
    '.github/workflows/quality.yml',
    '.env.example',
    'bin/classify-ci-scope.php',
    'bin/cleanup-sessions.php',
    'bin/install-db.php',
    'composer.json',
    'composer.lock',
    'config.php',
    'controllers/OwnerController.php',
    'controllers/TestController.php',
    'core/Database.php',
    'core/OwnerDashboardAuthenticator.php',
    'core/SessionLifecycleService.php',
    'core/SessionManager.php',
    'core/TherapistCaseService.php',
    'phinx.php',
    'phpunit.xml',
    'tests/OwnerDashboardAuthenticatorTest.php',
    'tests/Integration/LazarusE2ETest.php',
    'tests/Integration/MigratedSchemaTest.php',
    'tests/Integration/SessionDataMinimizationTest.php',
    'tests/Integration/SessionLifecycleServiceTest.php',
    'tests/Integration/TherapistCaseServiceTest.php',
];

$requiresDatabase = false;
while (($line = fgets(STDIN)) !== false) {
    $path = trim($line);
    if ($path === '') {
        continue;
    }

    if (
        str_starts_with($path, 'database/')
        || str_starts_with($path, 'services/')
        || in_array($path, $databasePaths, true)
    ) {
        $requiresDatabase = true;
        break;
    }
}

fwrite(STDOUT, 'database=' . ($requiresDatabase ? 'true' : 'false') . PHP_EOL);
