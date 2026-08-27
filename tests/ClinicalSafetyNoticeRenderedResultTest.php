<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\ClinicalSafetyNotice;
use PsyTest\Core\TemplateFunctions;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ClinicalSafetyNoticeRenderedResultTest extends TestCase
{
    public function testRenderedBdiResultShowsApprovedNoticeBeforeActions(): void
    {
        $html = $this->renderResult([
            'message' => ClinicalSafetyNotice::MESSAGE,
        ]);
        $xpath = $this->xpath($html);

        $notices = $xpath->query('//aside[contains(concat(" ", normalize-space(@class), " "), " clinical-safety-notice ")]');
        self::assertNotFalse($notices);
        self::assertCount(1, $notices);
        self::assertSame('alert', $notices->item(0)?->attributes?->getNamedItem('role')?->nodeValue);
        self::assertStringContainsString(ClinicalSafetyNotice::MESSAGE, trim($notices->item(0)?->textContent ?? ''));

        $links = $xpath->query('.//a', $notices->item(0));
        self::assertNotFalse($links);
        self::assertCount(0, $links);

        $noticePosition = strpos($html, 'class="clinical-safety-notice"');
        $actionsPosition = strpos($html, 'class="results-actions"');
        self::assertNotFalse($noticePosition);
        self::assertNotFalse($actionsPosition);
        self::assertLessThan($actionsPosition, $noticePosition);
    }

    public function testRenderedBdiResultOmitsNoticeWithoutSignal(): void
    {
        $xpath = $this->xpath($this->renderResult(null));
        $notices = $xpath->query('//aside[contains(concat(" ", normalize-space(@class), " "), " clinical-safety-notice ")]');

        self::assertNotFalse($notices);
        self::assertCount(0, $notices);
    }

    /** @param array{message: string}|null $notice */
    private function renderResult(?array $notice): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/templates'), [
            'cache' => false,
            'strict_variables' => true,
        ]);
        TemplateFunctions::register($twig);

        return $twig->render('result-layout.twig', [
            'appName' => 'PsyTest',
            'basePath' => '',
            'csrf_token' => 'synthetic-csrf-token',
            'test' => ['name' => 'Шкала депрессии Бека', 'slug' => 'bdi'],
            'session' => [
                'id' => 'synthetic-session-id',
                'session_token' => 'synthetic-result-token',
                'created_at' => '2026-08-22 12:00:00',
            ],
            'sections' => [],
            'clinical_safety_notice' => $notice,
        ]);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
