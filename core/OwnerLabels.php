<?php

declare(strict_types=1);

namespace PsyTest\Core;

/**
 * Подписи служебных значений для кабинета специалиста.
 *
 * Карточка кейса и панель приглашений показывали `completed`, `partial` и
 * `therapist_case` — значения столбцов, а не состояние работы. Специалист
 * читает карточку клиента, а не схему базы, поэтому перевод живёт здесь, в
 * одном месте для всех owner-шаблонов.
 *
 * Сами значения в базе не меняются: это только отображение. Неизвестное
 * значение возвращается как есть — молча подменять его на «неизвестно» значило
 * бы скрыть от специалиста новое состояние.
 */
final class OwnerLabels
{
    /** Состояния `test_sessions.status` (см. `database/schema.sql`). */
    private const STATUS = [
        'partial' => 'в процессе',
        'completed' => 'завершено',
        'expired' => 'истекло',
        'deleted' => 'удалено',
    ];

    /** Режимы хранения — константы `RetentionPolicy`. */
    private const RETENTION = [
        RetentionPolicy::ANONYMOUS => 'анонимная',
        RetentionPolicy::THERAPIST_CASE => 'кейс специалиста',
        RetentionPolicy::ACCOUNT => 'аккаунт посетителя',
    ];

    /**
     * Тон метки статуса (04.D1): модификатор класса `status--*` в main.css.
     * Покрывает состояния сессии и приглашения (`display_status`). Неизвестное
     * значение получает нейтральный тон, подпись при этом не меняется.
     */
    private const TONE = [
        'completed' => 'done',
        'partial' => 'open',
        'opened' => 'open',
        'pending' => 'pending',
        'revoked' => 'muted',
        'expired' => 'muted',
        'result_deleted' => 'muted',
        'deleted' => 'failed',
    ];

    public static function tone(?string $value): string
    {
        return self::TONE[$value ?? ''] ?? 'muted';
    }

    public static function status(?string $value): string
    {
        return self::lookup(self::STATUS, $value);
    }

    public static function retention(?string $value): string
    {
        return self::lookup(self::RETENTION, $value);
    }

    /** @param array<string, string> $map */
    private static function lookup(array $map, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return $map[$value] ?? $value;
    }
}
