<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Переключатели ИИ-разбора по методикам (07.WP10, D-056).
 *
 * `report_enabled` — открыт ли разбор для методики вообще; `send_item_answers`
 * — уходят ли модели ответы по каждому пункту. Строки нет — методика выключена:
 * ничего наружу не уходит, пока владелец явно не включил разбор.
 *
 * СМИЛ и Лазарус включаются сразу: у них уже есть опубликованные промпты, и
 * без этой строки пропал бы работающий заказ. Ответы по пунктам для них
 * выключены — их нынешний контекст не меняется.
 */
final class AddAiTestSettings extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('ai_test_settings')) {
            return;
        }

        $this->table('ai_test_settings', ['id' => false, 'primary_key' => ['test_slug']])
            ->addColumn('test_slug', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('report_enabled', 'boolean', [
                'null' => false,
                'default' => false,
                'comment' => 'ИИ-разбор для методики открыт владельцем',
            ])
            ->addColumn('send_item_answers', 'boolean', [
                'null' => false,
                'default' => false,
                'comment' => 'Передавать модели ответы по пунктам',
            ])
            ->addColumn('updated_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'update' => 'CURRENT_TIMESTAMP',
            ])
            ->create();

        $this->execute(
            "INSERT INTO ai_test_settings (test_slug, report_enabled, send_item_answers)
             VALUES ('smil', 1, 0), ('lazarus', 1, 0)",
        );
    }

    public function down(): void
    {
        if ($this->hasTable('ai_test_settings')) {
            $this->table('ai_test_settings')->drop()->save();
        }
    }
}
