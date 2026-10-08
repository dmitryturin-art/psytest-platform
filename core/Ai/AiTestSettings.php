<?php

declare(strict_types=1);

namespace PsyTest\Core\Ai;

use PsyTest\Core\Database;

/**
 * Переключатели ИИ-разбора по методикам (07.WP10, D-056).
 *
 * Две галочки владельца в «Промптах»:
 * - «ИИ-разбор для этой методики» — без неё разбор методики не заказывается
 *   нигде (см. {@see AiReportAvailability});
 * - «Передавать модели ответы по пунктам» — без неё контекст содержит только
 *   рассчитанные показатели; с ней к ним добавляются ответы на каждый пункт.
 *   Вторая галочка без первой не действует.
 *
 * Строки нет — методика выключена (fail-closed): в отличие от общего
 * выключателя {@see AiSettings}, это решение о том, что уходит наружу.
 * СМИЛ и Лазарус включены строкой миграции.
 *
 * В журнал пишется только сам факт смены и новые значения — без каких-либо
 * данных клиентов (PRODUCT_RULES §11).
 */
final class AiTestSettings
{
    public const AUDIT_ACTION = 'ai_test_settings_changed';

    /** @var array<string, array{report_enabled: bool, send_item_answers: bool}>|null */
    private ?array $cache = null;

    public function __construct(private readonly Database $db)
    {
    }

    public function isReportEnabled(string $test): bool
    {
        return $this->row($test)['report_enabled'];
    }

    /** Ответы по пунктам уходят, только когда разбор методики вообще включён. */
    public function sendsItemAnswers(string $test): bool
    {
        $row = $this->row($test);

        return $row['report_enabled'] && $row['send_item_answers'];
    }

    /**
     * Все сохранённые строки по методикам.
     *
     * @return array<string, array{report_enabled: bool, send_item_answers: bool}>
     */
    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach ($this->db->select('SELECT test_slug, report_enabled, send_item_answers FROM ai_test_settings') as $row) {
                $this->cache[(string) $row['test_slug']] = [
                    'report_enabled' => (bool) $row['report_enabled'],
                    'send_item_answers' => (bool) $row['send_item_answers'],
                ];
            }
        }

        return $this->cache;
    }

    /**
     * Сохранить обе галочки методики.
     *
     * Выключенный разбор сбрасывает и вторую галочку: иначе при следующем
     * включении ответы по пунктам ушли бы без нового явного решения.
     *
     * @return bool Изменилось ли что-нибудь.
     */
    public function save(string $test, bool $reportEnabled, bool $sendItemAnswers): bool
    {
        $sendItemAnswers = $reportEnabled && $sendItemAnswers;
        $before = $this->all()[$test] ?? null;
        // Строки нет — методика выключена: сохранение «выключено» ничего не меняет.
        $current = $before ?? ['report_enabled' => false, 'send_item_answers' => false];

        if ($current['report_enabled'] === $reportEnabled && $current['send_item_answers'] === $sendItemAnswers) {
            return false;
        }

        $values = [
            'report_enabled' => $reportEnabled ? 1 : 0,
            'send_item_answers' => $sendItemAnswers ? 1 : 0,
        ];

        $this->db->beginTransaction();
        try {
            if ($before === null) {
                $this->db->insert('ai_test_settings', ['test_slug' => $test] + $values);
            } else {
                $this->db->update('ai_test_settings', $values, 'test_slug = ?', [$test]);
            }

            $this->db->insert('activity_log', [
                'session_id' => null,
                'test_id' => null,
                'action' => self::AUDIT_ACTION,
                'details' => json_encode([
                    'actor' => 'owner',
                    'test' => $test,
                    'report_enabled' => $reportEnabled,
                    'send_item_answers' => $sendItemAnswers,
                ], JSON_THROW_ON_ERROR),
            ]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }

            throw $e;
        }

        $this->cache = null;

        return true;
    }

    /** @return array{report_enabled: bool, send_item_answers: bool} */
    private function row(string $test): array
    {
        return $this->all()[$test] ?? ['report_enabled' => false, 'send_item_answers' => false];
    }
}
