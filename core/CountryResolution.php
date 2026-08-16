<?php

declare(strict_types=1);

namespace PsyTest\Core;

/**
 * Immutable result of resolving a country for the crisis-resource flow.
 *
 * A country is deliberately optional: an unavailable or untrusted hint must
 * not be presented as a user's confirmed location.
 */
final class CountryResolution
{
    public function __construct(
        public readonly ?string $countryCode,
        public readonly string $source,
    ) {
    }

    /**
     * @return array{country_code: ?string, source: string}
     */
    public function toArray(): array
    {
        return [
            'country_code' => $this->countryCode,
            'source' => $this->source,
        ];
    }
}
