<?php

declare(strict_types=1);

namespace PsyTest\Core;

use Psr\Log\LoggerInterface;
use PsyTest\Modules\RefreshesAdditionalScores;
use PsyTest\Modules\TestModuleInterface;

/**
 * Единая точка обновления сохранённого результата при чтении (05.S4a).
 *
 * Результат считается один раз при завершении, а реестр дополнительных шкал
 * СМИЛ после этого вырос. Страница результата, PDF, кабинет, выгрузка кейса и
 * контекст ИИ получают сессию через этот сервис, поэтому видят один и тот же,
 * актуальный набор шкал.
 *
 * Модуль сам решает, что и когда пересчитывать (`RefreshesAdditionalScores`);
 * для остальных методик сервис ничего не делает. Обновлённый результат
 * записывается обратно один раз: при следующем чтении набор уже совпадает с
 * реестром и записи нет. Ошибка пересчёта не ломает показ — логируется, а
 * наружу уходит прежний результат.
 */
final class ResultRefresher
{
    public const UPDATED = 'updated';
    public const CURRENT = 'current';
    public const NO_ANSWERS = 'no_answers';
    public const NOT_APPLICABLE = 'not_applicable';
    public const FAILED = 'failed';

    public function __construct(
        private readonly Database $db,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Сессия с актуальным результатом (обновление записывается в БД).
     *
     * @param array<string, mixed> $session Сессия с уже декодированными `answers` и `calculated_results`.
     *
     * @return array<string, mixed>
     */
    public function refresh(array $session, TestModuleInterface $module): array
    {
        return $this->apply($session, $module)['session'];
    }

    /**
     * То же, что refresh(), но с исходом — для CLI и тестов.
     *
     * @param array<string, mixed> $session
     *
     * @return array{status: string, session: array<string, mixed>}
     */
    public function apply(array $session, TestModuleInterface $module, bool $persist = true): array
    {
        $results = $session['calculated_results'] ?? null;
        if (
            !$module instanceof RefreshesAdditionalScores
            || ($session['status'] ?? null) !== 'completed'
            || !is_array($results)
            || $results === []
        ) {
            return ['status' => self::NOT_APPLICABLE, 'session' => $session];
        }

        $answers = is_array($session['answers'] ?? null) ? $session['answers'] : [];
        if (!self::hasItemAnswers($answers)) {
            return ['status' => self::NO_ANSWERS, 'session' => $session];
        }

        try {
            $fresh = $module->refreshAdditionalScores($results, $answers);
            if ($fresh === $results) {
                return ['status' => self::CURRENT, 'session' => $session];
            }

            if ($persist) {
                $this->db->update(
                    'test_sessions',
                    ['calculated_results' => json_encode($fresh, JSON_THROW_ON_ERROR)],
                    'id = ? AND status = ?',
                    [(string) ($session['id'] ?? ''), 'completed'],
                );
            }
        } catch (\Throwable $exception) {
            // Ни идентификатор, ни данные сессии в лог не попадают.
            $this->logger()->error('stored result refresh failed: ' . $exception::class);

            return ['status' => self::FAILED, 'session' => $session];
        }

        $session['calculated_results'] = $fresh;

        return ['status' => self::UPDATED, 'session' => $session];
    }

    /**
     * Пройти все завершённые сессии методики и обновить устаревшие.
     *
     * Сессии читаются пачками по идентификатору, чтобы не держать в памяти
     * весь архив. Возвращаются только счётчики — без данных сессий.
     *
     * @return array{updated: int, current: int, no_answers: int, failed: int}
     */
    public function refreshAll(string $testSlug, TestModuleInterface $module, bool $dryRun = false, int $batchSize = 100): array
    {
        $counts = ['updated' => 0, 'current' => 0, 'no_answers' => 0, 'failed' => 0];
        $afterId = '';

        while (true) {
            $rows = $this->db->select(
                'SELECT sessions.id, sessions.status, sessions.answers, sessions.calculated_results
                 FROM test_sessions AS sessions
                 INNER JOIN tests ON tests.id = sessions.test_id
                 WHERE tests.slug = ? AND sessions.status = ? AND sessions.id > ?
                 ORDER BY sessions.id
                 LIMIT ' . max(1, $batchSize),
                [$testSlug, 'completed', $afterId],
            );
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $afterId = (string) $row['id'];
                try {
                    $row['answers'] = self::decode($row['answers'] ?? null);
                    $row['calculated_results'] = self::decode($row['calculated_results'] ?? null);
                } catch (\JsonException) {
                    $counts['failed']++;

                    continue;
                }

                $status = $this->apply($row, $module, !$dryRun)['status'];
                match ($status) {
                    self::UPDATED => $counts['updated']++,
                    self::NO_ANSWERS => $counts['no_answers']++,
                    self::FAILED => $counts['failed']++,
                    // Пустой результат пересчитывать нечем — как и без ответов.
                    self::NOT_APPLICABLE => $counts['no_answers']++,
                    default => $counts['current']++,
                };
            }
        }

        return $counts;
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function decode(mixed $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $data = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<int|string, mixed> $answers
     */
    private static function hasItemAnswers(array $answers): bool
    {
        foreach (array_keys($answers) as $key) {
            if (is_int($key) || ctype_digit((string) $key)) {
                return true;
            }
        }

        return false;
    }

    private function logger(): LoggerInterface
    {
        return $this->logger ?? LoggerFactory::getLogger('results');
    }
}
