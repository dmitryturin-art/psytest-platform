<?php

declare(strict_types=1);

namespace PsyTest\Core;

/**
 * Досрочно отдаёт HTTP-ответ и оставляет процесс работать дальше.
 *
 * Нужен для фоновой генерации ИИ-разборов: модель отвечает минуты, а браузер
 * и сервер оборвут запрос задолго до конца. Ответ уже ушёл, посетитель ушёл
 * со страницы — работа всё равно доводится до конца. Заголовки (в том числе
 * `Location`) должны быть выставлены до вызова.
 */
final class ResponseFinisher
{
    public static function finish(): void
    {
        ignore_user_abort(true);
        @set_time_limit(0);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            // Под обычным CGI остаётся только выпихнуть ответ и продолжить.
            @ob_end_flush();
            flush();
        }

        // Файл PHP-сессии заперт с проверки CSRF и держал бы следующий запрос
        // браузера (переход по 303) все минуты работы модели. Дальше сессия не
        // нужна, поэтому замок отпускается сразу после ответа.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }
}
