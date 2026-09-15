<?php

declare(strict_types=1);

namespace PsyTest\Core\Ai;

/**
 * Компактный режим глоссария дополнительных шкал СМИЛ (07.G6).
 *
 * Полный глоссарий по всем 75 шкалам — около 60 000 знаков в каждом запросе,
 * и владелец поставил вопрос, не тонет ли клиническая часть разбора в
 * пояснениях по шкалам, которые сами по себе ничего не говорят (D-055).
 *
 * Компактор работает поверх уже собранной нагрузки, а не внутри модуля:
 *
 * - модуль решает, что вообще разрешено отдавать наружу (PRODUCT_RULES §6),
 *   и ничего не знает ни о БД, ни о настройках кабинета — передавать ему
 *   режим значило бы тянуть настройку владельца в клинический слой;
 * - T-балл и пояснение уже лежат рядом в готовом контексте, поэтому сжатие
 *   здесь — чистая функция от нагрузки, без повторного расчёта;
 * - один и тот же класс применяют `AiReportContextBuilder` и
 *   `PromptFixtureContext`, так что предпросмотр в кабинете совпадает с
 *   боевым запросом байт в байт.
 *
 * Режим всегда виден в самом контексте (`glossary_mode`), а значит и в
 * `ai_reports.context_snapshot`: по снимку разбора должно быть понятно, каким
 * глоссарием он сделан.
 */
final class SmilGlossaryCompactor
{
    public const MODE_FULL = 'full';
    public const MODE_COMPACT = 'compact';

    /**
     * Средний диапазон T, в котором шкала передаётся кратко (решение владельца
     * 15.09). Границы включительные: 40T и 65T считаются средним диапазоном.
     *
     * Диапазон уже: шкала со значением внутри него самостоятельного вклада в
     * интерпретацию почти не даёт и нужна модели только как фон, поэтому
     * достаточно одной строки смысла. Всё, что ниже 40T или выше 65T, идёт с
     * полным пояснением — именно по таким шкалам и строится вывод.
     */
    public const MID_RANGE_FROM = 40;
    public const MID_RANGE_TO = 65;

    /** Фраза для модели: иначе краткая запись читается как «пояснения нет». */
    public const COMPACT_PRINCIPLE = 'По шкалам в среднем диапазоне 40–65T передано только краткое значение; полные пояснения — по шкалам вне 40–65T.';

    public function __construct(private readonly string $mode)
    {
    }

    /** Неизвестное значение — это «как было», а не ошибка настройки. */
    public static function normalizeMode(?string $mode): string
    {
        return trim((string) $mode) === self::MODE_COMPACT ? self::MODE_COMPACT : self::MODE_FULL;
    }

    public static function fromSettings(?AiSettings $settings): self
    {
        return new self($settings === null ? self::MODE_FULL : $settings->smilGlossaryMode());
    }

    public function mode(): string
    {
        return self::normalizeMode($this->mode);
    }

    /**
     * @param array<string, mixed> $context Нагрузка, собранная модулем.
     *
     * @return array<string, mixed>
     */
    public function apply(array $context): array
    {
        // Компактный режим — про глоссарий СМИЛ, и другие методики он не трогает:
        // у них нет ни дополнительных шкал, ни глоссария.
        if (($context['test'] ?? null) !== 'smil' || !isset($context['additional_scales_glossary'])) {
            return $context;
        }

        $context['glossary_mode'] = $this->mode();

        if ($this->mode() === self::MODE_FULL) {
            return $context;
        }

        $tScores = self::tScoresByCode((array) ($context['additional_scales'] ?? []));
        $glossary = [];

        foreach ((array) $context['additional_scales_glossary'] as $code => $entry) {
            if (!is_array($entry)) {
                $glossary[$code] = $entry;
                continue;
            }

            $glossary[$code] = self::isMidRange($tScores[(string) $code] ?? null)
                ? ['meaning' => $entry['meaning'] ?? '']
                : $entry;
        }

        $context['additional_scales_glossary'] = $glossary;
        $context['levels'] = self::withCompactPrinciple((array) ($context['levels'] ?? []));

        return $context;
    }

    /**
     * Шкала без T-балла сжатию не подлежит: непонятно, фон это или пик, и
     * терять пояснение на догадке нельзя.
     */
    private static function isMidRange(int|float|null $t): bool
    {
        return $t !== null && $t >= self::MID_RANGE_FROM && $t <= self::MID_RANGE_TO;
    }

    /**
     * @param array<array-key, mixed> $scales
     *
     * @return array<string, int|float>
     */
    private static function tScoresByCode(array $scales): array
    {
        $scores = [];

        foreach ($scales as $scale) {
            if (!is_array($scale) || !isset($scale['code'])) {
                continue;
            }

            $t = $scale['t'] ?? null;
            if (is_int($t) || is_float($t)) {
                $scores[(string) $scale['code']] = $t;
            }
        }

        return $scores;
    }

    /**
     * @param array<string, mixed> $levels
     *
     * @return array<string, mixed>
     */
    private static function withCompactPrinciple(array $levels): array
    {
        $principles = array_values((array) ($levels['principles'] ?? []));

        if (!in_array(self::COMPACT_PRINCIPLE, $principles, true)) {
            $principles[] = self::COMPACT_PRINCIPLE;
        }

        $levels['principles'] = $principles;

        return $levels;
    }
}
