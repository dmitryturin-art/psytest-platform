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

        $session = $sessions->createSession((int) $test['id'], [
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Regression fixture browser',
        ]);

        try {
            $stored = $db->selectOne(
                'SELECT ip_address, user_agent FROM test_sessions WHERE id = ?',
                [$session['id']],
            );
            $activity = $db->selectOne(
                'SELECT ip_address, user_agent FROM activity_log WHERE session_id = ? AND action = ?',
                [$session['id'], 'session_created'],
            );

            self::assertNull($stored['ip_address']);
            self::assertNull($stored['user_agent']);
            self::assertNull($activity['ip_address']);
            self::assertNull($activity['user_agent']);
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
