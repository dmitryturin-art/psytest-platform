<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use LogicException;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\AiProcessingConsentService;
use PsyTest\Core\Database;
use PsyTest\Core\SessionManager;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final class AiProcessingConsentServiceTest extends TestCase
{
    public function testConsentIsExplicitCheckoutBoundImmutableAndRevocable(): void
    {
        $db = Database::getInstance();
        $test = $db->selectOne("SELECT id FROM tests WHERE slug = 'bdi'");
        $sessions = new SessionManager($db);
        $service = new AiProcessingConsentService($db);
        $session = $sessions->createSession((int) $test['id']);
        $checkoutReference = Uuid::uuid4()->toString();

        try {
            $this->expectExceptionForPartialSession($service, $session['id'], $checkoutReference);
            $sessions->completeSession($session['id'], ['fixture' => true]);

            $consentId = $service->record(
                $session['id'],
                $checkoutReference,
                'openrouter',
                AiProcessingConsentService::REPORT_BOTH,
                'ai-consent-v1',
                ['scales', 'scores'],
            );
            self::assertSame($consentId, $service->record(
                $session['id'],
                $checkoutReference,
                'openrouter',
                AiProcessingConsentService::REPORT_BOTH,
                'ai-consent-v1',
                ['scores', 'scales'],
            ));
            self::assertTrue($service->allows($checkoutReference, 'openrouter', 'ai-consent-v1', ['scores']));
            self::assertFalse($service->allows($checkoutReference, 'openrouter', 'ai-consent-v1', ['validity']));

            try {
                $service->record($session['id'], $checkoutReference, 'other-provider', AiProcessingConsentService::REPORT_BOTH, 'ai-consent-v1', ['scores']);
                self::fail('An existing checkout consent must be immutable.');
            } catch (LogicException) {
                self::addToAssertionCount(1);
            }

            self::assertTrue($service->revoke($checkoutReference));
            self::assertFalse($service->allows($checkoutReference, 'openrouter', 'ai-consent-v1', ['scores']));
        } finally {
            $db->delete('ai_processing_consents', 'session_id = ?', [$session['id']]);
            $db->delete('activity_log', 'session_id = ?', [$session['id']]);
            $db->delete('test_sessions', 'id = ?', [$session['id']]);
        }
    }

    private function expectExceptionForPartialSession(
        AiProcessingConsentService $service,
        string $sessionId,
        string $checkoutReference,
    ): void {
        try {
            $service->record($sessionId, $checkoutReference, 'openrouter', AiProcessingConsentService::REPORT_BOTH, 'ai-consent-v1', ['scores']);
            self::fail('A free partial session must never imply AI consent.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }
    }
}
