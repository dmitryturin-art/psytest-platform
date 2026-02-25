<?php
/**
 * PDF Generator
 * 
 * Generates PDF reports from test results using DomPDF
 */

declare(strict_types=1);

namespace PsyTest\Core;

use Dompdf\Dompdf;
use Dompdf\Options;

class PDFGenerator
{
    private Dompdf $dompdf;
    private string $storagePath;
    
    public function __construct(?string $storagePath = null)
    {
        $this->storagePath = $storagePath ?? __DIR__ . '/../storage/pdfs';
        
        // Ensure storage directory exists
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
        
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('defaultPaperSize', 'a4');
        $options->set('defaultPaperOrientation', 'portrait');
        
        $this->dompdf = new Dompdf($options);
    }
    
    /**
     * Generate PDF from HTML content
     * 
     * @param string $html HTML content
     * @param string $filename Output filename (without path)
     * @param bool $saveToFile Save to storage and return path
     * @return string PDF binary content or file path
     */
    public function generate(string $html, string $filename, bool $saveToFile = true): string
    {
        // Add base styles
        $fullHtml = $this->wrapInHtml($html);
        
        $this->dompdf->loadHtml($fullHtml);
        $this->dompdf->setPaper('a4', 'portrait');
        $this->dompdf->render();
        
        if ($saveToFile) {
            return $this->saveToFile($this->dompdf->output(), $filename);
        }
        
        return $this->dompdf->output();
    }
    
    /**
     * Generate test result PDF
     * 
     * @param array $session Session data
     * @param array $test Test metadata
     * @param string $resultsHtml Rendered results HTML
     * @return string Path to saved PDF
     */
    public function generateTestResult(
        array $session,
        array $test,
        string $resultsHtml
    ): string {
        $filename = "result_{$session['id']}.pdf";
        
        $html = $this->renderTestResultTemplate($session, $test, $resultsHtml);
        
        return $this->generate($html, $filename, true);
    }
    
    /**
     * Generate AI interpretation PDF
     * 
     * @param array $session Session data
     * @param array $test Test metadata
     * @param string $interpretationText AI interpretation text
     * @return string Path to saved PDF
     */
    public function generateAIInterpretation(
        array $session,
        array $test,
        string $interpretationText
    ): string {
        $filename = "interpretation_{$session['id']}.pdf";
        
        $html = $this->renderInterpretationTemplate($session, $test, $interpretationText);
        
        return $this->generate($html, $filename, true);
    }
    
    /**
     * Generate pair comparison PDF
     * 
     * @param array $comparison Comparison data
     * @param array $test Test metadata
     * @param string $comparisonHtml Rendered comparison HTML
     * @return string Path to saved PDF
     */
    public function generatePairComparison(
        array $comparison,
        array $test,
        string $comparisonHtml
    ): string {
        $filename = "pair_{$comparison['id']}.pdf";
        
        $html = $this->renderPairTemplate($comparison, $test, $comparisonHtml);
        
        return $this->generate($html, $filename, true);
    }
    
    /**
     * Save PDF content to file
     */
    private function saveToFile(string $content, string $filename): string
    {
        $filepath = $this->storagePath . '/' . $filename;
        
        if (file_put_contents($filepath, $content) === false) {
            throw new \RuntimeException("Failed to save PDF: $filepath");
        }
        
        // Return relative path for storage in database
        return '/storage/pdfs/' . $filename;
    }
    
    /**
     * Wrap content in HTML template
     */
    private function wrapInHtml(string $content, string $title = 'Report'): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>$title</title>
    <style>
        @page {
            margin: 20mm;
        }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11pt;
            line-height: 1.5;
            color: #333;
        }
        h1, h2, h3, h4 {
            color: #2c3e50;
            margin-top: 1em;
            margin-bottom: 0.5em;
        }
        h1 {
            font-size: 18pt;
            border-bottom: 2px solid #3498db;
            padding-bottom: 0.5em;
        }
        h2 {
            font-size: 14pt;
            border-bottom: 1px solid #bdc3c7;
            padding-bottom: 0.3em;
        }
        p {
            margin: 0.5em 0;
            text-align: justify;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 1em 0;
        }
        th, td {
            border: 1px solid #bdc3c7;
            padding: 8px 12px;
            text-align: left;
        }
        th {
            background-color: #3498db;
            color: white;
            font-weight: bold;
        }
        tr:nth-child(even) {
            background-color: #ecf0f1;
        }
        .header {
            text-align: center;
            margin-bottom: 2em;
        }
        .header h1 {
            border: none;
            color: #2c3e50;
        }
        .meta-info {
            font-size: 9pt;
            color: #7f8c8d;
            margin-bottom: 1em;
        }
        .disclaimer {
            font-size: 8pt;
            color: #95a5a6;
            border-top: 1px solid #bdc3c7;
            padding-top: 1em;
            margin-top: 2em;
            text-align: center;
        }
        .chart-placeholder {
            text-align: center;
            padding: 2em;
            background: #ecf0f1;
            border: 1px dashed #bdc3c7;
            margin: 1em 0;
        }
        ul, ol {
            margin: 0.5em 0;
            padding-left: 2em;
        }
        li {
            margin: 0.3em 0;
        }
    </style>
