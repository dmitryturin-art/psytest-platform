<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\PDFGenerator;

final class PDFGeneratorSmokeTest extends TestCase
{
    public function testGeneratesPdfFromCyrillicHtmlInMemory(): void
    {
        $generator = new PDFGenerator();

        $pdf = $generator->generate('<h1>Проверка PDF</h1><p>Кириллица</p>', 'smoke.pdf', false);

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(500, strlen($pdf));
    }
}
