<?php

/**
 * Result Section Renderer
 *
 * Single owner of section-to-HTML dispatch for PDF output (Module API v2,
 * renderer contract). The web branch dispatches via result-layout.twig;
 * both branches render the same ResultSection list, and
 * RendererContractTest guards that every module's sections render in each.
 *
 * This class knows nothing about concrete modules: it only understands
 * ResultSection types.
 */

declare(strict_types=1);

namespace PsyTest\Core;

use PsyTest\Modules\ResultSection;

final class ResultSectionRenderer
{
    /**
     * @param \Closure(string, array<string, mixed>): string $blockRenderer
     *        Renders a block template name (without .twig extension) with
     *        data to HTML.
     */
    public function __construct(private readonly \Closure $blockRenderer)
    {
    }

    /**
     * Factory for production wiring on top of the shared View.
     */
    public static function forView(View $view): self
    {
        return new self(static fn (string $template, array $data): string => $view->render($template, $data));
    }

    /**
     * Render a ResultSection list to concatenated HTML blocks.
     * For profile charts, replaces JS canvas with a static HTML chart.
     *
     * @param list<ResultSection> $sections
     */
    public function renderToHtml(array $sections): string
    {
        $html = '';
        foreach ($sections as $section) {
            if ($section->type === ResultSection::TYPE_PROFILE_CHART) {
                // Replace JS canvas with static HTML chart for PDF
                $html .= $this->renderProfileChartHtml($section->data);
            } elseif ($section->block) {
                // Remove .twig extension if present, block renderer will add it
                $template = $section->block;
                if (str_ends_with($template, '.twig')) {
                    $template = substr($template, 0, -5);
                }
                $html .= ($this->blockRenderer)($template, $section->data);
            } elseif ($section->type === ResultSection::TYPE_RAW_HTML) {
                $html .= $section->data['html'] ?? '';
            }
        }
        return $html;
    }

    /**
     * Render a static profile chart for PDF output using HTML/CSS.
     * Generates a bar chart compatible with DomPDF (no JavaScript, no SVG).
     *
     * @param array{scores?: list<float|int>, labels?: list<string>} $data Profile chart data with 'scores' and 'labels' arrays.
     *
     * @return string HTML bar chart
     */
    private function renderProfileChartHtml(array $data): string
    {
        $scores = $data['scores'] ?? [];
        $labels = $data['labels'] ?? [];
        $count = count($scores);

        if ($count === 0) {
            return '<p style="color:#999;text-align:center;">Данные профиля недоступны</p>';
        }

        $tMin = 20;
        $tMax = 120;
        $barWidth = 28;
        $maxHeight = 200;

        // Колонки — ячейки одной строки таблицы, а не `inline-block`:
        // DomPDF не раскладывает inline-block, и весь профиль вытягивался в
        // вертикальный столбик на десяток страниц (найдено при 07.K5j на
        // выгрузке кейса). Данные, порядок шкал и цвета те же.
        // Столбец целиком лежит в одной ячейке — иначе DomPDF разрывает
        // таблицу между строками, и подписи уезжают на следующую страницу.
        // Выравнивание по низу делает распорка, а не `vertical-align`:
        // высоту ячейки DomPDF в этом случае считает по содержимому.
        $bars = '';
        $captions = '';
        for ($i = 0; $i < $count; $i++) {
            $t = max($tMin, min($tMax, (float) $scores[$i]));
            $barH = max(1, (int) round(($t - $tMin) / ($tMax - $tMin) * $maxHeight));
            $color = ($t >= 65 || $t <= 35) ? '#c0392b' : '#3498db';
            $cell = 'padding:0 2px;text-align:center;border:none;';

            $bars .= '<td style="' . $cell . '">'
                . '<div style="height:' . ($maxHeight - $barH) . 'px;"></div>'
                . '<div style="font-size:7pt;font-weight:bold;color:' . $color . ';">' . (int) $t . '</div>'
                . '<div style="width:' . $barWidth . 'px;height:' . $barH . 'px;background:' . $color . ';margin:0 auto;"></div>'
                . '</td>';
            $captions .= '<td style="' . $cell . 'font-size:7pt;color:#333;border-top:2px solid #333;">'
                . htmlspecialchars($labels[$i] ?? '') . '</td>';
        }

        // Заголовок — строка самой таблицы: отдельный `h3` и `caption` остаются
        // на прежней странице, когда график переносится целиком.
        return '<div style="margin:1em 0;text-align:center;">'
            . '<table style="width:100%;border-collapse:collapse;border:none;page-break-inside:avoid;">'
            . '<tr><td colspan="' . $count . '" style="border:none;padding:0 0 0.5em;text-align:center;'
            . 'font-size:11pt;font-weight:bold;color:#2c3e50;">Профиль личности (T-баллы)</td></tr>'
            . '<tr>' . $bars . '</tr>'
            . '<tr>' . $captions . '</tr>'
            . '</table>'
            . '<div style="font-size:7pt;color:#7f8c8d;margin-top:4px;">Норма: 30–70 T</div>'
            . '</div>';
    }
}