</head>
<body>
    $content
</body>
</html>
HTML;
    }
    
    /**
     * Render test result template
     */
    private function renderTestResultTemplate(
        array $session,
        array $test,
        string $resultsHtml
    ): string {
        $date = date('d.m.Y H:i', strtotime($session['created_at']));
        
        $content = <<<HTML
<div class="header">
    <h1>{$test['name']}</h1>
    <p>Результаты тестирования</p>
</div>

<div class="meta-info">
    <p><strong>Дата:</strong> $date</p>
    <p><strong>ID сессии:</strong> {$session['id']}</p>
    {$this->renderUserInfo($session)}
</div>

<div class="results">
    $resultsHtml
</div>

<div class="disclaimer">
    <p>Результаты данного тестирования носят ознакомительный характер 
    и не заменяют очную консультацию специалиста.</p>
    <p>Конфиденциально. Документ сгенерирован автоматически.</p>
</div>
HTML;
        
        return $this->wrapInHtml($content, $test['name'] . ' - Результаты');
    }
    
    /**
     * Render AI interpretation template
     */
    private function renderInterpretationTemplate(
        array $session,
        array $test,
        string $interpretationText
    ): string {
        $date = date('d.m.Y H:i', strtotime($session['created_at']));
        
        $content = <<<HTML
<div class="header">
    <h1>Развёрнутая интерпретация</h1>
    <p>{$test['name']}</p>
</div>

<div class="meta-info">
    <p><strong>Дата тестирования:</strong> $date</p>
    <p><strong>ID сессии:</strong> {$session['id']}</p>
</div>

<div class="interpretation">
    $interpretationText
</div>

<div class="disclaimer">
    <p><strong>Важно:</strong> Данная интерпретация сгенерирована искусственным интеллектом 
    на основе предоставленных результатов тестирования. Она носит исключительно ознакомительный 
    характер и не является диагнозом или заменой профессиональной консультации.</p>
    <p>Для получения квалифицированной интерпретации и рекомендаций обратитесь к специалисту.</p>
</div>
HTML;
        
        return $this->wrapInHtml($content, 'Интерпретация результатов');
    }
    
    /**
     * Render pair comparison template
     */
    private function renderPairTemplate(
        array $comparison,
        array $test,
        string $comparisonHtml
    ): string {
        $date = date('d.m.Y H:i', strtotime($comparison['generated_at']));
        
        $content = <<<HTML
<div class="header">
    <h1>Сравнительный анализ</h1>
    <p>{$test['name']}</p>
</div>

<div class="meta-info">
    <p><strong>Дата генерации:</strong> $date</p>
    <p><strong>ID сравнения:</strong> {$comparison['id']}</p>
</div>

<div class="comparison">
    $comparisonHtml
</div>

<div class="disclaimer">
    <p>Результаты сравнения носят ознакомительный характер.</p>
</div>
HTML;
        
        return $this->wrapInHtml($content, 'Сравнение результатов');
    }
    
    /**
     * Render user info section
     */
    private function renderUserInfo(array $session): string
    {
        $parts = [];
        
        if (!empty($session['user_email'])) {
            $parts[] = "<strong>Email:</strong> " . htmlspecialchars($session['user_email']);
        }
        
        if (!empty($session['user_name'])) {
            $parts[] = "<strong>Имя:</strong> " . htmlspecialchars($session['user_name']);
        }
        
        if (!empty($session['demographics'])) {
            $demo = is_string($session['demographics']) 
                ? json_decode($session['demographics'], true) 
                : $session['demographics'];
            
            if (!empty($demo['age'])) {
                $parts[] = "<strong>Возраст:</strong> " . (int)$demo['age'];
            }
            if (!empty($demo['gender'])) {
                $parts[] = "<strong>Пол:</strong> " . htmlspecialchars($demo['gender']);
            }
        }
        
        if (empty($parts)) {
            return '';
        }
        
        return '<p>' . implode(' | ', $parts) . '</p>';
    }
    
    /**
     * Get storage path
     */
    public function getStoragePath(): string
    {
        return $this->storagePath;
    }
}
