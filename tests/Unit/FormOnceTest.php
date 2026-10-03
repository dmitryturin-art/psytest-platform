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

    public function testRunPerformsTheActionOnceAndReplaysItsResult(): void
    {
        $session = [];
        $once = new FormOnce($session);
        $key = $once->issue('form');
        $calls = 0;
        $action = static function () use (&$calls): array {
            $calls++;

            return ['type' => 'success', 'message' => 'Создано ' . $calls, 'redirect' => '/x'];
        };

        $first = $once->run('form', $key, $action, 'устарела', 'уже отправлена');
        $second = (new FormOnce($session))->run('form', $key, $action, 'устарела', 'уже отправлена');

        self::assertSame(1, $calls);
        self::assertSame(FormOnce::FRESH, $first['claim']);
        self::assertSame(FormOnce::REPLAY, $second['claim']);
        self::assertSame($first['result'], $second['result'], 'Повтор получает тот же ответ, включая служебные поля.');

        // Новый ключ — новая отрисовка формы: действие выполняется снова.
        $third = $once->run('form', $once->issue('form'), $action, 'устарела', 'уже отправлена');
        self::assertSame(2, $calls);
        self::assertSame(FormOnce::FRESH, $third['claim']);
    }

    public function testRunWithoutAKnownKeyDoesNothing(): void
    {
        $session = [];
        $once = new FormOnce($session);
        $once->issue('form');
        $calls = 0;
        $action = static function () use (&$calls): array {
            $calls++;

            return ['type' => 'success', 'message' => 'ok'];
        };

        foreach ([null, '', str_repeat('ab', 16)] as $key) {
            $outcome = $once->run('form', $key, $action, 'устарела', 'уже отправлена');
            self::assertSame(FormOnce::UNKNOWN, $outcome['claim']);
            self::assertSame(['type' => 'error', 'message' => 'устарела'], $outcome['result']);
        }
        self::assertSame(0, $calls);
    }

    public function testRunErrorOrExceptionKeepsTheKeyUsable(): void
    {
        $session = [];
        $once = new FormOnce($session);
        $key = $once->issue('form');

        $invalid = $once->run('form', $key, static fn (): array => ['type' => 'error', 'message' => 'ошибка ввода'], 's', 'p');
        self::assertSame(FormOnce::FRESH, $invalid['claim']);

        try {
            $once->run('form', $key, static function (): array {
                throw new \RuntimeException('сбой');
            }, 's', 'p');
            self::fail('Исключение действия пробрасывается.');
        } catch (\RuntimeException) {
        }

        $fixed = $once->run('form', $key, static fn (): array => ['type' => 'success', 'message' => 'ok'], 's', 'p');
        self::assertSame(FormOnce::FRESH, $fixed['claim'], 'Ни ошибка ввода, ни сбой ключ не сжигают.');
        self::assertSame('ok', $fixed['result']['message']);
    }

    public function testRunReplayWithoutAStoredResultReportsTheFormAsAlreadySent(): void
    {
        $session = [];
        $once = new FormOnce($session);
        $key = $once->issue('form');
        // Первый запрос захватил ключ, но итог ещё не записал.
        $once->claim('form', $key);

        $outcome = $once->run('form', $key, static fn (): array => ['type' => 'success', 'message' => 'дубль'], 's', 'уже отправлена');

        self::assertSame(FormOnce::REPLAY, $outcome['claim']);
        self::assertSame(['type' => 'error', 'message' => 'уже отправлена'], $outcome['result']);
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
