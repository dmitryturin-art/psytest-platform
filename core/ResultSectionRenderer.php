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

use Psr\Log\LoggerInterface;
use PsyTest\Modules\ProfileChartImageRenderer;
use PsyTest\Modules\ResultSection;

final class ResultSectionRenderer
{
    /**
     * @param \Closure(string, array<string, mixed>): string $blockRenderer
     *        Renders a block template name (without .twig extension) with
     *        data to HTML.
     * @param LoggerInterface|null $logger Журнал отката графика; по умолчанию
     *        канал `pdf`, создаётся только при сбое.
     */
    public function __construct(
        private readonly \Closure $blockRenderer,
        private ?LoggerInterface $logger = null,
    ) {
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
     * For profile charts, replaces the JS chart with a raster image declared
     * by the module (07.WP7b), or with a static HTML chart as a fallback.
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
                // `_pdf` — признак печатного контекста для блока: веб-обёртки
                // (контейнер прокрутки таблиц, 04.U1) в DomPDF не выводятся,
                // иначе меняется вёрстка документа.
                $html .= ($this->blockRenderer)($template, $section->data + ['_pdf' => true]);
            } elseif ($section->type === ResultSection::TYPE_RAW_HTML) {
                $html .= $section->data['html'] ?? '';
            }
        }
        return $html;
    }

    /**
     * Профильный график для PDF.
     *
     * Модуль объявляет в данных секции `pdf_image_renderer` — класс,
     * реализующий `ProfileChartImageRenderer`; картинка встраивается data URI
     * (DomPDF читает его без `isRemoteEnabled`) через блок
     * `blocks/profile-chart-pdf`. Если рендерер не объявлен, недоступен или
     * упал, остаётся прежняя HTML-подмена: PDF не должен падать из-за графика.
     *
     * @param array<string, mixed> $data
     */
    private function renderProfileChartHtml(array $data): string
    {
        $png = $this->profileChartImage($data);
        if ($png === null) {
            return $this->renderStaticProfileChartHtml($data);
        }

        return ($this->blockRenderer)('blocks/profile-chart-pdf', $data + [
            'image_src' => 'data:image/png;base64,' . base64_encode($png),
            '_pdf' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function profileChartImage(array $data): ?string
    {
        $class = $data['pdf_image_renderer'] ?? null;
        if (!is_string($class) || $class === '') {
            return null;
        }
        if (!class_exists($class) || !is_subclass_of($class, ProfileChartImageRenderer::class)) {
            $this->logger()->warning('profile chart image renderer is not a ProfileChartImageRenderer', [
                'renderer' => $class,
            ]);

            return null;
        }
        if (!$class::isAvailable()) {
            $this->logger()->warning('profile chart image renderer is unavailable, static chart used', [
                'renderer' => $class,
            ]);

            return null;
        }

        try {
            /** @var ProfileChartImageRenderer $renderer */
            $renderer = new $class();

            return $renderer->renderPng($data);
        } catch (\Throwable $e) {
            // Только класс и сообщение рендерера: баллов и данных клиента в журнале нет.
            $this->logger()->error('profile chart image failed, static chart used', [
                'renderer' => $class,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function logger(): LoggerInterface
    {
        return $this->logger ??= LoggerFactory::getLogger('pdf');
    }

    /**
     * Render a static profile chart for PDF output using HTML/CSS.
     * Generates a bar chart compatible with DomPDF (no JavaScript, no SVG).
     * Откат на случай, когда растровый график недоступен (нет GD, сбой).
     *
     * @param array<string, mixed> $data Profile chart data with 'scores' and 'labels' arrays.
     *
     * @return string HTML bar chart
     */
    private function renderStaticProfileChartHtml(array $data): string
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
