<?php

declare(strict_types=1);

namespace PsyTest\Core;

/**
 * Разметка ИИ-разбора в HTML.
 *
 * Текст приходит от внешней модели, то есть это неконтролируемый ввод, который
 * попадёт на страницу. Поэтому порядок обратный привычному: **сначала
 * экранируется всё**, и только потом в уже безопасный текст добавляются наши
 * собственные теги. Вставить чужую разметку таким способом нельзя в принципе —
 * к моменту появления первого тега любой `<` уже превращён в `&lt;`.
 *
 * Поддерживается ровно то, что нужно отчёту: заголовки, абзацы, списки,
 * таблицы, выделение и код. Ссылок и картинок нет намеренно: модель не должна
 * уводить читателя куда бы то ни было, а без ссылок исчезает и весь класс
 * проблем с `javascript:` и подобными схемами.
 */
final class ReportMarkdown
{
    public static function toHtml(string $markdown): string
    {
        $escaped = htmlspecialchars($markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = preg_split('/\R/u', $escaped) ?: [];

        $html = [];
        $buffer = [];
        $mode = null;

        foreach ($lines as $line) {
            [$kind, $text] = self::classify(trim($line));

            // Строка другого вида закрывает накопленный блок. Отдельные строки
            // (заголовок, черта, пустая) закрывают его и ничего не накапливают.
            if ($kind !== $mode) {
                $html[] = self::closeBlock($mode, $buffer);
                $buffer = [];
                $mode = in_array($kind, ['blank', 'heading', 'hr'], true) ? null : $kind;
            }

            match ($kind) {
                'blank' => null,
                'heading' => $html[] = $text,
                'hr' => $html[] = '<hr>',
                default => $buffer[] = $text,
            };
        }

        $html[] = self::closeBlock($mode, $buffer);

        return implode("\n", array_filter($html, static fn (string $block): bool => $block !== ''));
    }

    /**
     * Что представляет собой строка и что от неё останется в выводе.
     *
     * @return array{0: string, 1: string}
     */
    private static function classify(string $line): array
    {
        if ($line === '') {
            return ['blank', ''];
        }

        if (preg_match('/^(#{1,4})\s+(.*)$/u', $line, $m) === 1) {
            // h1 принадлежит странице результата, отчёт начинается уровнем ниже.
            $level = strlen($m[1]) + 1;

            return ['heading', sprintf('<h%d>%s</h%d>', $level, self::inline($m[2]), $level)];
        }

        if (preg_match('/^([-*_])\1{2,}$/u', $line) === 1) {
            return ['hr', ''];
        }

        if (str_starts_with($line, '|') && str_ends_with($line, '|')) {
            return ['table', $line];
        }

        if (preg_match('/^&gt;\s?(.*)$/u', $line, $m) === 1) {
            return ['quote', self::inline($m[1])];
        }

        if (preg_match('/^[-*]\s+(.*)$/u', $line, $m) === 1) {
            return ['ul', self::inline($m[1])];
        }

        if (preg_match('/^\d{1,3}[.)]\s+(.*)$/u', $line, $m) === 1) {
            return ['ol', self::inline($m[1])];
        }

        return ['p', self::inline($line)];
    }

    /**
     * @param list<string> $buffer
     */
    private static function closeBlock(?string $mode, array $buffer): string
    {
        if ($mode === null || $buffer === []) {
            return '';
        }

        $item = static fn (string $i): string => '<li>' . $i . '</li>';

        return match ($mode) {
            'ul' => '<ul>' . implode('', array_map($item, $buffer)) . '</ul>',
            'ol' => '<ol>' . implode('', array_map($item, $buffer)) . '</ol>',
            'quote' => '<blockquote><p>' . implode('<br>', $buffer) . '</p></blockquote>',
            'table' => self::renderTable($buffer),
            default => '<p>' . implode('<br>', $buffer) . '</p>',
        };
    }

    /**
     * @param list<string> $rows Строки таблицы вида «| a | b |».
     */
    private static function renderTable(array $rows): string
    {
        $cells = [];
        foreach ($rows as $row) {
            $trimmed = trim($row, '|');
            // Строка-разделитель заголовка таблицы содержит только дефисы,
            // двоеточия и пробелы — она не данные и не рисуется.
            if (preg_match('/^[\s|:\-]+$/u', $trimmed) === 1) {
                continue;
            }
            $cells[] = array_map('trim', explode('|', $trimmed));
        }

        if ($cells === []) {
            return '';
        }

        $head = array_shift($cells);
        $html = '<div class="report-table-scroll"><table class="report-table"><thead><tr>';
        foreach ($head as $cell) {
            $html .= '<th>' . self::inline($cell) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($cells as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . self::inline($cell) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table></div>';
    }

    /**
     * Выделение внутри уже экранированной строки.
     */
    private static function inline(string $text): string
    {
        $text = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $text) ?? $text;
        $text = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/u', '<em>$1</em>', $text) ?? $text;

        return $text;
    }
}
