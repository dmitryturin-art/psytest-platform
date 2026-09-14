<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Необязательный email клиента в карточке и отметка отправленного уведомления (D-054).
 *
 * До этого пакета карточка не хранила контактов вовсе. Теперь специалист может
 * сам вписать адрес — и только ради одного короткого письма «разбор готов».
 * Ничего не отправляется автоматически, адрес не попадает в AI-контекст,
 * `activity_log`, ссылку-приглашение и на страницу респондента, а удаление
 * карточки уносит его вместе с ней.
 *
 * `client_notified_at` хранится у разбора, а не у карточки: это отметка «по
 * этому разбору специалист уже уведомлял», она же держит лимит повторов.
 *
 * VARCHAR(254) — предельная длина адреса по RFC 5321; NULL означает «адреса
 * нет», пустая строка в колонку не пишется.
 */
final class AddClientEmail extends AbstractMigration
{
    public function up(): void
    {
        $clients = $this->table('therapist_clients');
        if (!$clients->hasColumn('email')) {
            $clients->addColumn('email', 'string', [
                'limit' => 254,
                'null' => true,
                'default' => null,
                'comment' => 'Адрес для уведомления о готовом разборе; заполняется специалистом вручную',
            ])->update();
        }

        if ($this->hasTable('ai_reports')) {
            $reports = $this->table('ai_reports');
            if (!$reports->hasColumn('client_notified_at')) {
                $reports->addColumn('client_notified_at', 'timestamp', [
                    'null' => true,
                    'default' => null,
                    'comment' => 'Когда специалист в последний раз отправил клиенту уведомление об этом разборе',
                ])->update();
            }
        }
    }

    public function down(): void
    {
        if ($this->hasTable('ai_reports')) {
            $reports = $this->table('ai_reports');
            if ($reports->hasColumn('client_notified_at')) {
                $reports->removeColumn('client_notified_at')->update();
            }
        }

        $clients = $this->table('therapist_clients');
        if ($clients->hasColumn('email')) {
            $clients->removeColumn('email')->update();
        }
    }
}
