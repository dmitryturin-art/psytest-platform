<?php

declare(strict_types=1);

namespace PsyTest\Core;

use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Style;
use PhpOffice\PhpWord\Style\Language;
use PsyTest\Modules\ResultSection;

/**
 * Выгрузка кейса специалиста в Word (07.K5m).
 *
 * Владелец дорабатывает заключение в Word и печатает его, поэтому документ
 * нужен редактируемый: настоящие заголовки, таблицы и списки, а не картинка
 * страницы. Содержимое берётся из того же `CaseExportPresenter::build()`, что
 * питает PDF и версию для печати, — собственного сбора данных здесь нет, и
 * состав трёх выходов не может разойтись.
 *
 * Секции результата рендерятся теми же блоками Twig, что и PDF, и переводятся
 * в элементы Word через `DocxHtmlWriter`; канонический профиль СМИЛ берётся
 * тем же серверным рендерером, что и в PDF (`ResultSectionRenderer::profileChartImage()`).
 * Если картинку нарисовать нельзя (нет GD), документ не падает: график
 * заменяет короткий абзац, а значения остаются в таблице шкал.
 *
 * Файл собирается в памяти процесса; временный файл, который требуется
 * ZipArchive, создаётся и удаляется внутри `render()`. Идентификаторов сессии,
 * токенов и ссылок результата в документе нет — их нет и в `$document`.
 */
final class CaseExportDocx
{
    public const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const FONT = 'Calibri';

    /** Ширина графика профиля, пункты (16 см). */
    private const CHART_WIDTH_PT = 453;

    /**
     * @param \Closure(string, array<string, mixed>): string $blockRenderer
     *        Рендер блока Twig без расширения (как у `ResultSectionRenderer`).
     */
    public function __construct(private readonly \Closure $blockRenderer)
    {
    }

