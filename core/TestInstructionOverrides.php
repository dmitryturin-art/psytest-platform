<?php

declare(strict_types=1);

namespace PsyTest\Core;

use PsyTest\Modules\TestModuleInterface;

/**
 * Инструкция респонденту, изменённая владельцем из кабинета (07.K15).
 *
 * Тот же порядок, что у промптов: файл `metadata.json` — исходное состояние в
 * Git, правка владельца лежит в БД поверх него. Есть строка — показывается
 * она, нет — инструкция из файла. Абзацы — простой текст: шаблон экранирует
 * их и никогда не выводит как HTML.
 *
 * В журнал пишется только факт правки и число абзацев — не сам текст.
 */
final class TestInstructionOverrides
{
    public const AUDIT_ACTION = 'test_instruction_changed';
    public const MAX_PARAGRAPHS = 20;
    public const MAX_LENGTH = 2000;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Инструкция для показа: правка владельца, иначе текст из файла методики.
     *
     * @return list<string>
     */
    public function resolve(TestModuleInterface $module): array
    {
        $slug = (string) ($module->getMetadata()['slug'] ?? '');
        $override = $slug === '' ? null : $this->get($slug);

        return $override ?? $module->getInstruction();
    }

    /** @return list<string>|null null — правки нет, действует текст из файла. */
    public function get(string $slug): ?array
    {
        $row = $this->db->selectOne(
            'SELECT paragraphs FROM test_instruction_overrides WHERE test_slug = ?',
            [$slug],
        );
        if ($row === null) {
            return null;
        }

        $decoded = json_decode((string) $row['paragraphs'], true);
        if (!is_array($decoded)) {
            return null;
        }

        $paragraphs = [];
        foreach ($decoded as $paragraph) {
            if (is_string($paragraph) && trim($paragraph) !== '') {
                $paragraphs[] = trim($paragraph);
            }
        }

        // Пустая правка не должна оставлять человека совсем без инструкции.
        return $paragraphs === [] ? null : $paragraphs;
    }

    public function updatedAt(string $slug): ?string
    {
        $row = $this->db->selectOne(
            'SELECT updated_at FROM test_instruction_overrides WHERE test_slug = ?',
            [$slug],
        );

        return $row === null ? null : (string) $row['updated_at'];
    }

    /**
     * Текст из поля формы → абзацы: абзацы разделены пустой строкой.
     *
     * @return list<string>
     */
    public static function paragraphsFromText(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $paragraphs = [];
        foreach (preg_split('/\n[ \t]*\n/u', $text) ?: [] as $chunk) {
            $chunk = trim($chunk);
            if ($chunk !== '') {
                $paragraphs[] = $chunk;
            }
        }

        return $paragraphs;
    }

    /**
     * @param list<string> $paragraphs
     * @throws \InvalidArgumentException с понятным владельцу текстом
     */
    public function save(string $slug, array $paragraphs): void
    {
        $clean = [];
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim((string) $paragraph);
            if ($paragraph !== '') {
                $clean[] = $paragraph;
            }
        }

        if ($clean === []) {
            throw new \InvalidArgumentException('Инструкция не может быть пустой. Чтобы вернуть исходный текст, нажмите «Вернуть исходную».');
        }
        if (count($clean) > self::MAX_PARAGRAPHS) {
            throw new \InvalidArgumentException('Слишком много абзацев: не больше ' . self::MAX_PARAGRAPHS . '. Объедините часть абзацев.');
        }
        foreach ($clean as $index => $paragraph) {
            if (mb_strlen($paragraph) > self::MAX_LENGTH) {
                throw new \InvalidArgumentException('Абзац ' . ($index + 1) . ' слишком длинный: не больше ' . self::MAX_LENGTH . ' знаков. Разделите его на два.');
            }
        }

        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $now = date('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $this->db->execute(
                'INSERT INTO test_instruction_overrides (test_slug, paragraphs, updated_at) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE paragraphs = VALUES(paragraphs), updated_at = VALUES(updated_at)',
                [$slug, $json, $now],
            );
            $this->audit($slug, 'saved', count($clean));
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }

            throw $e;
        }
    }

    /** Вернуть инструкцию из файла методики. */
    public function reset(string $slug): void
    {
        $this->db->beginTransaction();
        try {
            $removed = $this->db->delete('test_instruction_overrides', 'test_slug = ?', [$slug]);
            if ($removed > 0) {
                $this->audit($slug, 'reset', 0);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }

            throw $e;
        }
    }

    private function audit(string $slug, string $change, int $paragraphs): void
    {
        $this->db->insert('activity_log', [
            'session_id' => null,
            'test_id' => null,
            'action' => self::AUDIT_ACTION,
            'details' => json_encode([
                'actor' => 'owner',
                'test' => $slug,
                'change' => $change,
                'paragraphs' => $paragraphs,
            ], JSON_THROW_ON_ERROR),
        ]);
    }
}
