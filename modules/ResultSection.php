<?php

declare(strict_types=1);

namespace PsyTest\Modules;

final class ResultSection
{
    public function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly array $data,
        public readonly ?string $block = null,
        public readonly int $order = 0,
    ) {
    }

    /**
     * Секция-список без пунктов: заголовок «Рекомендации» над пустым местом
     * читается как сбой. Тексты рекомендаций модуль не придумывает — нет
     * данных, нет и секции (04.D8).
     */
    public function isEmpty(): bool
    {
        if ($this->type !== self::TYPE_RECOMMENDATIONS) {
            return false;
        }
        $items = $this->data['items'] ?? [];

        return !is_array($items) || array_filter($items, static fn (mixed $item): bool => is_string($item) ? trim($item) !== '' : $item !== null) === [];
    }

    /**
     * Секции, которые есть что показывать: общий фильтр для страницы результата,
     * PDF, карточки кейса и выгрузки.
     *
     * @param list<ResultSection> $sections
     * @return list<ResultSection>
     */
    public static function visible(array $sections): array
    {
        return array_values(array_filter($sections, static fn (self $section): bool => !$section->isEmpty()));
    }

    /** Section types */
    public const TYPE_PROFILE_CHART = 'profile_chart';
    public const TYPE_SCALES_TABLE = 'scales_table';
    public const TYPE_SCORE_BADGE = 'score_badge';
    public const TYPE_VALIDITY = 'validity';
    public const TYPE_INTERPRETATION = 'interpretation';
    public const TYPE_RECOMMENDATIONS = 'recommendations';
    public const TYPE_INDICES = 'indices';
    public const TYPE_RAW_HTML = 'raw_html';
    public const TYPE_PAIR_CHART = 'pair_chart';
    public const TYPE_PAIR_COMPARISON = 'pair_comparison';
    public const TYPE_PAIR_INVITE = 'pair_invite';
    /** Профиль черт по перцентилям: домены с полосой и свёрнутые грани (IPIP-NEO-120). */
    public const TYPE_TRAIT_PROFILE = 'trait_profile';
}
