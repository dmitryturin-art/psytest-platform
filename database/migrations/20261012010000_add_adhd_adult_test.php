<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * «Тест на СДВГ у взрослых» в каталоге (09.A1).
 *
 * Авторский опросник PsyTest, чужих текстов в нём нет. ИИ-разбор не включается:
 * строки в `ai_test_settings` нет — значит, выключено, пока владелец не поставит
 * галочку в «Методиках».
 */
final class AddAdhdAdultTest extends AbstractMigration
{
    public function up(): void
    {
        $exists = $this->fetchRow("SELECT id FROM `tests` WHERE `slug` = 'adhd-adult'");
        if (!$exists) {
            $this->table('tests')->insert([
                'name' => 'Тест на СДВГ у взрослых',
                'slug' => 'adhd-adult',
                'module_class' => 'PsyTest\\Modules\\AdhdAdult\\AdhdAdultModule',
                'description' => 'Опросник о трудностях внимания, самоорганизации, гиперактивности и импульсивности у взрослых. 21 вопрос, около 5 минут. Показывает, набирается ли число признаков, при котором стоит обратиться к специалисту. Не ставит диагноз.',
                'is_active' => 1,
                // Открыт в каталоге сразу — решение владельца 12.10 (D-083).
                'visibility' => 'public',
                'sort_order' => 7,
            ])->saveData();
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM `tests` WHERE `slug` = 'adhd-adult'");
    }
}
