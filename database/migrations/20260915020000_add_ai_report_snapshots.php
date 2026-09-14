<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Неизменяемый снимок задания ИИ-разбора (аудит R2).
 *
 * Номер версии промпта не описывает реально отправленный запрос: между
 * постановкой и обработкой владелец может опубликовать другую версию, а
 * результат сессии — измениться. Поэтому при постановке сохраняется ровно то,
 * что уйдёт провайдеру: разрешённый структурированный контекст модуля и текст
 * промпта со всеми полями, нужными вызову.
 *
 * Снимок — те же данные, что и так передаются наружу, ничего сверх; он живёт и
 * удаляется вместе со строкой `ai_reports`.
 */
final class AddAiReportSnapshots extends AbstractMigration
{
    /** MEDIUMTEXT: парный контекст СМИЛ/Лазаруса заметно больше 64 КБ TEXT. */
    private const MEDIUM_TEXT = 16777215;

    public function up(): void
    {
        if (!$this->hasTable('ai_reports')) {
            return;
        }

        $table = $this->table('ai_reports');

        if (!$table->hasColumn('context_snapshot')) {
            $table->addColumn('context_snapshot', 'text', [
                'limit' => self::MEDIUM_TEXT,
                'null' => true,
                'after' => 'prompt_version',
                'comment' => 'JSON разрешённого контекста модуля на момент постановки',
            ]);
        }

        if (!$table->hasColumn('prompt_snapshot')) {
            $table->addColumn('prompt_snapshot', 'text', [
                'limit' => self::MEDIUM_TEXT,
                'null' => true,
                'after' => 'context_snapshot',
                'comment' => 'JSON промпта на момент постановки: ключ, версия и текст',
            ]);
        }

        $table->update();
    }

    public function down(): void
    {
        if (!$this->hasTable('ai_reports')) {
            return;
        }

        $table = $this->table('ai_reports');

        foreach (['prompt_snapshot', 'context_snapshot'] as $column) {
            if ($table->hasColumn($column)) {
                $table->removeColumn($column);
            }
        }

        $table->update();
    }
}