    /**
     * Готовый .docx одной строкой.
     *
     * @param array<string, mixed> $document Результат `CaseExportPresenter::build()`.
     */
    public function render(array $document): string
    {
        // Реестр стилей PhpWord статический: без сброса нумерация и заголовки
        // прошлого документа протекли бы в следующий.
        Style::resetStyles();
        Settings::setOutputEscapingEnabled(true);

        // PhpWord 1.4 на PHP 8.5 сыплет E_DEPRECATED («null как индекс массива»);
        // при display_errors они попали бы прямо в тело скачиваемого файла.
        // Гасятся только предупреждения самой библиотеки, остальные идут дальше.
        set_error_handler(
            static fn (int $level, string $message, string $file): bool => str_contains($file, '/phpoffice/phpword/'),
            E_DEPRECATED,
        );
        try {
            return $this->assemble($document);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param array<string, mixed> $document
     */
    private function assemble(array $document): string
    {
        $phpWord = $this->newDocument($document);
        $section = $phpWord->addSection([
            'pageSizeW' => 11906,
            'pageSizeH' => 16838,
            'marginTop' => 1134,
            'marginBottom' => 1134,
            'marginLeft' => 1134,
            'marginRight' => 1134,
            'footerHeight' => 567,
        ]);
        $writer = new DocxHtmlWriter($phpWord);

        $this->footer($section);
        $this->head($section, $document);
        $this->body($section, $writer, $document);

        return $this->serialize($phpWord);
    }

    // ----------------------------------------------------------------- document

    /**
     * @param array<string, mixed> $document
     */
    private function newDocument(array $document): PhpWord
    {
        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName(self::FONT);
        $phpWord->setDefaultFontSize(DocxHtmlWriter::BODY_SIZE);
        $phpWord->getSettings()->setThemeFontLang(new Language('ru-RU'));

        $info = $phpWord->getDocInfo();
        $info->setCreator('PsyTest');
        $info->setLastModifiedBy('PsyTest');
        $info->setTitle(DocxHtmlWriter::clean((string) $document['header']['test_name']));
        $info->setCompany('');

        // «Заголовок 1…3» — настоящие стили Word: по ним работают область
        // навигации и автооглавление.
        $phpWord->addTitleStyle(0, ['bold' => true, 'size' => 22, 'color' => '1F3864'], [
            'spaceAfter' => 60,
        ]);
        $sizes = [1 => 16, 2 => 13, 3 => 12, 4 => 11, 5 => 10.5, 6 => 10.5];
        foreach ($sizes as $level => $size) {
            $phpWord->addTitleStyle($level, [
                'bold' => true,
                'size' => $size,
                'color' => $level === 1 ? '1F3864' : '2F5496',
            ], [
                'spaceBefore' => $level === 1 ? 240 : 180,
                'spaceAfter' => 80,
                'keepNext' => true,
            ]);
        }

        return $phpWord;
    }

    private function footer(Section $section): void
    {
        $section->addFooter()->addPreserveText(
            'Конфиденциально · стр. {PAGE} из {NUMPAGES}',
            ['size' => 9, 'color' => '7F7F7F'],
            ['alignment' => 'center'],
        );
    }

    /**
     * Название, шапка кейса, пометка о конфиденциальности.
     *
     * @param array<string, mixed> $document
     */
    private function head(Section $section, array $document): void
    {
        /** @var array<string, mixed> $header */
        $header = $document['header'];

        $section->addTitle(DocxHtmlWriter::clean((string) $header['test_name']), 0);
        $section->addText('Выгрузка кейса специалиста', ['size' => 12, 'color' => '595959'], ['spaceAfter' => 160]);

        $label = $header['client_label'] ?? null;
        if (is_string($label) && $label !== '') {
            $this->fact($section, 'Клиент: ', $label);
        }
        $this->fact($section, 'Дата прохождения: ', $this->completed($header['completed_at'] ?? null));
        $section->addText(DocxHtmlWriter::clean((string) $header['prepared_by']), ['bold' => true], ['spaceAfter' => 60]);
        $section->addText(
            DocxHtmlWriter::clean((string) $header['confidential']),
            ['bold' => true, 'color' => 'C0392B'],
            ['spaceAfter' => 120],
        );
    }

    private function fact(Section $section, string $label, string $value): void
    {
        $run = $section->addTextRun(['spaceAfter' => 40]);
        $run->addText($label, ['bold' => true]);
        $run->addText(DocxHtmlWriter::clean($value));
    }

    private function completed(mixed $value): string
    {
        $time = is_string($value) ? strtotime($value) : false;

        return $time === false ? 'не завершено' : date('d.m.Y H:i', $time);
    }

    // --------------------------------------------------------------------- body

    /**
     * @param array<string, mixed> $document
     */
    private function body(Section $section, DocxHtmlWriter $writer, array $document): void
    {
        /** @var array<string, mixed>|null $pair */
        $pair = $document['pair'];
        /** @var list<ResultSection> $sections */
        $sections = $document['sections'];
        /** @var array<string, bool> $options */
        $options = $document['options'];

        if ($pair !== null) {
            $section->addTitle('Парный результат', 1);
            $section->addText('Этот кейс — ' . DocxHtmlWriter::clean((string) $pair['label']) . '.');
            // График совмещённых профилей — SVG, в Word его нет: остаётся
            // таблица сравнения, как и в PDF.
            $this->sections($section, $writer, array_values(array_filter(
                $pair['sections'],
                static fn (ResultSection $s): bool => $s->type !== ResultSection::TYPE_PAIR_CHART,
            )));
            $section->addPageBreak();
        }

        $section->addTitle($pair !== null ? 'Индивидуальный результат' : 'Базовый результат', 1);
        $this->sections($section, $writer, $sections);

        if ($options['include_answers']) {
            $section->addPageBreak();
            $this->answers($section, $writer, $document);
        }

        $professional = $document['professional'] ?? null;
        if (is_array($professional)) {
            $section->addPageBreak();
            $section->addTitle('Профессиональное заключение', 1);
            $writer->append($section, (string) $professional['html']);
        }

        $clear = $document['clear'] ?? null;
        if (is_array($clear)) {
            $section->addPageBreak();
            $section->addTitle('Понятный разбор', 1);
            $this->clearStatus($section, $clear);
            $writer->append($section, (string) $clear['html']);
        }

        $note = $document['note'] ?? null;
        if (is_string($note) && $note !== '') {
            $section->addPageBreak();
            $section->addTitle('Заметка к назначению', 1);
            foreach (preg_split('/\R/u', $note) ?: [] as $line) {
                if (trim($line) !== '') {
                    $section->addText(DocxHtmlWriter::clean(trim($line)));
                }
            }
        }

        $this->closing($section, $document);
    }

    /**
     * @param list<ResultSection> $sections
     */
    private function sections(Section $section, DocxHtmlWriter $writer, array $sections): void
    {
        foreach ($sections as $result) {
            if ($result->type === ResultSection::TYPE_PAIR_CHART || $result->type === ResultSection::TYPE_PAIR_INVITE) {
                continue;
            }

            if ($result->title !== '') {
                $section->addTitle(DocxHtmlWriter::clean($result->title), 2);
            }

            if ($result->type === ResultSection::TYPE_PROFILE_CHART) {
                $this->profileChart($section, $result);
            } elseif ($result->block !== null && $result->block !== '') {
                $template = str_ends_with($result->block, '.twig') ? substr($result->block, 0, -5) : $result->block;
                $writer->append($section, ($this->blockRenderer)($template, $result->data + ['_pdf' => true]));
            } elseif ($result->type === ResultSection::TYPE_RAW_HTML) {
                $writer->append($section, (string) ($result->data['html'] ?? ''));
            }
        }
    }

    /**
     * График профиля картинкой рендерера модуля либо абзац-заглушка.
     */
    private function profileChart(Section $section, ResultSection $result): void
    {
        $png = (new ResultSectionRenderer($this->blockRenderer))->profileChartImage($result->data);
        $size = $png !== null ? @getimagesizefromstring($png) : false;

        if ($png === null || !is_array($size) || $size[0] < 1) {
            $section->addText(
                'График недоступен в этой среде. Значения шкал приведены в таблице ниже.',
                ['italic' => true, 'color' => self::noteColor()],
            );

            return;
        }

        $section->addImage($png, [
            'width' => self::CHART_WIDTH_PT,
            'height' => (int) round(self::CHART_WIDTH_PT * $size[1] / $size[0]),
            'alignment' => 'center',
        ]);
        $section->addText(
            'Кривая 1: шкалы достоверности (L, F, K). Кривая 2: клинические шкалы (1–9, 0). '
            . 'Зелёная точка — норма 30–70 T, малиновая — отклонение (менее 30 T или более 70 T). '
            . 'Число у точки — T-балл шкалы.',
            ['size' => 8.5, 'color' => self::noteColor()],
            ['alignment' => 'center', 'spaceAfter' => 120],
        );
    }

    /**
     * Анкета(ы) таблицей: те же строки, что в PDF.
     *
     * @param array<string, mixed> $document
     */
    private function answers(Section $section, DocxHtmlWriter $writer, array $document): void
    {
        /** @var array<string, mixed>|null $pair */
        $pair = $document['pair'];

        if ($pair === null) {
            $section->addTitle('Анкета по пунктам', 1);
            $writer->append($section, $this->answersTable($document['answers']));

            return;
        }

        $section->addTitle('Анкеты обоих партнёров', 1);
        foreach ($pair['questionnaires'] as $sheet) {
            $section->addTitle(
                DocxHtmlWriter::clean((string) $sheet['label']) . ($sheet['is_case'] ? ' · этот кейс' : ''),
                2,
            );
            $writer->append($section, $this->answersTable($sheet['rows']));
        }
    }

    /**
     * @param list<array<string, string|int|null>> $rows
     */
    private function answersTable(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $dual = array_key_exists('self_answer', $rows[0]);

        $html = '<table><thead><tr><th>№</th><th>Пункт</th>'
            . ($dual ? '<th>Я</th><th>Партнёр (по мнению респондента)</th>' : '<th>Ответ</th><th>Балл</th>')
            . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td>' . $e($row['number'] ?? '') . '</td><td>' . $e($row['question'] ?? '') . '</td>';
            $html .= $dual
                ? '<td>' . $e($row['self_answer'] ?? '') . '</td><td>' . $e($row['partner_answer'] ?? '') . '</td>'
                : '<td>' . $e($row['answer'] ?? '') . '</td><td>' . $e($row['score'] ?? '') . '</td>';
            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }

    /**
     * Опубликован ли разбор клиенту — специалист должен видеть это в файле.
     *
     * @param array<string, mixed> $clear
     */
    private function clearStatus(Section $section, array $clear): void
    {
        if ($clear['published']) {
            $text = 'Опубликовано клиенту, версия №' . (int) $clear['revision_no'] . ' от ' . $clear['date'];
            $color = '1F5FA6';
        } else {
            $text = 'Черновик, не опубликован — версия №' . (int) $clear['revision_no'];
            $color = '8A5A06';
        }

        $section->addText(DocxHtmlWriter::clean($text), ['bold' => true, 'color' => $color], ['spaceAfter' => 120]);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function closing(Section $section, array $document): void
    {
        $small = ['size' => 9, 'color' => self::noteColor()];
        $section->addText('', null, [
            'borderBottomSize' => 6,
            'borderBottomColor' => 'A6A6A6',
            'spaceBefore' => 240,
            'spaceAfter' => 80,
        ]);
        $section->addText('Документ сформирован ' . DocxHtmlWriter::clean((string) $document['generated_at']) . '.', $small);
        $section->addText(DocxHtmlWriter::clean((string) $document['disclaimer']), $small);
        $section->addText(
            DocxHtmlWriter::clean((string) $document['header']['confidential']) . '. Документ сгенерирован автоматически.',
            $small,
        );
    }

    private static function noteColor(): string
    {
        return '595959';
    }

    // ------------------------------------------------------------------- output

    private function serialize(PhpWord $phpWord): string
    {
        // ZipArchive пишет только в файл: он живёт ровно до чтения.
        $path = tempnam(sys_get_temp_dir(), 'psytest-docx-');
        if ($path === false) {
            throw new \RuntimeException('Не удалось создать временный файл для документа.');
        }

        try {
            IOFactory::createWriter($phpWord, 'Word2007')->save($path);
            $this->normalizePackage($path);
            $bytes = file_get_contents($path);
        } finally {
            @unlink($path);
        }

        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('Документ Word не собран.');
        }

        return $bytes;
    }

    /**
     * PhpWord 1.4 пишет в таблице `w:tblGrid` раньше `w:tblPr`, а схема
     * OOXML (CT_Tbl) требует обратного порядка. Word это обычно прощает, но
     * «обычно» для файла, который открывает клиницист, мало: порядок
     * исправляется на готовом пакете.
     */
    private function normalizePackage(string $path): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Документ Word не открылся для проверки.');
        }

        $xml = $zip->getFromName('word/document.xml');
        if (is_string($xml)) {
            $fixed = preg_replace(
                '~(<w:tblGrid>(?:(?!</w:tblGrid>).)*</w:tblGrid>)(<w:tblPr>(?:(?!</w:tblPr>).)*</w:tblPr>)~s',
                '$2$1',
                $xml,
            );
            if (is_string($fixed) && $fixed !== $xml) {
                $zip->addFromString('word/document.xml', $fixed);
            }
        }

        $zip->close();
    }
}
