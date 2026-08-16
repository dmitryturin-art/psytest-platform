<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

final class ClinicalSafetyNoticeTemplateTest extends TestCase
{
    public function testResultLayoutPlacesSafetyNoticeBeforeActions(): void
    {
        $projectRoot = dirname(__DIR__);
        $layout = (string) file_get_contents($projectRoot . '/templates/result-layout.twig');

        $noticePosition = strpos($layout, "include('blocks/clinical-safety-notice.twig'");
        $actionsPosition = strpos($layout, '<div class="results-actions">');

        self::assertNotFalse($noticePosition);
        self::assertNotFalse($actionsPosition);
        self::assertLessThan($actionsPosition, $noticePosition);
    }

    public function testNoticeTemplateDoesNotContainContactsOrResourceLookup(): void
    {
        $projectRoot = dirname(__DIR__);
        $template = (string) file_get_contents($projectRoot . '/templates/blocks/clinical-safety-notice.twig');

        self::assertStringContainsString('role="alert"', $template);
        self::assertStringContainsString('{{ message }}', $template);

        foreach (['href=', 'tel:', 'http://', 'https://', 'country', 'GeoIP', 'IP'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $template);
        }
    }
}
