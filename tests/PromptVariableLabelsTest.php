<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\Ai\PromptFixtureContext;
use PsyTest\Core\Ai\PromptVariableLabels;
use PsyTest\Core\ModuleLoader;

/**
 * Каждое поле входных данных промпта имеет русскую подпись (замечание
 * владельца 08.10: английские ключи «ни о чём не говорят»).
 */
final class PromptVariableLabelsTest extends TestCase
{
    public function testEveryContextKeyOfEveryMethodologyHasARussianLabel(): void
    {
        $loader = (new ModuleLoader(null, null))->discover();
        $checked = 0;
        foreach (array_keys($loader->getActiveModules()) as $slug) {
            $module = $loader->getModule((string) $slug);
            self::assertNotNull($module);
            foreach (['individual', 'pair'] as $mode) {
                try {
                    $context = PromptFixtureContext::build($module, $mode, null);
                } catch (\Throwable) {
                    continue;
                }
                foreach (array_keys($context) as $key) {
                    $described = PromptVariableLabels::describe((string) $key);
                    self::assertTrue(PromptVariableLabels::has((string) $key), "Нет русской подписи для поля «{$key}» ({$slug}/{$mode})");
                    self::assertNotSame($described['label'], $described['key'], "Подпись поля «{$key}» совпадает с ключом");
                    self::assertMatchesRegularExpression('/[А-Яа-яЁё]/u', $described['label']);
                    self::assertNotSame('', $described['hint']);
                    ++$checked;
                }
            }
        }
        self::assertGreaterThan(10, $checked);
    }

    public function testUnknownKeyFallsBackToItself(): void
    {
        $d = PromptVariableLabels::describe('something_new');
        self::assertSame('something_new', $d['label']);
        self::assertSame('', $d['hint']);
    }
}
