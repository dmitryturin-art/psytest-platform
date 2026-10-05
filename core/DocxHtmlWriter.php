<?php

declare(strict_types=1);

namespace PsyTest\Core;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\Style\Table as TableStyle;

/**
 * Перевод HTML выгрузки кейса в элементы Word (07.K5m).
 *
 * Содержимое документа приходит HTML: тексты разборов — из
 * `ReportMarkdown::toHtml()`, секции результата — из тех же блоков Twig, что
 * рисуют PDF. `PhpWord\Shared\Html::addHtml` для этого не годится: вложенных
 * списков и повторяющейся шапки таблицы он не держит, а CSS-классы блоков
 * (`scale-note`, `item-domain`) не знает. Поэтому здесь свой обход DOM: он
 * понимает ровно те теги, которые встречаются в выгрузке, и складывает их в
 * настоящие элементы Word (абзацы, списки, таблицы), а всё остальное
 * сводит к тексту, не теряя содержимого.
 *
 * Сами OOXML-части пишет PhpWord; здесь только раскладка.
 */
final class DocxHtmlWriter
{
    /** Ширина области текста A4 при полях 2 см, твипы. */
    public const CONTENT_WIDTH = 9638;

    public const BODY_SIZE = 11;
    private const TABLE_SIZE = 9.0;
    private const NOTE_SIZE = 7.5;
    private const NOTE_COLOR = '595959';
    private const BULLET_STYLE = 'psytest-bullet';

    private const INLINE_TAGS = [
        'a', 'abbr', 'b', 'strong', 'i', 'em', 'u', 's', 'del', 'ins', 'code', 'kbd', 'small',
        'span', 'sub', 'sup', 'mark', 'time', 'label', 'cite', 'font', 'q', 'bdi', 'wbr', 'br',
    ];

    private const SKIPPED_TAGS = [
        'style', 'script', 'svg', 'canvas', 'button', 'input', 'select', 'textarea', 'noscript',
        'template', 'img', 'iframe', 'object', 'head',
    ];

    /** Элементы, которые на экране были подсказкой, а на бумаге — мусор. */
    private const HIDDEN_CLASSES = [
        'visually-hidden',
        'score-badge__scale',
        'score-badge__scale-labels',
        'pair-comparison__details-hint',
    ];

    /**
     * Классы-метки блоков, вёрстка которых держится на CSS: перенос перед
     * текстом и мелкий шрифт для примечаний.
     *
     * @var array<string, array{br?: bool, font: array<string, mixed>}>
     */
    private const SPAN_RULES = [
        'scale-note' => ['br' => true, 'font' => ['size' => self::NOTE_SIZE, 'color' => self::NOTE_COLOR]],
        'scale-source' => ['br' => true, 'font' => ['size' => self::NOTE_SIZE, 'color' => self::NOTE_COLOR]],
        'scale-norm' => ['br' => true, 'font' => ['size' => self::NOTE_SIZE, 'color' => self::NOTE_COLOR]],
        'category-note' => ['br' => true, 'font' => ['size' => self::NOTE_SIZE, 'italic' => true, 'color' => self::NOTE_COLOR]],
        'item-domain' => ['font' => ['bold' => true, 'size' => 8.5]],
        'item-text' => ['br' => true, 'font' => []],
        'pair-score-card__label' => ['font' => ['bold' => true]],
        'pair-score-card__value' => ['font' => ['bold' => true, 'size' => 14]],
        'score-badge__number' => ['font' => ['bold' => true, 'size' => 14]],
        'score-badge__level' => ['font' => ['bold' => true]],
        'validity-value' => ['font' => ['bold' => true]],
        'validity-status' => ['font' => ['bold' => true]],
        'profile-type-description' => ['font' => []],
        'indices-list__name' => ['font' => ['bold' => true]],
    ];

    private int $orderedLists = 0;

    public function __construct(private readonly PhpWord $phpWord)
    {
        $this->phpWord->addNumberingStyle(self::BULLET_STYLE, $this->numbering(false));
    }

    /**
     * Дописывает HTML в контейнер (раздел документа или ячейку).
     */
    public function append(AbstractContainer $container, string $html): void
    {
        if (trim($html) === '') {
            return;
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body instanceof \DOMElement) {
            $this->blocks($body, $container, $this->context());
        }
    }

