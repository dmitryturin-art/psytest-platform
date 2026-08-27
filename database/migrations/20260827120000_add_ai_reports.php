<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Хранение ИИ-разборов.
 *
 * Разбор — клинический документ, построенный на результате сессии, поэтому он
 * живёт по тем же правилам хранения: внешний ключ с каскадным удалением
 * гарантирует, что удаление кейса в кабинете уносит и разборы, а не оставляет
 * их сиротами в базе.
 */
final class AddAiReports extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('ai_reports')) {
            return;
        }

        $this->table('ai_reports', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false, 'comment' => 'UUID'])
            ->addColumn('session_id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('test_slug', 'string', ['limit' => 100])
            ->addColumn('mode', 'string', ['limit' => 32, 'comment' => 'individual | pair'])
            ->addColumn('report_kind', 'string', ['limit' => 32, 'comment' => 'professional | clear'])
            ->addColumn('prompt_key', 'string', ['limit' => 128])
            ->addColumn('prompt_version', 'integer', ['signed' => false])
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'pending', 'comment' => 'pending | running | ready | failed'])
            ->addColumn('requested_model', 'string', ['limit' => 128, 'null' => true])
            ->addColumn('served_model', 'string', ['limit' => 128, 'null' => true, 'comment' => 'Модель, фактически ответившая'])
            ->addColumn('content', 'text', ['limit' => 16777215, 'null' => true, 'comment' => 'Markdown-текст разбора'])
            ->addColumn('owner_context', 'text', ['null' => true, 'comment' => 'Клинический контекст, введённый специалистом'])
            ->addColumn('failure_reason', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('attempts', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('prompt_tokens', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('completion_tokens', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addColumn('completed_at', 'timestamp', ['null' => true, 'default' => null])
            ->addIndex(['session_id'])
            ->addIndex(['status'])
            // Один разбор на сочетание сессии, режима и вида отчёта:
            // повторный запрос переиспользует запись, а не плодит дубли.
            ->addIndex(['session_id', 'mode', 'report_kind'], ['unique' => true, 'name' => 'uq_report_per_session'])
            ->addForeignKey('session_id', 'test_sessions', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_reports')) {
            $this->table('ai_reports')->drop()->save();
        }
    }
}
