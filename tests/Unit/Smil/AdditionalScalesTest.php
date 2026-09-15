<?php

declare(strict_types=1);

namespace PsyTest\Tests\Unit\Smil;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\Smil\SmilModule;

/**
 * Секция «Дополнительные шкалы» после пакета 05.S3.2: одна группа
 * проверенных по источнику шкал, без клинических текстов; там, где источник
 * содержит опечатку, рядом идёт пояснение — без предупреждающего значка.
 */
class AdditionalScalesTest extends TestCase
{
    public function testBuildAdditionalScalesDataReturnsOneVerifiedGroup(): void
    {
        $module = new SmilModule();
        $additionalScores = [
            'A' => [
                'id' => 'sobchik-001',
                'code' => 'A',
                'name' => 'А-первый фактор',
                'raw' => 13,
                't' => 53.0,
                'M' => 11.0,
                'sigma' => 6.2,
                'max_raw' => 39,
                'answered' => 39,
                'level' => 'normal',
                'level_name' => 'в пределах нормы',
                'source' => ['entry' => 1, 'page_pdf' => 197, 'page_print' => 195],
                'status' => 'verified',
            ],
            'Es' => [
                'id' => 'sobchik-092',
                'code' => 'Es',
                'name' => 'Шкала «Интелектуальная эффективность»',
                'raw' => 29,
                't' => 51.0,
                'M' => 28.67,
                'sigma' => 3.75,
                'max_raw' => 39,
                'answered' => 39,
                'level' => 'normal',
                'level_name' => 'в пределах нормы',
                'source' => ['entry' => 92, 'page_pdf' => 205, 'page_print' => 203],
                'status' => 'verified',
            ],
        ];

        $data = $this->invokeMethod($module, 'buildAdditionalScalesData', [$additionalScores]);

        $this->assertArrayHasKey('categories', $data);
        $this->assertCount(1, $data['categories']);

        $category = $data['categories'][0];
        $this->assertSame('Проверенные по Собчик (2003)', $category['name']);
        $this->assertArrayHasKey('note', $category);
        $this->assertSame(2, $category['count']);
        $this->assertCount(2, $category['items']);

        $first = $category['items'][0];
        foreach (['code', 'name', 'raw', 'max_raw', 'answered', 't_score', 'level', 'level_name', 'norm', 'source_note', 'status', 'note'] as $key) {
            $this->assertArrayHasKey($key, $first, "item.$key");
        }
        $this->assertSame('A', $first['code']);
        $this->assertSame('verified', $first['status']);
        $this->assertSame('', $first['note']);
        $this->assertStringContainsString('M 11', $first['norm']);
        $this->assertStringContainsString('стр. 195', $first['source_note']);
        $this->assertArrayNotHasKey('interpretation', $first);
    }

    public function testTheEntryWithASourceTypoShowsItsExplanationWithoutAWarningBadge(): void
    {
        $module = new SmilModule();
        $data = $this->invokeMethod($module, 'buildAdditionalScalesData', [[
            'SOM' => [
                'id' => 'sobchik-087',
                'code' => 'SOM',
                'name' => 'Шкала «Соматические жалобы»',
                'raw' => 5,
                't' => 63.0,
                'M' => 2.37,
                'sigma' => 2.0,
                'max_raw' => 17,
                'answered' => 17,
                'level' => 'normal',
                'level_name' => 'в пределах нормы',
                'source' => ['entry' => 87, 'page_pdf' => 205, 'page_print' => 203],
                'status' => 'verified',
                'note' => 'В источнике опечатка «верно/неверно»; состав подтверждён сверкой с '
                    . 'Harris–Lingoes Hy4 (17 п., 6 true / 11 false).',
            ],
        ]]);

        $item = $data['categories'][0]['items'][0];

        $this->assertSame('verified', $item['status'], 'состав ключа подтверждён — статус не понижается');
        $this->assertStringContainsString('Harris', $item['note']);
        $this->assertArrayNotHasKey('flag', $item, 'предупреждающего значка у verified-шкалы быть не должно');

        // Примечание объясняет строку ключа, а не ставит под сомнение нормы,
        // и остаётся нейтральным: клинических формулировок в нём нет.
        $this->assertStringNotContainsStringIgnoringCase('норм', $item['note']);
        foreach (['симптом', 'диагноз', 'патолог', 'расстройств'] as $clinical) {
            $this->assertStringNotContainsStringIgnoringCase($clinical, $item['note']);
        }
    }

    public function testBuildAdditionalScalesDataHandlesEmptyScores(): void
    {
        $module = new SmilModule();
        $data = $this->invokeMethod($module, 'buildAdditionalScalesData', [[]]);

        $this->assertArrayHasKey('categories', $data);
        $this->assertIsArray($data['categories']);
        $this->assertCount(0, $data['categories']);
    }

    public function testUnknownCodesAreNotRendered(): void
    {
        $module = new SmilModule();
        $data = $this->invokeMethod($module, 'buildAdditionalScalesData', [
            ['ANX' => ['name' => 'Тревога', 'raw' => 13, 't' => 54.0]],
        ]);

        $this->assertSame([], $data['categories']);
    }

    private function invokeMethod($object, string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        // setAccessible() is a no-op since PHP 8.1 and deprecated in 8.5
        return $method->invokeArgs($object, $parameters);
    }
}
