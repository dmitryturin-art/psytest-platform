<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Корзина карточек клиентов (07.K11).
 *
 * `therapist_clients.trashed_at` — мягкое удаление карточки: назначения
 * переезжают в корзину вместе с ней, через 30 дней ежедневная очистка стирает
 * карточку со всеми данными. Пока метка стоит, карточка доступна только для
 * чтения, восстановления и окончательного удаления.
 */
final class AddClientTrash extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('therapist_clients');
        if (!$table->hasColumn('trashed_at')) {
            $table->addColumn('trashed_at', 'datetime', ['null' => true, 'default' => null]);
            $table->save();
        }

        $table = $this->table('therapist_clients');
        if (!$table->hasIndexByName('idx_therapist_clients_trashed')) {
            $table->addIndex(['trashed_at'], ['name' => 'idx_therapist_clients_trashed']);
            $table->save();
        }
    }

    public function down(): void
    {
        $table = $this->table('therapist_clients');
        if ($table->hasIndexByName('idx_therapist_clients_trashed')) {
            $table->removeIndexByName('idx_therapist_clients_trashed');
            $table->save();
        }

        $table = $this->table('therapist_clients');
        if ($table->hasColumn('trashed_at')) {
            $table->removeColumn('trashed_at');
            $table->save();
        }
    }
}
