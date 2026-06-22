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

    /** Section types */
    public const TYPE_PROFILE_CHART = 'profile_chart';
    public const TYPE_SCALES_TABLE = 'scales_table';
    public const TYPE_SCORE_BADGE = 'score_badge';
    public const TYPE_VALIDITY = 'validity';
    public const TYPE_INTERPRETATION = 'interpretation';
    public const TYPE_RECOMMENDATIONS = 'recommendations';
    public const TYPE_INDICES = 'indices';
    public const TYPE_RAW_HTML = 'raw_html';
}
