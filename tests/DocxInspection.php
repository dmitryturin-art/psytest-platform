<?php

declare(strict_types=1);

namespace PsyTest\Tests;

/**
 * Разбор готового .docx в тестах: архив, XML-части, текст и таблицы.
 */
trait DocxInspection
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * @return array<string, string> Имя части архива => содержимое.
     */
    private function unzip(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'psytest-test-docx-');
        self::assertIsString($path);
        file_put_contents($path, $bytes);

        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path) === true, 'Результат не является zip-архивом.');
            $parts = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $parts[$name] = (string) $zip->getFromIndex($i);
            }
            $zip->close();
        } finally {
            @unlink($path);
        }

        return $parts;
    }

    /** Часть архива как валидный XML. */
    private function xmlPart(string $content): \DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($content);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertTrue($loaded, 'XML невалиден: ' . ($errors[0]->message ?? ''));

        return $dom;
    }

    private function xpath(\DOMDocument $dom): \DOMXPath
    {
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', self::W_NS);

        return $xpath;
    }

    /** Весь текст документа, абзацы через перевод строки. */
    private function plainText(\DOMDocument $dom): string
    {
        $xpath = $this->xpath($dom);
        $lines = [];
        foreach ($xpath->query('//w:p') ?: [] as $paragraph) {
            $line = '';
            foreach ($xpath->query('.//w:t', $paragraph) ?: [] as $t) {
                $line .= $t->textContent;
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /** Таблица с наибольшим числом строк. */
    private function largestTable(\DOMDocument $dom): \DOMElement
    {
        $xpath = $this->xpath($dom);
        $best = null;
        $bestRows = -1;
        foreach ($xpath->query('//w:tbl') ?: [] as $table) {
            $rows = $xpath->query('./w:tr', $table);
            $count = $rows === false ? 0 : $rows->length;
            if ($count > $bestRows) {
                $best = $table;
                $bestRows = $count;
            }
        }
        self::assertInstanceOf(\DOMElement::class, $best, 'В документе нет таблиц.');

        return $best;
    }
}
