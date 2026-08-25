<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Database;
use PsyTest\Core\SessionManager;

final class SessionDataMinimizationTest extends TestCase
{
    #[Group('database')]
    public function testNewSessionAndActivityRecordsDoNotCaptureClientMetadata(): void
    {
        $db = Database::getInstance();
        $test = $db->selectOne("SELECT id FROM tests WHERE slug = 'bdi'");
        $sessions = new SessionManager($db);

        // Metadata options must be silently ignored: the columns no longer exist.
        $session = $sessions->createSession((int) $test['id'], [
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Regression fixture browser',
        ]);

        try {
            foreach (['test_sessions', 'activity_log'] as $table) {
                $columns = $db->select('SHOW COLUMNS FROM `' . $table . '`');
                $names = array_map(
                    static fn (array $column): string => (string) $column['Field'],
                    $columns,
                );
                self::assertNotContains('ip_address', $names, "Legacy metadata column {$table}.ip_address must stay dropped.");
                self::assertNotContains('user_agent', $names, "Legacy metadata column {$table}.user_agent must stay dropped.");
            }

            $activity = $db->selectOne(
                'SELECT details FROM activity_log WHERE session_id = ? AND action = ?',
                [$session['id'], 'session_created'],
            );
            self::assertNotFalse($activity);
        } finally {
            $db->delete('activity_log', 'session_id = ?', [$session['id']]);
            $db->delete('test_sessions', 'id = ?', [$session['id']]);
        }
    }

    public function testPublicTestStartDoesNotReadClientHeaders(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/TestController.php');

        self::assertStringNotContainsString("\$_SERVER['REMOTE_ADDR']", $controller);
        self::assertStringNotContainsString("\$_SERVER['HTTP_USER_AGENT']", $controller);
    }
}
