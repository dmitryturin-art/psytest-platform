<?php

declare(strict_types=1);

namespace PsyTest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\FormOnce;

/**
 * Одноразовый ключ формы (07.K6a): повтор не выполняет действие второй раз.
 */
final class FormOnceTest extends TestCase
{
    public function testFirstClaimIsFreshAndTheSecondReplaysTheStoredResult(): void
    {
        $session = [];
        $once = new FormOnce($session);
        $key = $once->issue('invite');

        self::assertSame(FormOnce::FRESH, $once->claim('invite', $key));
        self::assertSame(FormOnce::REPLAY, $once->claim('invite', $key));
        self::assertNull($once->result('invite', $key), 'До завершения действия итога ещё нет.');

        $once->complete('invite', $key, ['type' => 'success', 'message' => 'ok', 'invite_url' => 'https://x/invite/abc']);

        // Состояние живёт в переданном хранилище — это $_SESSION следующего запроса.
        $nextRequest = new FormOnce($session);
        self::assertSame(FormOnce::REPLAY, $nextRequest->claim('invite', $key));
        self::assertSame('https://x/invite/abc', $nextRequest->result('invite', $key)['invite_url'] ?? null);
    }

    public function testMissingForeignOrMalformedKeysAreUnknown(): void
    {
        $session = [];
        $once = new FormOnce($session);
        $key = $once->issue('invite');

        self::assertSame(FormOnce::UNKNOWN, $once->claim('invite', null));
        self::assertSame(FormOnce::UNKNOWN, $once->claim('invite', ''));
        self::assertSame(FormOnce::UNKNOWN, $once->claim('invite', ['x']));
        self::assertSame(FormOnce::UNKNOWN, $once->claim('invite', str_repeat('a', 32)));
        self::assertSame(FormOnce::UNKNOWN, $once->claim('other-form', $key), 'Ключ одной формы не годится для другой.');
        self::assertSame(FormOnce::FRESH, $once->claim('invite', $key));
    }

    public function testReleasedKeyCanBeReusedButACompletedOneCannot(): void
    {
        $session = [];
        $once = new FormOnce($session);
        $key = $once->issue('invite');

        self::assertSame(FormOnce::FRESH, $once->claim('invite', $key));
        $once->release('invite', $key);
        self::assertSame(FormOnce::FRESH, $once->claim('invite', $key));

        $once->complete('invite', $key, ['type' => 'success', 'message' => 'ok']);
        $once->release('invite', $key);
        self::assertSame(FormOnce::REPLAY, $once->claim('invite', $key));
    }

    public function testKeysExpireAndOnlyTheLatestAreKept(): void
    {
        $now = 1_000_000;
        $session = [];
        $once = new FormOnce($session, static function () use (&$now): int {
            return $now;
        });

        $old = $once->issue('invite');
        $now += FormOnce::TTL_SECONDS + 1;
        self::assertSame(FormOnce::UNKNOWN, $once->claim('invite', $old));

        $first = $once->issue('invite');
        for ($i = 0; $i < FormOnce::MAX_KEYS; $i++) {
            $once->issue('invite');
        }
        self::assertSame(FormOnce::UNKNOWN, $once->claim('invite', $first), 'Хранится не больше MAX_KEYS ключей.');
        self::assertCount(FormOnce::MAX_KEYS, $session['psytest_form_once']['invite']);
    }
}
