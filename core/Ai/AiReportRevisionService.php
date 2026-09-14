<?php

declare(strict_types=1);

namespace PsyTest\Core\Ai;

use PsyTest\Core\Database;
use PsyTest\Core\RetentionPolicy;
use Ramsey\Uuid\Uuid;

/**
 * История версий разбора и публикация одобренной редакции клиенту (D-054).
 *
 * Черновик модели не показывается клиенту специалиста ни при каких условиях
 * (AGENTS, PRODUCT_RULES §4). Специалист правит понятный разбор здесь, каждая
 * правка ложится отдельной неизменяемой ревизией, и только явная публикация
 * делает одну из них видимой на странице результата клиента и в его PDF.
 *
 * Ревизии не изменяются и не удаляются по одной: восстановление старой версии —
 * это новая ревизия с её текстом, а не откат записи. Так у специалиста всегда
 * остаётся доказуемая история того, что видел клиент.
 */
final class AiReportRevisionService
{
    public const SOURCE_AI = 'ai';
    public const SOURCE_OWNER = 'owner';

    /** Разбор длиннее этого не редактируется: это уже не отчёт, а вставка чужого файла. */
    public const CONTENT_MAX_LENGTH = 200000;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Все версии разбора, от первой к последней.
     *
     * @return list<array<string, mixed>>
     */
    public function revisions(string $reportId): array
    {
        return $this->db->select(
            'SELECT id, report_id, revision_no, content, source, created_at
             FROM ai_report_revisions WHERE report_id = ? ORDER BY revision_no',
            [$reportId],
        );
    }

    /** @return array<string, mixed>|null */
    public function revision(string $reportId, string $revisionId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM ai_report_revisions WHERE id = ? AND report_id = ?',
            [$revisionId, $reportId],
        );
    }

    /** @return array<string, mixed>|null */
    public function latest(string $reportId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM ai_report_revisions WHERE report_id = ? ORDER BY revision_no DESC LIMIT 1',
            [$reportId],
        );
    }

    /**
     * Ревизия №1 из готового черновика модели.
     *
     * Вызывается при переходе задания в `ready` и при первом открытии
     * редактора для старых готовых отчётов, поставленных до этого пакета.
     * Идемпотентно: если у отчёта уже есть хоть одна ревизия, ничего не делает.
     */
    public function seedFromContent(string $reportId, string $content): ?string
    {
        if (trim($content) === '' || $this->latest($reportId) !== null) {
            return null;
        }

        return $this->append($reportId, $content, self::SOURCE_AI, 1);
    }

    /**
     * Сохранить правку специалиста как новую версию.
     *
     * @throws \InvalidArgumentException пустой или слишком длинный текст.
     */
    public function save(string $reportId, string $markdown): string
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            throw new \InvalidArgumentException('Текст разбора не может быть пустым.');
        }
        if (mb_strlen($markdown) > self::CONTENT_MAX_LENGTH) {
            throw new \InvalidArgumentException('Текст разбора слишком длинный.');
        }

        $latest = $this->latest($reportId);

        return $this->append($reportId, $markdown, self::SOURCE_OWNER, ((int) ($latest['revision_no'] ?? 0)) + 1);
    }

    /**
     * Вернуть текст старой версии как новую.
     *
     * Старая ревизия при этом не трогается: история остаётся полной, а
     * «последняя версия» всегда означает то, что специалист выбрал последним.
     */
    public function restore(string $reportId, string $revisionId): ?string
    {
        $revision = $this->revision($reportId, $revisionId);

        return $revision === null ? null : $this->save($reportId, (string) $revision['content']);
    }

    /**
     * Опубликовать версию клиенту.
     *
     * Публикуется только понятный разбор и только по кейсу клиента
     * специалиста: профессиональное заключение адресовано специалисту
     * (PRODUCT_RULES §4), а у анонимного посетителя нет специалиста, который
     * что-либо одобряет.
     */
    public function publish(string $reportId, string $revisionId): bool
    {
        if (!$this->isPublishable($reportId) || $this->revision($reportId, $revisionId) === null) {
            return false;
        }

        return $this->db->update('ai_reports', [
            'published_revision_id' => $revisionId,
            'published_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$reportId]) >= 0;
    }

    public function unpublish(string $reportId): bool
    {
        return $this->db->update('ai_reports', [
            'published_revision_id' => null,
            'published_at' => null,
        ], 'id = ?', [$reportId]) >= 0;
    }

    /**
     * Опубликованная версия разбора по отчёту вместе с датой публикации.
     *
     * @return array{content: string, published_at: string, revision_no: int}|null
     */
    public function published(string $reportId): ?array
    {
        $row = $this->db->selectOne(
            'SELECT revisions.content, revisions.revision_no, reports.published_at
             FROM ai_reports AS reports
             INNER JOIN ai_report_revisions AS revisions ON revisions.id = reports.published_revision_id
             WHERE reports.id = ?',
            [$reportId],
        );

        return $row === null ? null : [
            'content' => (string) $row['content'],
            'published_at' => (string) $row['published_at'],
            'revision_no' => (int) $row['revision_no'],
        ];
    }

    /**
     * Markdown одобренного понятного разбора для страницы результата клиента.
     *
     * Ищется именно опубликованная ревизия, а не последняя и не `content`
     * отчёта: незаконченная правка специалиста не должна попасть клиенту.
     *
     * @return array{content: string, published_at: string, revision_no: int}|null
     */
    public function publishedContent(string $sessionId): ?array
    {
        $row = $this->db->selectOne(
            'SELECT revisions.content, revisions.revision_no, reports.published_at
             FROM ai_reports AS reports
             INNER JOIN ai_report_revisions AS revisions ON revisions.id = reports.published_revision_id
             INNER JOIN test_sessions AS sessions ON sessions.id = reports.session_id
             WHERE reports.session_id = ?
               AND reports.report_kind = ?
               AND sessions.retention_class = ?
             ORDER BY reports.published_at DESC LIMIT 1',
            [$sessionId, Prompt::KIND_CLEAR, RetentionPolicy::THERAPIST_CASE],
        );

        return $row === null ? null : [
            'content' => (string) $row['content'],
            'published_at' => (string) $row['published_at'],
            'revision_no' => (int) $row['revision_no'],
        ];
    }

    private function isPublishable(string $reportId): bool
    {
        $row = $this->db->selectOne(
            'SELECT reports.report_kind, sessions.retention_class
             FROM ai_reports AS reports
             INNER JOIN test_sessions AS sessions ON sessions.id = reports.session_id
             WHERE reports.id = ?',
            [$reportId],
        );

        return $row !== null
            && (string) $row['report_kind'] === Prompt::KIND_CLEAR
            && (string) $row['retention_class'] === RetentionPolicy::THERAPIST_CASE;
    }

    private function append(string $reportId, string $content, string $source, int $revisionNo): string
    {
        $id = Uuid::uuid4()->toString();
        $this->db->insert('ai_report_revisions', [
            'id' => $id,
            'report_id' => $reportId,
            'revision_no' => $revisionNo,
            'content' => $content,
            'source' => $source,
        ]);

        return $id;
    }
}
