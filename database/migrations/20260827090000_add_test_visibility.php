<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Видимость методики: публичная или только по ссылке-приглашению.
 *
 * Нужна, чтобы закрыть публичное распространение текстов методик, права на
 * которые не подтверждены, не убирая их из платформы: владелец продолжает
 * давать их своим клиентам по ссылке.
 */
final class AddTestVisibility extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('tests');

        if (!$table->hasColumn('visibility')) {
            $table->addColumn('visibility', 'string', [
                'limit' => 16,
                'default' => 'public',
                'null' => false,
                'comment' => 'public — виден в каталоге; invite — только по ссылке с ключом',
                'after' => 'is_active',
            ])->update();
        }

        if (!$table->hasColumn('access_key')) {
            $table->addColumn('access_key', 'string', [
                'limit' => 64,
                'null' => true,
                'default' => null,
                'comment' => 'Ключ доступа для visibility = invite',
                'after' => 'visibility',
            ])->update();
        }

        // СМИЛ закрывается: права на русскую адаптацию не подтверждены,
        // а публикация 566 формулировок — это распространение адаптации.
        $existing = $this->fetchRow("SELECT id FROM `tests` WHERE `slug` = 'smil'");
        if ($existing) {
            $key = bin2hex(random_bytes(24));
            $this->execute(sprintf(
                "UPDATE `tests` SET `visibility` = 'invite', `access_key` = '%s' WHERE `slug` = 'smil' AND (`access_key` IS NULL OR `access_key` = '')",
                $key,
            ));
        }
    }

    public function down(): void
    {
        $table = $this->table('tests');

        if ($table->hasColumn('access_key')) {
            $table->removeColumn('access_key')->update();
        }
        if ($table->hasColumn('visibility')) {
            $table->removeColumn('visibility')->update();
        }
    }
}
