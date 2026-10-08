<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * Пробные разборы промптов (07.K14a).
 *
 * Пробный разбор владельца идёт тем же фоновым обработчиком, что и обычные
 * разборы, но не связан ни с сессией, ни с клиентом: во входе только
 * синтетический кейс методики. Строка живёт час и удаляется кнопкой
 * «Закрыть» либо ночной очисткой.
 */
final class AddAiTrialRuns extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('ai_trial_runs')) {
            return;
        }

        $this->table('ai_trial_runs', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('test_slug', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('mode', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('report_kind', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('prompt_version', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('status', 'string', ['limit' => 16, 'null' => false, 'default' => 'pending'])
            ->addColumn('prompt_snapshot', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => false])
            ->addColumn('context_snapshot', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => false])
            ->addColumn('content', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => true])
            ->addColumn('served_model', 'string', ['limit' => 128, 'null' => true])
            ->addColumn('failure_reason', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addColumn('completed_at', 'datetime', ['null' => true])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addIndex(['test_slug', 'mode', 'report_kind'], ['name' => 'idx_trial_key'])
            ->addIndex(['status', 'created_at'], ['name' => 'idx_trial_queue'])
            ->addIndex(['expires_at'], ['name' => 'idx_trial_expires'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_trial_runs')) {
            $this->table('ai_trial_runs')->drop()->save();
        }
    }
}
