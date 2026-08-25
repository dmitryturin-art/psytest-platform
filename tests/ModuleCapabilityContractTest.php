<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PsyTest\Modules\BeckAnxiety\BeckAnxietyModule;
use PsyTest\Modules\BeckDepression\BeckDepressionModule;
use PsyTest\Modules\Hads\HadsModule;
use PsyTest\Modules\Lazarus\LazarusModule;
use PsyTest\Modules\ModuleCapability;
use PsyTest\Modules\Smil\SmilModule;
use PsyTest\Modules\TestModuleInterface;

/**
 * Contract tests for the Module API v2 capability registry.
 *
 * Every module must declare its capabilities declaratively; supportsPairMode()
 * must be derived from the PAIR capability, never overridden independently.
 */
final class ModuleCapabilityContractTest extends TestCase
{
    public static function moduleProvider(): array
    {
        return [
            'beck-anxiety' => [BeckAnxietyModule::class, [ModuleCapability::PDF]],
            'beck-depression' => [BeckDepressionModule::class, [ModuleCapability::CLINICAL_SIGNAL, ModuleCapability::PDF]],
            'hads' => [HadsModule::class, [ModuleCapability::PDF]],
            'lazarus' => [LazarusModule::class, [ModuleCapability::PAIR, ModuleCapability::PDF]],
            'smil' => [SmilModule::class, [ModuleCapability::CHART, ModuleCapability::PDF]],
        ];
    }

    #[DataProvider('moduleProvider')]
    public function testCapabilitiesAreDeclaredAndValid(string $moduleClass, array $expected): void
    {
        $module = new $moduleClass();
        $capabilities = $module->getCapabilities();

        self::assertNotEmpty($capabilities, "{$moduleClass} must declare at least one capability.");
        self::assertSame(
            array_values($expected),
            array_values($capabilities),
            "{$moduleClass} capability set drifted — update the declaration deliberately."
        );

        $known = ModuleCapability::all();
        foreach ($capabilities as $capability) {
            self::assertContains($capability, $known, "Unknown capability '{$capability}' in {$moduleClass}.");
        }
        self::assertSame(
            count($capabilities),
            count(array_unique($capabilities)),
            "{$moduleClass} declares duplicate capabilities."
        );
    }

    #[DataProvider('moduleProvider')]
    public function testPairSupportIsDerivedFromCapability(string $moduleClass): void
    {
        $module = new $moduleClass();

        self::assertSame(
            in_array(ModuleCapability::PAIR, $module->getCapabilities(), true),
            $module->supportsPairMode(),
            "{$moduleClass}: supportsPairMode() must be derived from the PAIR capability."
        );
    }
}