    /**
     * Текст без символов, которых нет в XML 1.0: Word такой файл не откроет.
     */
    public static function clean(string $text): string
    {
        return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    private function context(): array
    {
        return [
            'font' => ['size' => self::BODY_SIZE],
            'cell' => false,
            'align' => null,
            'indent' => 0,
            'width' => self::CONTENT_WIDTH,
        ];
    }

    // ------------------------------------------------------------------ blocks

    /**
     * Дочерние узлы как последовательность блоков; соседний inline-текст
     * собирается в один абзац.
     *
     * @param array<string, mixed> $ctx
     */
    private function blocks(\DOMNode $parent, AbstractContainer $container, array $ctx): void
    {
        $buffer = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $buffer[] = ['t' => $this->normalize($child->nodeValue ?? ''), 'f' => $ctx['font']];
                continue;
            }
            if (!$child instanceof \DOMElement || $this->hidden($child)) {
                continue;
            }

            if (in_array(strtolower($child->tagName), self::INLINE_TAGS, true)) {
                $this->collect($child, $buffer, $ctx['font']);
                continue;
            }

            $this->paragraph($buffer, $container, $ctx);
            $buffer = [];
            $this->block($child, $container, $ctx);
        }

        $this->paragraph($buffer, $container, $ctx);
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function block(\DOMElement $el, AbstractContainer $container, array $ctx): void
    {
        $tag = strtolower($el->tagName);

        switch ($tag) {
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                $this->heading($el, (int) substr($tag, 1), $container, $ctx);
                return;

            case 'hr':
                $container->addText('', null, [
                    'borderBottomSize' => 6,
                    'borderBottomColor' => 'A6A6A6',
                    'spaceBefore' => 60,
                    'spaceAfter' => 120,
                ]);
                return;

            case 'ul':
            case 'ol':
                $this->list($el, $container, $ctx, 0);
                return;

            case 'table':
                $this->table($el, $container, $ctx);
                return;

            case 'blockquote':
                $ctx['indent'] += 567;
                $ctx['font'] = $ctx['font'] + ['italic' => true];
                $ctx['font']['color'] = self::NOTE_COLOR;
                $this->blocks($el, $container, $ctx);
                return;

            case 'dt':
            case 'summary':
            case 'legend':
            case 'caption':
                $ctx['font'] = ['bold' => true] + $ctx['font'];
                $this->blocks($el, $container, $ctx);
                return;

            case 'dd':
                $ctx['indent'] += 284;
                $this->blocks($el, $container, $ctx);
                return;

            default:
                // div, p, section, article, details, dl, figure и прочая обёртка;
                // класс блока может задавать начертание (оценка пары, уровень).
                $dummy = [];
                $ctx['font'] = $this->fontFor($el, $tag, $ctx['font'], $dummy);
                $this->blocks($el, $container, $ctx);
        }
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function heading(\DOMElement $el, int $level, AbstractContainer $container, array $ctx): void
    {
        $text = trim($this->normalize($el->textContent));
        if ($text === '') {
            return;
        }

        // Стили «Заголовок N» есть только в теле документа; в ячейке и списке
        // заголовок остаётся жирным абзацем.
        if ($container instanceof Section) {
            $container->addTitle(self::clean($text), max(1, min(6, $level)));

            return;
        }

        $ctx['font'] = ['bold' => true] + $ctx['font'];
        $buffer = [];
        $this->collect($el, $buffer, $ctx['font']);
        $this->paragraph($buffer, $container, $ctx);
    }

    // --------------------------------------------------------------- paragraphs

    /**
     * Накопленные фрагменты → один абзац.
     *
     * @param list<array<string, mixed>> $buffer
     * @param array<string, mixed> $ctx
     */
    private function paragraph(array $buffer, AbstractContainer $container, array $ctx): void
    {
        $buffer = $this->trimmed($buffer);
        if ($buffer === []) {
            return;
        }

        $run = $container->addTextRun($this->paragraphStyle($ctx));
        $this->fill($run, $buffer);
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function paragraphStyle(array $ctx): array
    {
        $style = [
            'spaceBefore' => 0,
            'spaceAfter' => $ctx['cell'] ? 20 : 100,
            // Кратность строки без дроби: PhpWord пишет `w:line` как число, а
            // «259.2» вместо целых твипов Word может счесть ошибкой разметки.
            'lineHeight' => $ctx['cell'] ? 1.0 : 1.125,
        ];
        if ($ctx['align'] !== null) {
            $style['alignment'] = $ctx['align'];
        }
        if ($ctx['indent'] > 0) {
            $style['indentation'] = ['left' => $ctx['indent']];
        }

        return $style;
    }

    /**
     * @param \PhpOffice\PhpWord\Element\TextRun $run
     * @param list<array<string, mixed>> $buffer
     */
    private function fill(\PhpOffice\PhpWord\Element\TextRun $run, array $buffer): void
    {
        foreach ($buffer as $piece) {
            if (isset($piece['br'])) {
                $run->addTextBreak();
                continue;
            }
            $text = self::clean((string) $piece['t']);
            if ($text !== '') {
                $run->addText($text, $piece['f']);
            }
        }
    }

    /**
     * Убирает пробелы по краям абзаца и пустые переносы.
     *
     * @param list<array<string, mixed>> $buffer
     * @return list<array<string, mixed>>
     */
    private function trimmed(array $buffer): array
    {
        // Подряд идущие пробелы между фрагментами схлопываются, как в браузере.
        $out = [];
        $lastSpace = true;
        foreach ($buffer as $piece) {
            if (isset($piece['br'])) {
                $lastSpace = true;
                if ($out !== [] && !isset($out[array_key_last($out)]['br'])) {
                    $out[] = $piece;
                }
                continue;
            }
            $text = (string) $piece['t'];
            if ($lastSpace) {
                $text = ltrim($text);
            }
            if ($text === '') {
                continue;
            }
            $lastSpace = str_ends_with($text, ' ');
            $piece['t'] = $text;
            $out[] = $piece;
        }

        while ($out !== [] && isset($out[array_key_last($out)]['br'])) {
            array_pop($out);
        }
        if ($out !== []) {
            $last = array_key_last($out);
            $out[$last]['t'] = rtrim((string) $out[$last]['t']);
            if ($out[$last]['t'] === '') {
                array_pop($out);
            }
        }

        return array_values($out);
    }

    /**
     * Inline-узел → фрагменты текста со своим начертанием.
     *
     * @param list<array<string, mixed>> $buffer
     * @param array<string, mixed> $font
     */
    private function collect(\DOMNode $node, array &$buffer, array $font): void
    {
        if ($node instanceof \DOMText) {
            $buffer[] = ['t' => $this->normalize($node->nodeValue ?? ''), 'f' => $font];

            return;
        }
        if (!$node instanceof \DOMElement) {
            return;
        }

        $tag = strtolower($node->tagName);
        if ($this->hidden($node) || in_array($tag, self::SKIPPED_TAGS, true)) {
            return;
        }
        if ($tag === 'br') {
            $buffer[] = ['br' => true];

            return;
        }

        $font = $this->fontFor($node, $tag, $font, $buffer);

        foreach ($node->childNodes as $child) {
            // Вложенные блоки внутри inline-контекста (заголовок, ячейка с
            // абзацами) читаются подряд; границу между ними задаёт перенос.
            if ($child instanceof \DOMElement && !in_array(strtolower($child->tagName), self::INLINE_TAGS, true)
                && !in_array(strtolower($child->tagName), self::SKIPPED_TAGS, true)) {
                $buffer[] = ['br' => true];
                $this->collect($child, $buffer, $font);
                $buffer[] = ['br' => true];
                continue;
            }
            $this->collect($child, $buffer, $font);
        }
    }

    /**
     * @param list<array<string, mixed>> $buffer
     * @param array<string, mixed> $font
     * @return array<string, mixed>
     */
    private function fontFor(\DOMElement $node, string $tag, array $font, array &$buffer): array
    {
        switch ($tag) {
            case 'strong':
            case 'b':
            case 'th':
                $font['bold'] = true;
                break;
            case 'em':
            case 'i':
            case 'cite':
                $font['italic'] = true;
                break;
            case 'u':
            case 'ins':
                $font['underline'] = 'single';
                break;
            case 's':
            case 'del':
                $font['strikethrough'] = true;
                break;
            case 'code':
            case 'kbd':
                $font['name'] = 'Consolas';
                break;
            case 'small':
                $font['size'] = max(7.0, (float) ($font['size'] ?? self::BODY_SIZE) - 1.5);
                break;
            case 'sub':
                $font['subScript'] = true;
                break;
            case 'sup':
                $font['superScript'] = true;
                break;
        }

        foreach (preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [] as $class) {
            $rule = self::SPAN_RULES[$class] ?? null;
            if ($rule === null) {
                continue;
            }
            if (($rule['br'] ?? false) === true) {
                $buffer[] = ['br' => true];
            }
            $font = $rule['font'] + $font;
        }

        return $font;
    }

    private function normalize(string $text): string
    {
        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    private function hidden(\DOMElement $el): bool
    {
        if (in_array(strtolower($el->tagName), self::SKIPPED_TAGS, true)) {
            return true;
        }
        $classes = preg_split('/\s+/', trim($el->getAttribute('class'))) ?: [];

        return array_intersect($classes, self::HIDDEN_CLASSES) !== []
            || $el->hasAttribute('hidden');
    }

    // -------------------------------------------------------------------- lists

    /**
     * @param array<string, mixed> $ctx
     */
    private function list(\DOMElement $el, AbstractContainer $container, array $ctx, int $depth): void
    {
        $ordered = strtolower($el->tagName) === 'ol';
        $style = self::BULLET_STYLE;
        if ($ordered) {
            // Каждый нумерованный список — отдельная нумерация, иначе второй
            // список продолжил бы счёт первого.
            $style = 'psytest-ol-' . (++$this->orderedLists);
            $this->phpWord->addNumberingStyle($style, $this->numbering(true));
        }

        foreach ($el->childNodes as $item) {
            if (!$item instanceof \DOMElement || strtolower($item->tagName) !== 'li') {
                continue;
            }

            $buffer = [];
            $nested = [];
            foreach ($item->childNodes as $child) {
                if ($child instanceof \DOMText) {
                    $buffer[] = ['t' => $this->normalize($child->nodeValue ?? ''), 'f' => $ctx['font']];
                    continue;
                }
                if (!$child instanceof \DOMElement || $this->hidden($child)) {
                    continue;
                }
                $tag = strtolower($child->tagName);
                if ($tag === 'ul' || $tag === 'ol') {
                    $nested[] = $child;
                } elseif (in_array($tag, self::INLINE_TAGS, true)) {
                    $this->collect($child, $buffer, $ctx['font']);
                } else {
                    // Абзац внутри пункта — продолжение текста пункта.
                    if ($buffer !== []) {
                        $buffer[] = ['br' => true];
                    }
                    $this->collect($child, $buffer, $ctx['font']);
                }
            }

            $buffer = $this->trimmed($buffer);
            $run = $container->addListItemRun(min($depth, 2), $style, [
                'spaceBefore' => 0,
                'spaceAfter' => $ctx['cell'] ? 20 : 60,
                'lineHeight' => $ctx['cell'] ? 1.0 : 1.125,
            ]);
            $this->fill($run, $buffer);

            foreach ($nested as $child) {
                $this->list($child, $container, $ctx, $depth + 1);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function numbering(bool $ordered): array
    {
        $levels = [];
        $formats = $ordered ? ['decimal', 'lowerLetter', 'lowerRoman'] : ['bullet', 'bullet', 'bullet'];
        $texts = $ordered ? ['%1.', '%2)', '%3.'] : ['•', '–', '•'];
        foreach ($formats as $level => $format) {
            $levels[] = [
                'format' => $format,
                'text' => $texts[$level],
                'left' => 567 * ($level + 1),
                'hanging' => 340,
                'tabPos' => 567 * ($level + 1),
            ];
        }

        return ['type' => 'multilevel', 'levels' => $levels];
    }

    // ------------------------------------------------------------------- tables

    /**
     * @param array<string, mixed> $ctx
     */
    private function table(\DOMElement $el, AbstractContainer $container, array $ctx): void
    {
        $rows = [];
        $caption = null;
        $this->gather($el, $rows, $caption);
        if ($rows === []) {
            return;
        }

        if ($caption !== null && trim($caption) !== '') {
            $container->addText(self::clean(trim($caption)), ['bold' => true, 'size' => self::BODY_SIZE], [
                'spaceBefore' => 120,
                'spaceAfter' => 60,
                'keepNext' => true,
            ]);
        }

        $columns = 0;
        foreach ($rows as $row) {
            $columns = max($columns, array_sum(array_column($row['cells'], 'span')));
        }
        if ($columns === 0) {
            return;
        }

        $width = (int) $ctx['width'];
        $widths = $this->columnWidths($rows, $columns, $width);
        $short = $this->shortColumns($rows, $columns);

        $table = $container->addTable([
            'borderSize' => 4,
            'borderColor' => 'A6A6A6',
            'cellMarginTop' => 30,
            'cellMarginBottom' => 30,
            'cellMarginLeft' => 70,
            'cellMarginRight' => 70,
            'layout' => TableStyle::LAYOUT_FIXED,
            'unit' => TblWidth::TWIP,
            'width' => $width,
        ]);

        foreach ($rows as $row) {
            $table->addRow(null, $row['header']
                ? ['tblHeader' => true, 'cantSplit' => true]
                : ['cantSplit' => true]);

            $column = 0;
            foreach ($row['cells'] as $cell) {
                $span = max(1, min($cell['span'], $columns - $column));
                $cellWidth = (int) array_sum(array_slice($widths, $column, $span));
                // noWrap по умолчанию включён в PhpWord: в ячейке он запрещает перенос слов.
                $style = ['valign' => 'center', 'noWrap' => false];
                if ($span > 1) {
                    $style['gridSpan'] = $span;
                }
                if ($row['header']) {
                    $style['bgColor'] = 'D9E2F3';
                } elseif ($row['category']) {
                    $style['bgColor'] = 'EDEDED';
                }

                $cellCtx = $ctx;
                $cellCtx['cell'] = true;
                $cellCtx['indent'] = 0;
                $cellCtx['width'] = max(600, $cellWidth - 140);
                $cellCtx['font'] = ['size' => self::TABLE_SIZE]
                    + (($row['header'] || $row['category']) ? ['bold' => true] : []);
                $cellCtx['align'] = ($span === 1 && ($short[$column] ?? false)) ? 'center' : null;

                $target = $table->addCell($cellWidth, $style);
                $this->blocks($cell['el'], $target, $cellCtx);
                $column += $span;
            }
            // Строка короче таблицы: недостающие ячейки пустые.
            while ($column < $columns) {
                $table->addCell($widths[$column]);
                $column++;
            }
        }

        // Между двумя таблицами Word обязан видеть абзац, иначе он их склеит.
        if (!$ctx['cell']) {
            $container->addText('', ['size' => 4], ['spaceBefore' => 0, 'spaceAfter' => 60]);
        }
    }

    /**
     * @param list<array{header: bool, category: bool, cells: list<array{el: \DOMElement, span: int}>}> $rows
     */
    private function gather(\DOMElement $table, array &$rows, ?string &$caption): void
    {
        foreach ($table->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ($tag === 'caption') {
                $caption = $child->textContent;
            } elseif (in_array($tag, ['thead', 'tbody', 'tfoot'], true)) {
                foreach ($child->childNodes as $tr) {
                    if ($tr instanceof \DOMElement && strtolower($tr->tagName) === 'tr') {
                        $rows[] = $this->row($tr, $tag === 'thead');
                    }
                }
            } elseif ($tag === 'tr') {
                $rows[] = $this->row($child, false);
            }
        }

        $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['cells'] !== []));
    }

    /**
     * @return array{header: bool, category: bool, cells: list<array{el: \DOMElement, span: int}>}
     */
    private function row(\DOMElement $tr, bool $inHead): array
    {
        $cells = [];
        $allHeaders = true;
        foreach ($tr->childNodes as $cell) {
            if (!$cell instanceof \DOMElement || !in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                continue;
            }
            $allHeaders = $allHeaders && strtolower($cell->tagName) === 'th';
            $cells[] = ['el' => $cell, 'span' => max(1, (int) $cell->getAttribute('colspan'))];
        }

        $classes = preg_split('/\s+/', trim($tr->getAttribute('class'))) ?: [];

        return [
            'header' => $cells !== [] && ($inHead || $allHeaders),
            'category' => in_array('category-header', $classes, true),
            'cells' => $cells,
        ];
    }

    /**
     * Ширины колонок по содержимому: узкие числовые колонки не должны
     * забирать место у названий шкал.
     *
     * @param list<array{header: bool, category: bool, cells: list<array{el: \DOMElement, span: int}>}> $rows
     * @return list<int>
     */
    private function columnWidths(array $rows, int $columns, int $total): array
    {
        $max = array_fill(0, $columns, 0);
        $sum = array_fill(0, $columns, 0);
        $count = array_fill(0, $columns, 0);
        $word = array_fill(0, $columns, 0);

        foreach ($rows as $row) {
            $column = 0;
            foreach ($row['cells'] as $cell) {
                if ($cell['span'] === 1 && $column < $columns) {
                    $text = trim($this->normalize($cell['el']->textContent));
                    $length = mb_strlen($text);
                    $max[$column] = max($max[$column], $length);
                    $sum[$column] += $length;
                    $count[$column]++;
                    foreach (preg_split('/\s+/u', $text) ?: [] as $piece) {
                        $word[$column] = max($word[$column], mb_strlen($piece));
                    }
                }
                $column += $cell['span'];
            }
        }

        $weights = [];
        $minimum = [];
        for ($i = 0; $i < $columns; $i++) {
            $average = $count[$i] > 0 ? $sum[$i] / $count[$i] : 4;
            $weights[$i] = max(min(55.0, 0.5 * $max[$i] + 0.5 * $average), 4.0);
            // Самое длинное слово не должно ломаться посередине (в шапке оно
            // жирное): ~100 твипов на знак 9 pt и поля ячейки.
            $minimum[$i] = max(520, $word[$i] * 100 + 160);
        }

        $weightSum = array_sum($weights);
        $widths = [];
        foreach ($weights as $i => $weight) {
            $widths[$i] = max($minimum[$i], (int) round($total * $weight / $weightSum));
        }

        // Минимумы сдвинули сумму: лишнее снимается с колонок, у которых есть запас.
        $excess = array_sum($widths) - $total;
        if ($excess > 0) {
            $slack = [];
            foreach ($widths as $i => $width) {
                $slack[$i] = max(0, $width - $minimum[$i]);
            }
            $slackSum = array_sum($slack);
            if ($slackSum > 0) {
                foreach ($widths as $i => $width) {
                    $widths[$i] = $width - (int) round(min($excess, $slackSum) * $slack[$i] / $slackSum);
                }
            }
        }

        // Остаток округления уходит в самую широкую колонку.
        $delta = $total - array_sum($widths);
        $widest = array_search(max($widths), $widths, true);
        $widths[$widest === false ? 0 : $widest] += $delta;

        return array_values($widths);
    }

    /**
     * Колонки с короткими значениями (баллы, уровни) выравниваются по центру.
     *
     * @param list<array{header: bool, category: bool, cells: list<array{el: \DOMElement, span: int}>}> $rows
     * @return array<int, bool>
     */
    private function shortColumns(array $rows, int $columns): array
    {
        $short = array_fill(0, $columns, true);
        $seen = array_fill(0, $columns, false);
        foreach ($rows as $row) {
            if ($row['header']) {
                continue;
            }
            $column = 0;
            foreach ($row['cells'] as $cell) {
                if ($cell['span'] === 1 && $column < $columns) {
                    $seen[$column] = true;
                    if (mb_strlen(trim($this->normalize($cell['el']->textContent))) > 12) {
                        $short[$column] = false;
                    }
                }
                $column += $cell['span'];
            }
        }
        // Первая колонка — подписи строк, её выравнивание не меняется.
        $short[0] = false;

        foreach ($seen as $i => $was) {
            if (!$was) {
                $short[$i] = false;
            }
        }

        return $short;
    }
}
