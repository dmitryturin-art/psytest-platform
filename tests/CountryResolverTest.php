<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\CountryResolver;

final class CountryResolverTest extends TestCase
{
    private CountryResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new CountryResolver();
    }

    public function testManualChoiceHasPriorityAndIsNormalised(): void
    {
        $resolution = $this->resolver->resolve(' ru ', 'KZ', 'BY');

        self::assertSame([
            'country_code' => 'RU',
            'source' => CountryResolver::SOURCE_MANUAL,
        ], $resolution->toArray());
    }

    public function testSessionChoiceIsUsedWhenThereIsNoManualChoice(): void
    {
        $resolution = $this->resolver->resolve(null, 'kz', 'BY');

        self::assertSame([
            'country_code' => 'KZ',
            'source' => CountryResolver::SOURCE_SESSION,
        ], $resolution->toArray());
    }

    public function testTrustedHintIsOnlyAFallback(): void
    {
        $resolution = $this->resolver->resolve(null, null, 'by');

        self::assertSame([
            'country_code' => 'BY',
            'source' => CountryResolver::SOURCE_TRUSTED_HINT,
        ], $resolution->toArray());
    }

    public function testInvalidValuesNeverSelectACountry(): void
    {
        $resolution = $this->resolver->resolve('Russia', 'RUS', '127.0.0.1');

        self::assertSame([
            'country_code' => null,
            'source' => CountryResolver::SOURCE_UNKNOWN,
        ], $resolution->toArray());
    }
}
