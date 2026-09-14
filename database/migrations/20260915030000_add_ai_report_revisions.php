<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * История версий понятного разбора и отметка опубликованной версии (D-054).
 *
 * Клиент специалиста видит не черновик модели, а ту редакцию, которую
 * специалист сам проверил и опубликовал. Чтобы это было проверяемо, правки не
 * перезаписывают текст: каждая становится отдельной неизменяемой ревизией, а
 * `published_revision_id` указывает, какая именно из них показывается клиенту.
 *
 * Ревизии живут и удаляются вместе с отчётом: каскад от `ai_reports` уносит
 * их, а `ai_reports` в свою очередь каскадно удаляется вместе с сессией.
 */
final class AddAiReportRevisions extends AbstractMigration
{
    /** MEDIUMTEXT: разбор с таблицами заметно длиннее 64 КБ TEXT. */
    private const MEDIUM_TEXT = 16777215;

    public function up(): void
    {
        if (!$this->hasTable('ai_reports')) {
            return;
        }

        if (!$this->hasTable('ai_report_revisions')) {
            $this->table('ai_report_revisions', ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'char', ['limit' => 36, 'null' => false, 'comment' => 'UUID'])
                ->addColumn('report_id', 'char', ['limit' => 36, 'null' => false])
                ->addColumn('revision_no', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('content', 'text', [
                    'limit' => self::MEDIUM_TEXT,
                    'null' => false,
                    'comment' => 'Markdown этой версии разбора',
                ])
                ->addColumn('source', 'enum', [
                    'values' => ['ai', 'owner'],
                    'null' => false,
                    'comment' => 'ai — исходный черновик модели, owner — правка специалиста',
                ])
                ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['report_id', 'revision_no'], ['unique' => true, 'name' => 'uq_report_revision_no'])
                ->addForeignKey('report_id', 'ai_reports', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
                ->create();
        }

        $reports = $this->table('ai_reports');

        if (!$reports->hasColumn('published_revision_id')) {
            $reports->addColumn('published_revision_id', 'char', [
                'limit' => 36,
                'null' => true,
                'default' => null,
                'comment' => 'Версия, которую видит клиент специалиста',
            ]);
        }

        if (!$reports->hasColumn('published_at')) {
            $reports->addColumn('published_at', 'timestamp', ['null' => true, 'default' => null]);
        }

        $reports->update();

        if (!$reports->hasForeignKey('published_revision_id')) {
            // SET NULL, а не CASCADE: снятие ревизии не должно уносить сам
            // отчёт — публикация просто перестаёт существовать.
            $this->table('ai_reports')
                ->addForeignKey('published_revision_id', 'ai_report_revisions', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_ai_reports_published_revision',
                ])
                ->update();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('ai_reports')) {
            $reports = $this->table('ai_reports');

            if ($reports->hasForeignKey('published_revision_id')) {
                $reports->dropForeignKey('published_revision_id')->update();
            }

            foreach (['published_at', 'published_revision_id'] as $column) {
                if ($reports->hasColumn($column)) {
                    $reports->removeColumn($column);
                }
            }

            $reports->update();
        }

        if ($this->hasTable('ai_report_revisions')) {
            $this->table('ai_report_revisions')->drop()->save();
        }
    }
}
