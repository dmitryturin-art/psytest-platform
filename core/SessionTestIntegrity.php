<?php

declare(strict_types=1);

namespace PsyTest\Core;

/**
 * Verifies that a routed test and a stored test session describe the same test.
 *
 * A public result token grants access only to the test session that issued it;
 * it must not be reusable under another test slug.
 */
final class SessionTestIntegrity
{
    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $test
     */
    public static function matches(array $session, array $test): bool
    {
        return isset($session['test_id'], $test['id'])
            && (int) $session['test_id'] === (int) $test['id'];
    }
}
