<?php

declare(strict_types=1);

namespace PsyTest\Tests\Smil;

use PHPUnit\Framework\TestCase;

/**
 * Целостность транскрипции приложения Собчик (05.S2).
 *
 * Файл — versioned input для S3, расчётом не используется. Тест стережёт то,
 * что подтверждено двумя независимыми проходами: 113 записей, счётчики равны
 * длинам списков, все номера в 1–566, нет дублей внутри ключа и пересечений
 * «верно/неверно». Числа намеренно оставлены как напечатано, поэтому порядок
 * не проверяется.
 */
final class AdditionalScalesTranscriptionTest extends TestCase
{
    public function testTranscriptionKeepsItsProvenInvariants(): void
    {
        $doc = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/docs/smil-additional-scales-transcription.json'), true, 512, JSON_THROW_ON_ERROR);
        $entries = $doc['entries'];

        self::assertCount(113, $entries);
        self::assertSame('НЕ используется расчётом: это versioned input для S3; scoring, нормы и fixtures runtime не менялись.', $doc['runtime']);

        $numbers = [];
        foreach ($entries as $entry) {
            $numbers[] = $entry['number'];
            self::assertSame($entry['true_declared'], count($entry['true']), "#{$entry['number']} true");
            self::assertSame($entry['false_declared'], count($entry['false']), "#{$entry['number']} false");
            foreach (['true', 'false'] as $side) {
                self::assertSame(count($entry[$side]), count(array_unique($entry[$side])), "#{$entry['number']} dup {$side}");
                foreach ($entry[$side] as $item) {
                    self::assertGreaterThanOrEqual(1, $item, "#{$entry['number']}");
                    self::assertLessThanOrEqual(566, $item, "#{$entry['number']}");
                }
            }
            self::assertSame([], array_values(array_intersect($entry['true'], $entry['false'])), "#{$entry['number']} true∩false");
            self::assertSame('A=B', $entry['agreement']);
        }
        self::assertSame(113, count(array_unique($numbers)));
        self::assertSame(1, min($numbers));
        self::assertSame(212, max($numbers));

        // Единственная запись без норм — №9: низ страницы обрезан в скане.
        $withoutNorms = array_map(static fn (array $e): int => $e['number'], array_filter($entries, static fn (array $e): bool => $e['male'] === null || $e['female'] === null));
        self::assertSame([9], array_values($withoutNorms));
    }
}
