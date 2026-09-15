<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Редактирование промптов из кабинета и общий выключатель ИИ (07.WP9).
 *
 * Уточнение владельца 26.08: файлы в `prompts/` остаются версионируемым
 * исходным состоянием в Git, а правки владельца ложатся в БД поверх них.
 * Поэтому здесь нет ни копии файловых версий, ни их номеров: в
 * `prompt_versions` попадает только то, что написано в кабинете, а
 * `prompt_publications` говорит, какая версия ключа считается опубликованной
 * (NULL — «как в manifest.json»).
 *
 * `ai_settings` — две настройки владельца, которые раньше жили только в `.env`:
 * общий выключатель разборов и переопределение модели. Ключ провайдера сюда
 * не попадает никогда: секреты остаются в environment (PRODUCT_RULES §6).
 */
final class AddPromptVersions extends AbstractMigration
{
    /** MEDIUMTEXT: промпт с примерами оформления заметно длиннее 64 КБ TEXT. */
    private const MEDIUM_TEXT = 16777215;

    public function up(): void
    {
        if (!$this->hasTable('prompt_versions')) {
            $this->table('prompt_versions', ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'char', ['limit' => 36, 'null' => false, 'comment' => 'UUID'])
                ->addColumn('test', 'string', ['limit' => 64, 'null' => false])
                ->addColumn('mode', 'string', ['limit' => 16, 'null' => false])
                ->addColumn('kind', 'string', ['limit' => 16, 'null' => false])
                ->addColumn('version', 'integer', [
                    'signed' => false,
                    'null' => false,
                    'comment' => 'Сквозной номер версии ключа: больше любого файлового и любого прежнего из кабинета',
                ])
                ->addColumn('text', 'text', [
                    'limit' => self::MEDIUM_TEXT,
                    'null' => false,
                    'comment' => 'Текст system prompt целиком',
                ])
                ->addColumn('note', 'string', [
                    'limit' => 255,
                    'null' => true,
                    'default' => null,
                    'comment' => 'Заметка владельца о причине правки',
                ])
                ->addColumn('allows_owner_context', 'boolean', [
                    'null' => false,
                    'default' => false,
                    'comment' => 'Принимает ли промпт клинический контекст специалиста',
                ])
                ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['test', 'mode', 'kind', 'version'], [
                    'unique' => true,
                    'name' => 'uq_prompt_versions_key_version',
                ])
                ->create();
        }

        if (!$this->hasTable('prompt_publications')) {
            $this->table('prompt_publications', ['id' => false, 'primary_key' => ['test', 'mode', 'kind']])
                ->addColumn('test', 'string', ['limit' => 64, 'null' => false])
                ->addColumn('mode', 'string', ['limit' => 16, 'null' => false])
                ->addColumn('kind', 'string', ['limit' => 16, 'null' => false])
                ->addColumn('published_version', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'default' => null,
                    'comment' => 'NULL — версия берётся из manifest.json',
                ])
                ->addColumn('updated_at', 'timestamp', [
                    'default' => 'CURRENT_TIMESTAMP',
                    'update' => 'CURRENT_TIMESTAMP',
                ])
                ->create();
        }

        if (!$this->hasTable('ai_settings')) {
            $this->table('ai_settings', ['id' => false, 'primary_key' => ['setting_key']])
                ->addColumn('setting_key', 'string', [
                    'limit' => 64,
                    'null' => false,
                    'comment' => 'ai_enabled | ai_model',
                ])
                ->addColumn('setting_value', 'string', ['limit' => 255, 'null' => true, 'default' => null])
                ->addColumn('updated_at', 'timestamp', [
                    'default' => 'CURRENT_TIMESTAMP',
                    'update' => 'CURRENT_TIMESTAMP',
                ])
                ->create();
        }
    }

    public function down(): void
    {
        foreach (['ai_settings', 'prompt_publications', 'prompt_versions'] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop()->save();
            }
        }
    }
}
