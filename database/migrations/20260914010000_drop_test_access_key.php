<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Снятие общего ключа доступа к закрытой методике.
 *
 * Решение владельца 14.09.2026: закрытая методика открывается только личным
 * одноразовым приглашением из кабинета (`test_invites`), а не общим ключом
 * в адресе. Ключ был временной затычкой: его негде хранить, перевыпуск требовал
 * SSH, и он не отвечал на вопрос, кто именно прошёл методику.
 *
 * Колонка `visibility` остаётся: она управляет каталогом, и разделение
 * public / invite признано правильным.
 */
final class DropTestAccessKey extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('tests');

        if ($table->hasColumn('access_key')) {
            $table->removeColumn('access_key')->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('tests');

        if (!$table->hasColumn('access_key')) {
            $table->addColumn('access_key', 'string', [
                'limit' => 64,
                'null' => true,
                'default' => null,
                'comment' => 'Исторический ключ доступа; не заполняется',
                'after' => 'visibility',
            ])->update();
        }
    }
}
