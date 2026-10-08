<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * IPIP-NEO-120 в каталоге (09.O1).
 *
 * Методика открытая (IPIP — public domain), поэтому публичная и активная
 * с первого дня. ИИ-разбор для неё не включается: строки в `ai_test_settings`
 * нет — значит, выключено, пока владелец не поставит галочку в «Методиках».
 */
final class AddIpipNeo120Test extends AbstractMigration
{
    public function up(): void
    {
        $exists = $this->fetchRow("SELECT id FROM `tests` WHERE `slug` = 'ipip-neo-120'");
        if (!$exists) {
            $this->table('tests')->insert([
                'name' => 'Опросник «Большая пятёрка» IPIP-NEO-120',
                'slug' => 'ipip-neo-120',
                'module_class' => 'PsyTest\\Modules\\IpipNeo120\\IpipNeo120Module',
                'description' => 'Пять основных черт личности и 30 их граней, сравнение с международной выборкой по полу и возрасту. 120 утверждений, 15–20 минут.',
                'is_active' => 1,
                'sort_order' => 6,
            ])->saveData();
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM `tests` WHERE `slug` = 'ipip-neo-120'");
    }
}
