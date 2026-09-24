<?php

declare(strict_types=1);

namespace PsyTest\Core;

/**
 * Одноразовый ключ формы: защита от повторной отправки (07.K6a).
 *
 * CSRF-токен живёт всю сессию, поэтому двойное нажатие кнопки проходит как
 * два разных запроса. Здесь каждая отрисовка формы получает свой случайный
 * ключ, а сервер помнит, какие ключи уже отработали и чем закончились:
 *
 * - первый POST с ключом «захватывает» его и выполняет действие;
 * - повторный POST с тем же ключом действие не повторяет и получает
 *   сохранённый результат первого (например, ту же ссылку-приглашение);
 * - POST без ключа или с неизвестным (устаревшим, чужим) ключом отклоняется.
 *
 * Состояние лежит в сессии PHP. Стандартный файловый обработчик сессий
 * блокирует файл на время запроса, поэтому второй запрос двойного клика ждёт
 * окончания первого и видит уже захваченный ключ. Хранится не больше
 * MAX_KEYS последних ключей на форму и не дольше TTL_SECONDS.
 */
final class FormOnce
{
    public const FRESH = 'fresh';
    public const REPLAY = 'replay';
    public const UNKNOWN = 'unknown';

    public const MAX_KEYS = 20;
    public const TTL_SECONDS = 43200;

    private const SESSION_KEY = 'psytest_form_once';

    /** @var array<string, mixed> */
    private array $store;

    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param array<string, mixed> $store Хранилище (обычно `$_SESSION`), по ссылке.
     * @param (\Closure(): int)|null $clock Часы для тестов.
     */
    public function __construct(array &$store, ?\Closure $clock = null)
    {
        $this->store = &$store;
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** Новый ключ для очередной отрисовки формы. */
    public function issue(string $form): string
    {
        $keys = $this->keys($form);
        $key = bin2hex(random_bytes(16));
        $keys[$key] = ['at' => ($this->clock)(), 'used' => false, 'result' => null];
        if (count($keys) > self::MAX_KEYS) {
            $keys = array_slice($keys, -self::MAX_KEYS, null, true);
        }
        $this->save($form, $keys);

        return $key;
    }

    /**
     * Захватить ключ перед действием.
     *
     * FRESH — ключ выдан и ещё не использован: он помечается использованным,
     * действие нужно выполнить. REPLAY — ключ уже отработал: действие не
     * повторять, итог первого запроса — в `result()`. UNKNOWN — ключа нет.
     */
    public function claim(string $form, mixed $key): string
    {
        if (!is_string($key) || preg_match('/\A[a-f0-9]{32}\z/', $key) !== 1) {
            return self::UNKNOWN;
        }
        $keys = $this->keys($form);
        if (!isset($keys[$key])) {
            return self::UNKNOWN;
        }
        if ($keys[$key]['used']) {
            return self::REPLAY;
        }

        $keys[$key]['used'] = true;
        $this->save($form, $keys);

        return self::FRESH;
    }

    /**
     * Вернуть захваченный ключ, если действие не выполнено (ошибка ввода):
     * исправленную форму можно отправить с тем же ключом.
     */
    public function release(string $form, string $key): void
    {
        $keys = $this->keys($form);
        if (isset($keys[$key]) && $keys[$key]['result'] === null) {
            $keys[$key]['used'] = false;
            $this->save($form, $keys);
        }
    }

    /**
     * Запомнить итог выполненного действия для повторных запросов.
     *
     * @param array<string, string> $result
     */
    public function complete(string $form, string $key, array $result): void
    {
        $keys = $this->keys($form);
        if (isset($keys[$key])) {
            $keys[$key]['used'] = true;
            $keys[$key]['result'] = $result;
            $this->save($form, $keys);
        }
    }

    /** @return array<string, string>|null */
    public function result(string $form, string $key): ?array
    {
        return $this->keys($form)[$key]['result'] ?? null;
    }

    /** @return array<string, array{at: int, used: bool, result: array<string, string>|null}> */
    private function keys(string $form): array
    {
        $all = $this->store[self::SESSION_KEY] ?? [];
        $keys = is_array($all) && isset($all[$form]) && is_array($all[$form]) ? $all[$form] : [];
        $now = ($this->clock)();
        $valid = [];
        foreach ($keys as $key => $entry) {
            if (!is_string($key) || !is_array($entry) || !is_int($entry['at'] ?? null)) {
                continue;
            }
            if ($now - $entry['at'] > self::TTL_SECONDS) {
                continue;
            }
            $result = $entry['result'] ?? null;
            $valid[$key] = [
                'at' => $entry['at'],
                'used' => ($entry['used'] ?? false) === true,
                'result' => is_array($result) ? array_map('strval', $result) : null,
            ];
        }

        return $valid;
    }

    /** @param array<string, array{at: int, used: bool, result: array<string, string>|null}> $keys */
    private function save(string $form, array $keys): void
    {
        $all = $this->store[self::SESSION_KEY] ?? [];
        if (!is_array($all)) {
            $all = [];
        }
        $all[$form] = $keys;
        $this->store[self::SESSION_KEY] = $all;
    }
}
