<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

final class PrivacyClaimsTruthfulnessTest extends TestCase
{
    public function testPublicPrivacyCopyDoesNotPromiseUnimplementedProtectionOrTransfers(): void
    {
        $contents = (string) file_get_contents(dirname(__DIR__) . '/controllers/HomeController.php');

        self::assertStringNotContainsString('Все данные хранятся в зашифрованном виде', $contents);
        self::assertStringNotContainsString('Мы не передаём ваши персональные данные третьим лицам', $contents);
        self::assertStringContainsString('расширенная AI-интерпретация и оплата отключены', $contents);
        self::assertStringContainsString('Уникальная ссылка на результат действует как ключ доступа', $contents);
        self::assertStringContainsString('без IP-адреса и сведений о браузере', $contents);
        self::assertStringNotContainsString('IP-адрес и user agent, которые записываются автоматически', $contents);
    }

    public function testPublicDeleteCopyDescribesTheCurrentSoftDeleteBoundary(): void
    {
        $projectRoot = dirname(__DIR__);

        foreach ([
            'controllers/HomeController.php',
            'templates/result-page.twig',
            'templates/test-wrapper.twig',
            'templates/blocks/_delete-modal.twig',
        ] as $path) {
            $contents = (string) file_get_contents($projectRoot . '/' . $path);

            self::assertStringNotContainsString('необратимо удалит все результаты тестирования', $contents, $path);
            self::assertMatchesRegularExpression('/очищ(?:ает|ены)/u', $contents, $path);
        }
    }

    public function testCurrentStateDocsDistinguishRetiredLegacyRoutesFromLiveRoutes(): void
    {
        $projectRoot = dirname(__DIR__);
        $architecture = (string) file_get_contents($projectRoot . '/ARCHITECTURE.md');
        $dataMap = (string) file_get_contents($projectRoot . '/docs/roadmap/DATA_MAP_CURRENT.md');

        self::assertStringContainsString('RetiredPaymentController::interpretation', $architecture);
        self::assertStringNotContainsString('ResultController::initiatePayment', $architecture);
        self::assertStringContainsString('SessionLifecycleService', $dataMap);
        self::assertStringContainsString('soft-delete', $dataMap);
    }
}
