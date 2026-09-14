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

    /**
     * Текст про кабинет обязан совпадать с кодом 07.K3: добровольность,
     * явная привязка, сроки и то, что удаление действительно удаляет.
     */
    public function testAccountCopyMatchesTheImplementedVisitorCabinet(): void
    {
        $projectRoot = dirname(__DIR__);
        $copy = (string) file_get_contents($projectRoot . '/controllers/HomeController.php');
        $service = (string) file_get_contents($projectRoot . '/core/VisitorAccountService.php');
        $lifecycle = (string) file_get_contents($projectRoot . '/core/SessionLifecycleService.php');

        self::assertStringContainsString('Кабинет добровольный', $copy);
        self::assertStringContainsString('она действует 15 минут, открывается один раз', $copy);
        self::assertStringContainsString('ни по email, ни по cookie,', $copy);
        self::assertStringContainsString('IP-адрес и сведения о браузере при входе не записываются', $copy);
        self::assertStringContainsString('повторного входа по тому же адресу почты', $copy);

        // Обещанное поведение должно существовать в коде, а не только в тексте.
        self::assertStringContainsString('LOGIN_TOKEN_TTL_MINUTES = 15', $service);
        self::assertStringContainsString('used_at IS NULL AND expires_at > NOW()', $service);
        self::assertStringContainsString('deleteSessionAndArtifacts', $service);
        self::assertStringContainsString("retention_class = :retention_class", $lifecycle);
        self::assertStringNotContainsString('ip_address', $service);
        self::assertStringNotContainsString('user_agent', $service);
        self::assertStringNotContainsString('REMOTE_ADDR', $service);
    }

    /**
     * Текст про специалиста обязан совпадать с кодом 07.K5b (D-054).
     *
     * Обещание «адрес только по решению специалиста, письмо без разбора и без
     * ссылки» проверяется не только в копирайте, но и в самом отправителе.
     */
    public function testTherapistEmailCopyMatchesTheImplementedNotification(): void
    {
        $projectRoot = dirname(__DIR__);
        $copy = (string) file_get_contents($projectRoot . '/controllers/HomeController.php');
        $notifier = (string) file_get_contents($projectRoot . '/core/ClientReportNotifier.php');

        self::assertStringContainsString('только если он сам его', $copy);
        self::assertStringContainsString('ни текста', $copy);
        self::assertStringContainsString('ни ссылки на результат', $copy);

        // Обещанное поведение существует в коде, а не только в тексте.
        self::assertStringContainsString('Ваш разбор готов', $notifier);
        self::assertStringContainsString('published_revision_id IS NOT NULL', $notifier);
        self::assertStringContainsString('MIN_INTERVAL_MINUTES = 10', $notifier);
        $body = substr($notifier, (int) strpos($notifier, 'public static function body('));
        self::assertStringNotContainsString('http', $body);
        self::assertStringNotContainsString('session_token', $body);
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
