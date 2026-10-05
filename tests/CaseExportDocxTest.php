<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\CaseExportDocx;
use PsyTest\Core\ReportMarkdown;
use PsyTest\Modules\ProfileChartImageRenderer;
use PsyTest\Modules\ResultSection;

/**
 * Сборка Word-документа выгрузки без БД (07.K5m): перевод HTML и Markdown в
 * элементы Word и поведение при недоступном графике.
 */
final class CaseExportDocxTest extends TestCase
{
    use DocxInspection;

    public function testMarkdownReportKeepsHeadingsListsTablesAndEmphasis(): void
    {
        $markdown = <<<'MD'
# Заключение

## Первый раздел

Абзац с **жирным** и *курсивным* текстом & символом <b>.

- первый пункт
- второй пункт

1. шаг один
2. шаг два

Другой список:

1. снова с единицы

| Шкала | Балл | Комментарий |
|---|---|---|
| 1 Hs | 75 | повышена |
| 2 D | 60 | норма |

---

> Цитата
MD;

        $dom = $this->xmlPart($this->documentXml($this->document(['professional' => ['html' => ReportMarkdown::toHtml($markdown)]])));
        $xpath = $this->xpath($dom);
        $text = $this->plainText($dom);

        // Заголовок отчёта — настоящий стиль Word, а не жирный абзац.
        self::assertGreaterThan(0, $xpath->query('//w:pStyle[@w:val="Heading2"]')->length);
        self::assertGreaterThan(0, $xpath->query('//w:pStyle[@w:val="Heading3"]')->length);
        self::assertStringContainsString('Заключение', $text);
        self::assertStringContainsString('символом <b>.', $text, 'Экранирование: чужая разметка остаётся текстом.');

        // Жирное и курсивное — свойства прогона, а не звёздочки.
        self::assertSame('жирным', $xpath->query('//w:r[w:rPr/w:b]/w:t[.="жирным"]')->item(0)?->textContent);
        self::assertGreaterThan(0, $xpath->query('//w:r[w:rPr/w:i]/w:t[.="курсивным"]')->length);
        self::assertStringNotContainsString('**', $text);

        // Списки: настоящая нумерация Word, два нумерованных списка — две разные нумерации.
        self::assertGreaterThanOrEqual(5, $xpath->query('//w:numPr')->length);
        $numIds = [];
        foreach ($xpath->query('//w:numPr/w:numId/@w:val') ?: [] as $attr) {
            $numIds[$attr->nodeValue] = true;
        }
        self::assertGreaterThanOrEqual(3, count($numIds), 'Маркированный и два нумерованных списка различаются.');

        // Таблица Markdown стала таблицей Word с повторяющейся шапкой.
        $table = $this->largestTable($dom);
        self::assertSame(3, $xpath->query('./w:tr', $table)->length);
        self::assertSame(1, $xpath->query('./w:tr/w:trPr/w:tblHeader', $table)->length);
        self::assertStringContainsString('повышена', $table->textContent);
        self::assertSame(3, $xpath->query('./w:tblGrid/w:gridCol', $table)->length);
        // Порядок по схеме OOXML: свойства таблицы, затем сетка, затем строки.
        self::assertSame('w:tblPr', $table->firstChild?->nodeName);
        self::assertSame('w:tblGrid', $table->firstChild?->nextSibling?->nodeName);
        self::assertSame(0, $xpath->query('.//w:noWrap', $table)->length);
    }

    public function testNestedListsAndColspanRowsAreKept(): void
    {
        $html = '<ul><li>верхний<ul><li>вложенный</li></ul></li><li>второй</li></ul>'
            . '<table><thead><tr><th>A</th><th>B</th></tr></thead><tbody>'
            . '<tr class="category-header"><td colspan="2"><strong>Раздел</strong>'
            . '<span class="category-note">Пояснение</span></td></tr>'
            . '<tr><td>x</td><td>y<span class="scale-note">мелко</span></td></tr></tbody></table>';

        $dom = $this->xmlPart($this->documentXml($this->document(['professional' => ['html' => $html]])));
        $xpath = $this->xpath($dom);

        $levels = [];
        foreach ($xpath->query('//w:numPr/w:ilvl/@w:val') ?: [] as $attr) {
            $levels[] = $attr->nodeValue;
        }
        self::assertContains('1', $levels, 'Вложенный пункт стоит на втором уровне.');
        self::assertStringContainsString('вложенный', $this->plainText($dom));

        $table = $this->largestTable($dom);
        self::assertSame('2', $xpath->query('.//w:gridSpan/@w:val', $table)->item(0)?->nodeValue);
        self::assertStringContainsString('Пояснение', $table->textContent);
        // Примечание шкалы — мелкий серый текст в ячейке названия.
        self::assertSame(
            '15',
            $xpath->query('.//w:r[w:t="мелко"]/w:rPr/w:sz/@w:val', $table)->item(0)?->nodeValue,
        );
    }

