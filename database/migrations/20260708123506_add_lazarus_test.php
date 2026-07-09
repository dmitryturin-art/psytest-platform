<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Registers the Lazarus Marital Satisfaction Questionnaire test.
 * Idempotent — skips if slug 'lazarus' already exists.
 */
final class AddLazarusTest extends AbstractMigration
{
    public function up(): void
    {
        $exists = $this->fetchRow("SELECT id FROM `tests` WHERE `slug` = 'lazarus'");
        if (!$exists) {
            $this->table('tests')->insert([
                'name'         => 'Опросник супружеской удовлетворённости (Лазарус)',
                'slug'         => 'lazarus',
                'module_class' => 'PsyTest\\Modules\\Lazarus\\LazarusModule',
                'description'  => 'Опросник супружеской удовлетворённости (А. Лазарус, 1997). 16 пунктов, двойная оценка (Я + партнёр), парный режим.',
                'is_active'    => 1,
                'sort_order'   => 5,
            ])->saveData();
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM `tests` WHERE `slug` = 'lazarus'");
    }
}
