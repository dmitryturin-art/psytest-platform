<?php

declare(strict_types=1);

namespace PsyTest\Core\Ai;

use PsyTest\Core\Database;
use Ramsey\Uuid\Uuid;

/**
 * Очередь пробных разборов владельца (07.K14a).
 *
 * Пробный разбор проверяет формулировку промпта на синтетическом кейсе методики.
 * В строке нет ни сессии, ни клиента: только снимок промпта и выдуманный
 * контекст. Результат хранится час, чтобы страница могла его показать после
 * ожидания, и стирается кнопкой «Закрыть» или ночной очисткой.
 */
final class AiTrialRepository
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    public const TTL_MINUTES = 60;

    /** Обработчик, не вернувшийся за это время, считается упавшим. */
    public const STUCK_MINUTES = 10;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Поставить пробный разбор; прежний итог этого ключа заменяется новым.
     *
     * @param array<string, mixed> $context Синтетический контекст, ровно то, что уйдёт провайдеру.
     *
     * @return array<string, mixed>
     */
    public function request(string $test, string $mode, string $kind, Prompt $prompt, array $context): array
    {
        $this->db->execute(
            'DELETE FROM ai_trial_runs WHERE test_slug = ? AND mode = ? AND report_kind = ? AND status IN (?, ?)',
            [$test, $mode, $kind, self::STATUS_READY, self::STATUS_FAILED],
        );

        $id = Uuid::uuid4()->toString();
        $this->db->insert('ai_trial_runs', [
            'id' => $id,
            'test_slug' => $test,
            'mode' => $mode,
            'report_kind' => $kind,
            'prompt_version' => $prompt->version,
            'status' => self::STATUS_PENDING,
            'prompt_snapshot' => json_encode($prompt->toSnapshot(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'context_snapshot' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'expires_at' => date('Y-m-d H:i:s'),
        ]);
        // Срок считает база: часовой пояс PHP и MySQL может различаться.
        $this->db->execute('UPDATE ai_trial_runs SET expires_at = NOW() + INTERVAL ? MINUTE WHERE id = ?', [self::TTL_MINUTES, $id]);

        return (array) $this->find($id);
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM ai_trial_runs WHERE id = ?', [$id]);
    }

    /**
     * Последний неистёкший пробный разбор ключа.
     *
     * @return array<string, mixed>|null
     */
    public function latestFor(string $test, string $mode, string $kind): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM ai_trial_runs
             WHERE test_slug = ? AND mode = ? AND report_kind = ? AND expires_at > NOW()
             ORDER BY created_at DESC, id LIMIT 1',
            [$test, $mode, $kind],
        );
    }

    public function hasActive(string $test, string $mode, string $kind): bool
    {
        $row = $this->latestFor($test, $mode, $kind);

        return $row !== null && in_array($row['status'], [self::STATUS_PENDING, self::STATUS_RUNNING], true);
    }

    /** @return array<string, mixed>|null */
    public function claimNext(): ?array
    {
        $candidate = $this->db->selectOne(
            'SELECT id FROM ai_trial_runs WHERE status = ? AND expires_at > NOW() ORDER BY created_at LIMIT 1',
            [self::STATUS_PENDING],
        );
        if ($candidate === null) {
            return null;
        }

        $claimed = $this->db->execute(
            'UPDATE ai_trial_runs SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = ?',
            [self::STATUS_RUNNING, $candidate['id'], self::STATUS_PENDING],
        );

        return $claimed->rowCount() === 1 ? $this->find((string) $candidate['id']) : null;
    }

    public function markReady(string $id, AiCompletion $completion): void
    {
        $this->db->update('ai_trial_runs', [
            'status' => self::STATUS_READY,
            'content' => $completion->text,
            'served_model' => mb_substr($completion->servedModel, 0, 128),
            'failure_reason' => null,
        ], 'id = ?', [$id]);
        $this->stamp($id);
    }

    public function markFailed(string $id, string $reason): void
    {
        $this->db->update('ai_trial_runs', [
            'status' => self::STATUS_FAILED,
            'failure_reason' => mb_substr($reason, 0, 255),
        ], 'id = ?', [$id]);
        $this->stamp($id);
    }

    /** Время окончания ставит база: все метки строки в одном часовом поясе. */
    private function stamp(string $id): void
    {
        $this->db->execute('UPDATE ai_trial_runs SET completed_at = NOW() WHERE id = ?', [$id]);
    }

    public function delete(string $id): void
    {
        $this->db->execute('DELETE FROM ai_trial_runs WHERE id = ?', [$id]);
    }

    /** Пробные запуски, не дошедшие до конца, закрываются как неудавшиеся. */
    public function releaseStuck(): int
    {
        return $this->db->execute(
            'UPDATE ai_trial_runs SET status = ?, failure_reason = ?, completed_at = NOW()
             WHERE status IN (?, ?) AND updated_at < (NOW() - INTERVAL ? MINUTE)',
            [
                self::STATUS_FAILED,
                'Обработчик не завершил пробный разбор. Запустите его ещё раз.',
                self::STATUS_PENDING,
                self::STATUS_RUNNING,
                self::STUCK_MINUTES,
            ],
        )->rowCount();
    }

    /** Ночная очистка: истёкшие строки стираются. */
    public function purgeExpired(): int
    {
        return $this->db->execute('DELETE FROM ai_trial_runs WHERE expires_at <= NOW()')->rowCount();
    }
}
