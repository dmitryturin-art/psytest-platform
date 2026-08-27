<?php

declare(strict_types=1);

namespace PsyTest\Core\Ai;

use PsyTest\Core\Database;
use Ramsey\Uuid\Uuid;

/**
 * Хранение заданий на ИИ-разбор и их результатов.
 *
 * Разбор делается не в веб-запросе: замеры дали 86–130 секунд на отчёт,
 * столько посетитель ждать не может и соединение всё равно оборвётся.
 * Поэтому запрос лишь ставит задание, а выполняет его фоновый обработчик.
 */
final class AiReportRepository
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    /** Больше этого числа попыток задание не берётся: провайдер отвечает отказом устойчиво. */
    public const MAX_ATTEMPTS = 3;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Поставить задание или вернуть уже существующее.
     *
     * Повторное нажатие кнопки не должно ни плодить записи, ни перезапускать
     * готовый отчёт — за это отвечает уникальный индекс по сессии, режиму и виду.
     *
     * @return array<string, mixed>
     */
    public function request(
        string $sessionId,
        string $testSlug,
        string $mode,
        string $reportKind,
        Prompt $prompt,
        ?string $ownerContext = null,
    ): array {
        $existing = $this->findFor($sessionId, $mode, $reportKind);

        if ($existing !== null) {
            // Неудавшееся задание можно попросить заново, пока не исчерпаны попытки.
            if ($existing['status'] === self::STATUS_FAILED && (int) $existing['attempts'] < self::MAX_ATTEMPTS) {
                $this->db->update(
                    'ai_reports',
                    ['status' => self::STATUS_PENDING, 'failure_reason' => null],
                    'id = ?',
                    [$existing['id']],
                );

                return (array) $this->find((string) $existing['id']);
            }

            return $existing;
        }

        $id = Uuid::uuid4()->toString();
        $this->db->insert('ai_reports', [
            'id' => $id,
            'session_id' => $sessionId,
            'test_slug' => $testSlug,
            'mode' => $mode,
            'report_kind' => $reportKind,
            'prompt_key' => $prompt->key(),
            'prompt_version' => $prompt->version,
            'status' => self::STATUS_PENDING,
            'owner_context' => $ownerContext,
        ]);

        return (array) $this->find($id);
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM ai_reports WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findFor(string $sessionId, string $mode, string $reportKind): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM ai_reports WHERE session_id = ? AND mode = ? AND report_kind = ?',
            [$sessionId, $mode, $reportKind],
        );
    }

    /**
     * Взять следующее задание в работу.
     *
     * Захват атомарный: статус меняется условием `WHERE status = pending`, и
     * задание достаётся тому обработчику, чей `UPDATE` затронул строку. Два
     * одновременных запуска cron не возьмут одну и ту же работу дважды.
     *
     * @return array<string, mixed>|null
     */
    public function claimNext(): ?array
    {
        $candidate = $this->db->selectOne(
            'SELECT id FROM ai_reports WHERE status = ? AND attempts < ? ORDER BY created_at LIMIT 1',
            [self::STATUS_PENDING, self::MAX_ATTEMPTS],
        );

        if ($candidate === null) {
            return null;
        }

        $claimed = $this->db->execute(
            'UPDATE ai_reports SET status = ?, attempts = attempts + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = ?',
            [self::STATUS_RUNNING, $candidate['id'], self::STATUS_PENDING],
        );

        return $claimed->rowCount() === 1 ? $this->find((string) $candidate['id']) : null;
    }

    public function markReady(string $id, AiCompletion $completion): void
    {
        $this->db->update('ai_reports', [
            'status' => self::STATUS_READY,
            'content' => $completion->text,
            'served_model' => $completion->servedModel,
            'requested_model' => $completion->requestedModel,
            'prompt_tokens' => $completion->promptTokens,
            'completion_tokens' => $completion->completionTokens,
            'failure_reason' => null,
            'completed_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);
    }

    public function markFailed(string $id, string $reason): void
    {
        $this->db->update('ai_reports', [
            'status' => self::STATUS_FAILED,
            // Причина показывается владельцу и попадает в логи, поэтому берётся
            // короткая формулировка адаптера, а не тело ответа провайдера.
            'failure_reason' => mb_substr($reason, 0, 255),
        ], 'id = ?', [$id]);
    }

    /**
     * Задания, застрявшие в работе дольше отведённого времени.
     *
     * Обработчик может умереть посреди вызова — например, его прервал хостинг.
     * Такое задание иначе останется `running` навсегда и никогда не повторится.
     *
     * @return list<array<string, mixed>>
     */
    public function releaseStuck(int $olderThanMinutes = 30): array
    {
        $stuck = $this->db->select(
            'SELECT id FROM ai_reports WHERE status = ? AND updated_at < (NOW() - INTERVAL ? MINUTE)',
            [self::STATUS_RUNNING, $olderThanMinutes],
        );

        foreach ($stuck as $row) {
            $this->db->update(
                'ai_reports',
                ['status' => self::STATUS_PENDING, 'failure_reason' => 'Обработчик не завершил задание и был перезапущен'],
                'id = ?',
                [$row['id']],
            );
        }

        return $stuck;
    }
}
