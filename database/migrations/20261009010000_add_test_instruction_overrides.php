<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * Инструкция респонденту, изменённая из кабинета (07.K15).
 *
 * Файл `modules/<методика>/metadata.json` остаётся исходным состоянием в Git,
 * правка владельца ложится в БД поверх него: есть строка — показывается она,
 * нет строки — инструкция из файла. `paragraphs` — JSON-список абзацев простым
 * текстом.
 */
final class AddTestInstructionOverrides extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('test_instruction_overrides')) {
            return;
        }

        $this->table('test_instruction_overrides', ['id' => false, 'primary_key' => ['test_slug']])
            ->addColumn('test_slug', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('paragraphs', 'text', [
                'limit' => MysqlAdapter::TEXT_MEDIUM,
                'null' => false,
                'comment' => 'JSON-список абзацев инструкции (простой текст)',
            ])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('test_instruction_overrides')) {
            $this->table('test_instruction_overrides')->drop()->save();
        }
    }
}
