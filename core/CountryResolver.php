<?php

declare(strict_types=1);

namespace PsyTest\Core;

/**
 * Resolves a country for future crisis-resource selection without inspecting,
 * storing, or transmitting an IP address.
 *
 * The caller is responsible for supplying a hint only after it has established
 * that the reverse proxy or local GeoIP source is trusted. A manual choice is
 * always authoritative, then a choice already stored for the current session.
 */
final class CountryResolver
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_SESSION = 'session';
    public const SOURCE_TRUSTED_HINT = 'trusted_hint';
    public const SOURCE_UNKNOWN = 'unknown';

    public function resolve(
        ?string $manualCountryCode,
        ?string $sessionCountryCode,
        ?string $trustedCountryHint,
    ): CountryResolution {
        foreach ([
            [$manualCountryCode, self::SOURCE_MANUAL],
            [$sessionCountryCode, self::SOURCE_SESSION],
            [$trustedCountryHint, self::SOURCE_TRUSTED_HINT],
        ] as [$countryCode, $source]) {
            $normalised = $this->normaliseCountryCode($countryCode);

            if ($normalised !== null) {
                return new CountryResolution($normalised, $source);
            }
        }

        return new CountryResolution(null, self::SOURCE_UNKNOWN);
    }

    private function normaliseCountryCode(?string $countryCode): ?string
    {
        if ($countryCode === null) {
            return null;
        }

        $normalised = strtoupper(trim($countryCode));

        return preg_match('/^[A-Z]{2}$/', $normalised) === 1 ? $normalised : null;
    }
}
