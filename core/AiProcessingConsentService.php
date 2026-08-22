<?php

declare(strict_types=1);

namespace PsyTest\Core;

use LogicException;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final class AiProcessingConsentService
{
    public const REPORT_LAY = 'lay';
    public const REPORT_PROFESSIONAL = 'professional';
    public const REPORT_BOTH = 'both';
    public const PURPOSE = 'expanded_interpretation';

    private const REPORT_KINDS = [self::REPORT_LAY, self::REPORT_PROFESSIONAL, self::REPORT_BOTH];
    private const DATA_SCOPES = ['scores', 'validity', 'scales', 'demographics', 'request_text', 'pair_comparison'];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Records the explicit checkout consent snapshot. Calling this method is
     * never part of free test completion and does not start an AI request.
     *
     * @param list<string> $allowedData
     */
    public function record(
        string $sessionId,
        string $checkoutReference,
        string $providerCode,
        string $reportKind,
        string $noticeVersion,
        array $allowedData,
    ): string {
        $snapshot = $this->normalizeSnapshot(
            $sessionId,
            $checkoutReference,
            $providerCode,
            $reportKind,
            $noticeVersion,
            $allowedData,
        );

        $session = $this->db->selectOne('SELECT status FROM test_sessions WHERE id = ?', [$sessionId]);
        if (!$session || $session['status'] !== 'completed') {
            throw new RuntimeException('AI consent requires a completed test session.');
        }

        $existing = $this->db->selectOne(
            'SELECT * FROM ai_processing_consents WHERE checkout_reference = ?',
            [$checkoutReference],
        );
        if ($existing) {
            $this->assertSameSnapshot($existing, $snapshot);
            return (string) $existing['id'];
        }

        $id = Uuid::uuid4()->toString();
        $this->db->insert('ai_processing_consents', ['id' => $id] + $snapshot);

        return $id;
    }

    /** @param list<string> $requiredData */
    public function allows(
        string $checkoutReference,
        string $providerCode,
        string $noticeVersion,
        array $requiredData,
    ): bool {
        $record = $this->db->selectOne(
            'SELECT provider_code, notice_version, allowed_data, revoked_at
             FROM ai_processing_consents WHERE checkout_reference = ?',
            [$checkoutReference],
        );
        if (!$record || $record['revoked_at'] !== null) {
            return false;
        }
        if ($record['provider_code'] !== $providerCode || $record['notice_version'] !== $noticeVersion) {
            return false;
        }

        $allowed = json_decode((string) $record['allowed_data'], true, flags: JSON_THROW_ON_ERROR);
        return is_array($allowed) && array_diff($requiredData, $allowed) === [];
    }

    public function revoke(string $checkoutReference): bool
    {
        return $this->db->update(
            'ai_processing_consents',
            ['revoked_at' => date('Y-m-d H:i:s')],
            'checkout_reference = ? AND revoked_at IS NULL',
            [$checkoutReference],
        ) === 1;
    }

    /**
     * @param list<string> $allowedData
     * @return array<string, mixed>
     */
    private function normalizeSnapshot(
        string $sessionId,
        string $checkoutReference,
        string $providerCode,
        string $reportKind,
        string $noticeVersion,
        array $allowedData,
    ): array {
        if (!Uuid::isValid($sessionId) || !Uuid::isValid($checkoutReference)) {
            throw new RuntimeException('Invalid consent reference.');
        }
        if (!preg_match('/^[a-z0-9._-]{1,100}$/', $providerCode)) {
            throw new RuntimeException('Invalid AI provider code.');
        }
        if (!preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $noticeVersion)) {
            throw new RuntimeException('Invalid consent notice version.');
        }
        if (!in_array($reportKind, self::REPORT_KINDS, true)) {
            throw new RuntimeException('Invalid report kind.');
        }

        $allowedData = array_values(array_unique($allowedData));
        sort($allowedData);
        if ($allowedData === [] || array_diff($allowedData, self::DATA_SCOPES) !== []) {
            throw new RuntimeException('Invalid consent data scope.');
        }

        return [
            'session_id' => $sessionId,
            'checkout_reference' => $checkoutReference,
            'purpose' => self::PURPOSE,
            'notice_version' => $noticeVersion,
            'provider_code' => $providerCode,
            'report_kind' => $reportKind,
            'allowed_data' => json_encode($allowedData, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $snapshot
     */
    private function assertSameSnapshot(array $existing, array $snapshot): void
    {
        foreach (['session_id', 'purpose', 'notice_version', 'provider_code', 'report_kind'] as $field) {
            if ((string) $existing[$field] !== (string) $snapshot[$field]) {
                throw new LogicException('Consent snapshot for this checkout is immutable.');
            }
        }

        $existingData = json_decode((string) $existing['allowed_data'], true, flags: JSON_THROW_ON_ERROR);
        $snapshotData = json_decode((string) $snapshot['allowed_data'], true, flags: JSON_THROW_ON_ERROR);
        if ($existingData !== $snapshotData) {
            throw new LogicException('Consent snapshot for this checkout is immutable.');
        }
    }
}