    public function testControlCharactersNeverBreakTheXml(): void
    {
        $html = "<p>до\x00\x08\x1Fпосле</p>";
        $dom = $this->xmlPart($this->documentXml($this->document(['professional' => ['html' => $html]])));

        $text = $this->plainText($dom);
        self::assertMatchesRegularExpression('/до.?после/u', $text);
        self::assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text);
    }

    public function testAnUnavailableChartRendererLeavesAPlaceholderAndTheDocumentStillBuilds(): void
    {
        $chart = new ResultSection(ResultSection::TYPE_PROFILE_CHART, 'Профиль личности', [
            'pdf_image_renderer' => UnavailableChartRenderer::class,
            'scores' => [50],
            'labels' => ['L'],
        ]);

        $parts = $this->unzip($this->build($this->document(['sections' => [$chart]])));

        self::assertSame([], array_filter(array_keys($parts), static fn (string $n): bool => str_starts_with($n, 'word/media/')));
        $text = $this->plainText($this->xmlPart($parts['word/document.xml']));
        self::assertStringContainsString('График недоступен', $text);
        self::assertStringContainsString('Профиль личности', $text);
    }

    public function testThePackageHasTheWordPartsAndAFooterWithPageNumbers(): void
    {
        $parts = $this->unzip($this->build($this->document([])));

        foreach (['[Content_Types].xml', '_rels/.rels', 'word/document.xml', 'word/styles.xml', 'word/numbering.xml'] as $name) {
            self::assertArrayHasKey($name, $parts);
            $this->xmlPart($parts[$name]);
        }

        $footers = array_filter(array_keys($parts), static fn (string $n): bool => str_starts_with($n, 'word/footer'));
        self::assertNotSame([], $footers);
        $footer = $parts[array_values($footers)[0]];
        self::assertStringContainsString('Конфиденциально · стр. ', $footer);
        self::assertStringContainsString('PAGE', $footer);
        self::assertStringContainsString('NUMPAGES', $footer);

        // Страница A4 с полями 2 см.
        $document = $parts['word/document.xml'];
        self::assertMatchesRegularExpression('/w:pgSz[^>]*w:w="11906"[^>]*w:h="16838"/', $document);
        self::assertStringContainsString('w:left="1134"', $document);
    }

    // ----------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function document(array $override): array
    {
        return $override + [
            'header' => [
                'test_name' => 'Синтетическая методика',
                'client_label' => 'Образец',
                'completed_at' => '2026-10-01 12:30:00',
                'prepared_by' => 'Подготовил: специалист',
                'confidential' => 'Конфиденциально',
            ],
            'sections' => [],
            'pair' => null,
            'answers' => [],
            'professional' => null,
            'clear' => null,
            'note' => null,
            'generated_at' => '05.10.2026 10:00',
            'disclaimer' => 'Результаты носят ознакомительный характер.',
            'options' => [
                'include_professional' => true,
                'include_clear' => true,
                'include_answers' => false,
                'include_note' => false,
            ],
        ];
    }

    /** @param array<string, mixed> $document */
    private function build(array $document): string
    {
        return (new CaseExportDocx(
            static fn (string $template, array $data): string => '',
        ))->render($document);
    }

    /** @param array<string, mixed> $document */
    private function documentXml(array $document): string
    {
        return $this->unzip($this->build($document))['word/document.xml'];
    }
}

/** Рендерер, которого «нет в окружении» (например, без GD). */
final class UnavailableChartRenderer implements ProfileChartImageRenderer
{
    public static function isAvailable(): bool
    {
        return false;
    }

    public function renderPng(array $data): string
    {
        throw new \RuntimeException('недоступен');
    }
}
