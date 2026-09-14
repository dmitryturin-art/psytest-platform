<?php

declare(strict_types=1);

namespace PsyTest\Core;

/**
 * PHP-сессия вошедшего посетителя.
 *
 * Ключ отдельный от кабинета владельца и ничего о нём не знает: вход
 * посетителя не даёт и не может дать ничего в `/admin`, а выход из одного
 * кабинета не выбрасывает из другого.
 */
final class VisitorAccountSession
{
    private const SESSION_KEY = 'psytest_visitor_account_id';
    private const EMAIL_KEY = 'psytest_visitor_account_email';

    public static function login(string $accountId, string $email): void
    {
        Security::startSession();
        // Идентификатор сессии меняется вместе со сменой прав: иначе заранее
        // подсунутый посетителю cookie стал бы после входа его кабинетом.
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = $accountId;
        $_SESSION[self::EMAIL_KEY] = $email;
    }

    public static function logout(): void
    {
        Security::startSession();
        unset($_SESSION[self::SESSION_KEY], $_SESSION[self::EMAIL_KEY]);
        session_regenerate_id(true);
    }

    /**
     * Снимок для навигации: показать «Мои результаты» вместо «Войти».
     *
     * Права по нему не выдаются — любая страница кабинета отдельно сверяет
     * аккаунт с базой, поэтому удалённый аккаунт не остаётся «вошедшим».
     *
     * @return array{id: string, email: string}|null
     */
    public static function snapshot(): ?array
    {
        $accountId = self::accountId();
        $email = $_SESSION[self::EMAIL_KEY] ?? null;

        return $accountId === null || !is_string($email)
            ? null
            : ['id' => $accountId, 'email' => $email];
    }

    public static function accountId(): ?string
    {
        Security::startSession();
        $accountId = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($accountId) && Security::isValidUuid($accountId) ? $accountId : null;
    }
}
