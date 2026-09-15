<?php

declare(strict_types=1);

namespace PsyTest\Core\Ai;

use PsyTest\Core\Database;

/**
 * Настройки ИИ, которыми владелец управляет из кабинета (07.WP9).
 *
 * Это общий выключатель разборов, переопределение модели и режим глоссария
 * СМИЛ в контексте разбора (07.G6). Всё
 * остальное — адрес провайдера, таймаут и, главное, ключ — остаётся в
 * environment: секрет не редактируется через веб и не хранится в БД
 * (PRODUCT_RULES §6, ENGINEERING_RULES §9).
 *
 * Отсутствие строки значит «по умолчанию»: разборы включены, модель берётся
 * из `.env`, глоссарий СМИЛ уходит полным. Так пустая таблица ведёт себя ровно
 * как поведение до этих пакетов.
 */
final class AiSettings
{
    public const KEY_ENABLED = 'ai_enabled';
    public const KEY_MODEL = 'ai_model';
    public const KEY_SMIL_GLOSSARY_MODE = 'smil_glossary_mode';

    /** @var array<string, string|null>|null */
    private ?array $cache = null;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Разборы разрешены, пока владелец явно не выключил их.
     *
     * Fail-open здесь осознан: это не право доступа, а рубильник, и его
     * отсутствие в БД означает «ничего не настраивали».
     */
    public function isAiEnabled(): bool
    {
        return ($this->value(self::KEY_ENABLED) ?? '1') !== '0';
    }

    /** Переопределение модели из кабинета; пустое значение — «из .env». */
    public function modelOverride(): string
    {
        return trim((string) ($this->value(self::KEY_MODEL) ?? ''));
    }

    /**
     * Режим глоссария СМИЛ в контексте разбора (07.G6): `full` или `compact`.
     *
     * Отсутствие строки — «полный», то есть ровно поведение до этого пакета:
     * компактный режим включается только явным действием владельца.
     */
    public function smilGlossaryMode(): string
    {
        return SmilGlossaryCompactor::normalizeMode($this->value(self::KEY_SMIL_GLOSSARY_MODE));
    }

    public function setSmilGlossaryMode(string $mode): void
    {
        $this->put(self::KEY_SMIL_GLOSSARY_MODE, SmilGlossaryCompactor::normalizeMode($mode));
    }

    public function setAiEnabled(bool $enabled): void
    {
        $this->put(self::KEY_ENABLED, $enabled ? '1' : '0');
    }

    public function setModelOverride(string $model): void
    {
        $this->put(self::KEY_MODEL, mb_substr(trim($model), 0, 255));
    }

    private function value(string $key): ?string
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach ($this->db->select('SELECT setting_key, setting_value FROM ai_settings') as $row) {
                $this->cache[(string) $row['setting_key']] = $row['setting_value'] === null
                    ? null
                    : (string) $row['setting_value'];
            }
        }

        return $this->cache[$key] ?? null;
    }

    private function put(string $key, string $value): void
    {
        // MySQL 5.7 не знает ON CONFLICT, а составного ключа здесь нет —
        // обычной пары «обновить или вставить» достаточно.
        $updated = $this->db->update('ai_settings', ['setting_value' => $value], 'setting_key = ?', [$key]);

        if ($updated === 0 && $this->db->selectOne('SELECT setting_key FROM ai_settings WHERE setting_key = ?', [$key]) === null) {
            $this->db->insert('ai_settings', ['setting_key' => $key, 'setting_value' => $value]);
        }

        $this->cache = null;
    }
}
