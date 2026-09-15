<?php

declare(strict_types=1);

namespace PsyTest\Tests\Unit\Ai;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\Ai\SmilGlossaryCompactor;

/**
 * Компактный режим глоссария дополнительных шкал СМИЛ (07.G6).
 *
 * Проверяется ровно то, чем режим и оправдан: шкала среднего диапазона идёт
 * одной строкой смысла, шкала за его пределами — полной записью, а полный
 * режим ничего не меняет в нагрузке.
 */
final class SmilGlossaryCompactorTest extends TestCase
{
    /** @return array<string, mixed> */
    private function context(int ...$tScores): array
    {
        $scales = [];
        $glossary = [];

        foreach ($tScores as $index => $t) {
            $code = 'S' . $index;
            $scales[] = ['code' => $code, 'name' => 'Шкала ' . $index, 't' => $t, 'raw' => 10];
            $glossary[$code] = [
                'meaning' => 'смысл ' . $index,
                'high' => 'высокие значения ' . $index,
                'low' => 'низкие значения ' . $index,
                'relates_to' => ['4'],
                'western_name' => 'Scale ' . $index,
                'source' => 'источник',
            ];
        }

        return [
            'test' => 'smil',
            'mode' => 'individual',
            'additional_scales' => $scales,
            'additional_scales_glossary' => $glossary,
            'additional_scales_without_glossary' => [],
            'levels' => [
                'scale' => 'T',
                'bands' => [['from' => 71, 'to' => 100, 'label' => 'выражено', 'means' => 'признак выражен']],
                'principles' => ['первый принцип'],
            ],
        ];
    }

    public function testFullModeChangesNothingExceptTheModeMarker(): void
    {
        $context = $this->context(30, 50, 80);

        $result = (new SmilGlossaryCompactor(SmilGlossaryCompactor::MODE_FULL))->apply($context);

        self::assertSame('full', $result['glossary_mode']);
        unset($result['glossary_mode']);
        self::assertSame($context, $result, 'В полном режиме нагрузка обязана остаться прежней.');
    }

    public function testMidRangeScalesKeepOnlyTheirMeaning(): void
    {
        $result = (new SmilGlossaryCompactor(SmilGlossaryCompactor::MODE_COMPACT))->apply($this->context(50));

        self::assertSame('compact', $result['glossary_mode']);
        self::assertSame(['meaning'], array_keys($result['additional_scales_glossary']['S0']));
        self::assertSame('смысл 0', $result['additional_scales_glossary']['S0']['meaning']);
    }

    /**
     * Границы 40 и 65 включительно — решение владельца 15.09, и именно на них
     * ошибка была бы незаметной.
     */
    public function testRangeBoundariesDecideWhoKeepsTheFullEntry(): void
    {
        $result = (new SmilGlossaryCompactor(SmilGlossaryCompactor::MODE_COMPACT))->apply($this->context(39, 40, 65, 66));
        $glossary = $result['additional_scales_glossary'];

        self::assertCount(6, $glossary['S0'], '39T — вне диапазона, запись полная.');
        self::assertSame(['meaning'], array_keys($glossary['S1']), '40T — нижняя граница среднего диапазона.');
        self::assertSame(['meaning'], array_keys($glossary['S2']), '65T — верхняя граница среднего диапазона.');
        self::assertCount(6, $glossary['S3'], '66T — вне диапазона, запись полная.');
    }

    public function testScaleWithoutAGlossaryEntryIsUntouched(): void
    {
        $context = $this->context(50);
        $context['additional_scales'][] = ['code' => 'NOGLOSS', 'name' => 'Без пояснения', 't' => 52, 'raw' => 3];
        $context['additional_scales_without_glossary'] = ['NOGLOSS'];

        $result = (new SmilGlossaryCompactor(SmilGlossaryCompactor::MODE_COMPACT))->apply($context);

        self::assertSame(['S0'], array_keys($result['additional_scales_glossary']));
        self::assertSame(['NOGLOSS'], $result['additional_scales_without_glossary'], 'Список шкал без пояснения режим не трогает.');
    }

    public function testScaleWithoutATScoreKeepsTheFullEntry(): void
    {
        // Непонятно, фон это или пик, — терять пояснение на догадке нельзя.
        $context = $this->context(50);
        $context['additional_scales'][0]['t'] = null;

        $result = (new SmilGlossaryCompactor(SmilGlossaryCompactor::MODE_COMPACT))->apply($context);

        self::assertCount(6, $result['additional_scales_glossary']['S0']);
    }

    public function testCompactModeTellsTheModelWhatItGotAndWhatItDidNot(): void
    {
        // Без этой фразы краткая запись читается как «пояснения нет».
        $result = (new SmilGlossaryCompactor(SmilGlossaryCompactor::MODE_COMPACT))->apply($this->context(50));

        self::assertContains(SmilGlossaryCompactor::COMPACT_PRINCIPLE, $result['levels']['principles']);
        self::assertContains('первый принцип', $result['levels']['principles'], 'Прежние принципы остаются на месте.');
        self::assertCount(1, $result['levels']['bands'], 'Полосы уровней режим не трогает.');
    }

    public function testUnknownModeBehavesLikeFull(): void
    {
        // Мусор в настройке не должен молча резать нагрузку.
        $context = $this->context(50);
        $result = (new SmilGlossaryCompactor('что-то ещё'))->apply($context);

        self::assertSame('full', $result['glossary_mode']);
        self::assertCount(6, $result['additional_scales_glossary']['S0']);
    }

    public function testOtherMethodologiesAreNotTouchedAtAll(): void
    {
        $context = ['test' => 'lazarus', 'mode' => 'individual', 'scales' => []];

        self::assertSame($context, (new SmilGlossaryCompactor(SmilGlossaryCompactor::MODE_COMPACT))->apply($context));
    }
}
