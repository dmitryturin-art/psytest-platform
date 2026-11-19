<?php

declare(strict_types=1);

namespace PsyTest\Core;

/**
 * Запись нового пароля кабинета владельца в серверный `.env`.
 *
 * Открытый пароль нигде не сохраняется: в файл уходит только Argon2id-хэш,
 * а сам пароль живёт в памяти одного запроса. Замена атомарная: новое
 * содержимое собирается во временном файле рядом с `.env` и подменяет его
 * через `rename`, поэтому оборванная запись не оставит половину конфигурации.
 */
final class OwnerPasswordStore
{
    public const KEY = 'OWNER_DASHBOARD_PASSWORD_HASH';
    public const MIN_LENGTH = 8;
    public const MAX_LENGTH = 200;

    public function __construct(private readonly string $envPath)
    {
    }

    public function isWritable(): bool
    {
        $path = realpath($this->envPath);

        return $path !== false && is_file($path) && is_writable($path);
    }

    /**
     * Сохранить новый пароль как Argon2id-хэш.
     *
     * @throws \InvalidArgumentException если пароль пустой, короче минимума или длиннее лимита
     * @throws \RuntimeException если `.env` не найден, не читается или не заменяется
     */
    public function save(string $password): void
    {
        $length = mb_strlen($password);
        if ($length < self::MIN_LENGTH) {
            throw new \InvalidArgumentException(
                'Пароль короче ' . self::MIN_LENGTH . ' символов.'
            );
        }
        if ($length > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(
                'Пароль длиннее ' . self::MAX_LENGTH . ' символов.'
            );
        }

        $hash = password_hash($password, PASSWORD_ARGON2ID);
        if (!is_string($hash)) {
            throw new \RuntimeException('Не удалось вычислить хэш пароля.');
        }

        $info = password_get_info($hash);
        if (($info['algoName'] ?? null) !== 'argon2id') {
            throw new \RuntimeException('Сборка PHP не даёт Argon2id.');
        }
        if (!password_verify($password, $hash)) {
            throw new \RuntimeException('Хэш не подтвердил пароль.');
        }

        $path = realpath($this->envPath);
        if ($path === false || !is_file($path)) {
            throw new \RuntimeException('Файл .env не найден.');
        }
        if (!is_readable($path)) {
            throw new \RuntimeException('Файл .env недоступен для чтения.');
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException('Не удалось прочитать .env.');
        }

        $line = self::KEY . '=' . $hash;
        $replaced = false;
        foreach ($lines as $index => $existing) {
            if (str_starts_with($existing, self::KEY . '=')) {
                $lines[$index] = $line;
                $replaced = true;
            }
        }
        if (!$replaced) {
            $lines[] = $line;
        }

        $dir = dirname($path);
        if (!is_writable($dir) || !is_writable($path)) {
            throw new \RuntimeException('Нет прав на запись .env.');
        }

        $tmp = tempnam($dir, '.env.');
        if ($tmp === false || dirname($tmp) !== $dir) {
            if (is_string($tmp)) {
                @unlink($tmp);
            }

            throw new \RuntimeException('Не удалось создать временный файл рядом с .env.');
        }

        $written = file_put_contents($tmp, implode("\n", $lines) . "\n", LOCK_EX);
        if ($written === false) {
            @unlink($tmp);

            throw new \RuntimeException('Не удалось записать новый .env.');
        }

        @chmod($tmp, 0600);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            throw new \RuntimeException('Не удалось заменить .env.');
        }
    }
}
