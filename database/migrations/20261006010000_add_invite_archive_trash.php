<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Архив и корзина приглашений владельца (07.K8).
 *
 * `archived_at` убирает завершённое назначение из рабочего списка, ничего не
 * удаляя. `trashed_at` — мягкое удаление: через 30 дней ежедневная очистка
 * физически стирает кейс (ответы, результат, разборы, выгрузки). Клиентские
 * ссылки и кабинет посетителя от обеих отметок не зависят.
 */
final class AddInviteArchiveTrash extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('test_invites');
        if (!$table->hasColumn('archived_at')) {
            $table->addColumn('archived_at', 'datetime', ['null' => true, 'default' => null]);
        }
        if (!$table->hasColumn('trashed_at')) {
            $table->addColumn('trashed_at', 'datetime', ['null' => true, 'default' => null]);
        }
        $table->save();

        $table = $this->table('test_invites');
        if (!$table->hasIndexByName('idx_test_invites_archived')) {
            $table->addIndex(['archived_at'], ['name' => 'idx_test_invites_archived']);
        }
        if (!$table->hasIndexByName('idx_test_invites_trashed')) {
            $table->addIndex(['trashed_at'], ['name' => 'idx_test_invites_trashed']);
        }
        $table->save();
    }

    public function down(): void
    {
        $table = $this->table('test_invites');
        if ($table->hasIndexByName('idx_test_invites_archived')) {
            $table->removeIndexByName('idx_test_invites_archived');
        }
        if ($table->hasIndexByName('idx_test_invites_trashed')) {
            $table->removeIndexByName('idx_test_invites_trashed');
        }
        $table->save();

        $table = $this->table('test_invites');
        if ($table->hasColumn('archived_at')) {
            $table->removeColumn('archived_at');
        }
        if ($table->hasColumn('trashed_at')) {
            $table->removeColumn('trashed_at');
        }
        $table->save();
    }
}
