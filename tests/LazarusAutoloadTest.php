<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

final class LazarusAutoloadTest extends TestCase
{
    public function testLazarusModuleAutoloadsThroughComposer(): void
    {
        self::assertTrue(class_exists('PsyTest\\Modules\\Lazarus\\LazarusModule'));
    }
}
