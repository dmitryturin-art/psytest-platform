<?php

/**
 * Session Manager
 *
 * Handles test session creation, token generation, and lifecycle
 */

declare(strict_types=1);

namespace PsyTest\Core;

use DateTime;
use DateTimeImmutable;
use PDOException;
use Ramsey\Uuid\Uuid;

class SessionManager
{
    private Database $db;
    private int $sessionTtlDays;

    public function __construct(?Database $db = null, ?int $sessionTtlDays = null)
    {
        $this->db = $db ?? Database::getInstance();

        if ($sessionTtlDays === null) {
            $configLoader = require __DIR__ . '/../config.php';
            $sessionTtlDays = $configLoader->sessionTtlDays();
        }

        $this->sessionTtlDays = $sessionTtlDays;
    }

    /**
     * Create a new test session
     *
     * @param int $testId Test ID
     * @param array $options Optional: email, name, demographics, partner_token
     * @return array Session data including tokens
     */
    public function createSession(int $testId, array $options = []): array
    {
        $sessionId = Uuid::uuid4()->toString();
        $sessionToken = $this->generateUniqueToken();
        $partnerToken = $options['partner_token'] ?? null;

        $expiresAt = new DateTimeImmutable("+{$this->sessionTtlDays} days");

        $data = [
            'id' => $sessionId,
            'test_id' => $testId,
            'session_token' => $sessionToken,
            'partner_token' => $partnerToken,
            'user_email' => $options['email'] ?? null,
            'user_name' => $options['name'] ?? null,
            'demographics' => $options['demographics'] ?? null,
            'answers' => json_encode([]),
            'calculated_results' => json_encode([]),
            'status' => 'partial',
            'retention_class' => RetentionPolicy::ANONYMOUS,
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ];

        $this->db->insert('test_sessions', $data);

        // Log session creation
        $this->logActivity($sessionId, $testId, 'session_created', [
            'has_partner' => $partnerToken !== null,
        ]);

        return [
            'id' => $sessionId,
            'test_id' => $testId,
            'session_token' => $sessionToken,
            'partner_token' => $partnerToken,
            'retention_class' => RetentionPolicy::ANONYMOUS,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Look up a session by its public result-access token.
     *
     * `partner_token` is a relationship reference used only while creating a
     * pair response. It is deliberately not an alternative access credential:
     * a result URL, PDF, save, delete, or pair flow must resolve only the
     * session that issued the token in its own `session_token` column.
     *
     * @return array<string, mixed>|null
     */
    public function getSessionByResultToken(string $token): ?array
    {
        $sql = "SELECT * FROM test_sessions
                WHERE session_token = :token
                AND expires_at > NOW()
                AND status NOT IN ('expired', 'deleted')";

        $session = $this->db->selectOne($sql, ['token' => $token]);

        if ($session) {
            $session['answers'] = !empty($session['answers']) ? json_decode($session['answers'], true) : [];
            $session['calculated_results'] = !empty($session['calculated_results']) ? json_decode($session['calculated_results'], true) : [];
            $session['demographics'] = !empty($session['demographics']) ? json_decode($session['demographics'], true) : [];
        }

        return $session;
    }

    /**
     * Get session by ID
     */
    public function getSessionById(string $sessionId): ?array
    {
        $sql = "SELECT * FROM test_sessions
                WHERE id = :id
                AND expires_at > NOW()
                AND status NOT IN ('expired', 'deleted')";

        $session = $this->db->selectOne($sql, ['id' => $sessionId]);

        if ($session) {
            $session['answers'] = !empty($session['answers']) ? json_decode($session['answers'], true) : [];
            $session['calculated_results'] = !empty($session['calculated_results']) ? json_decode($session['calculated_results'], true) : [];
            $session['demographics'] = !empty($session['demographics']) ? json_decode($session['demographics'], true) : [];
        }

        return $session;
    }

    /**
     * Save answers to session
     *
     * @param string $sessionId Session ID
     * @param array $answers User answers
     * @return bool Success
     */
    public function saveAnswers(string $sessionId, array $answers): bool
    {
        $this->db->update(
            'test_sessions',
            ['answers' => json_encode($answers)],
            'id = ?',
            [$sessionId]
        );

        $this->logActivity($sessionId, null, 'answers_saved', [
            'answer_count' => count($answers),
        ]);

        return true;
    }

    /**
     * Save demographics data for a session.
     *
     * @param string                           $sessionId   Session ID.
     * @param array<string, mixed>             $demographics Demographics data (gender, age, etc.).
     *
     * @return bool Success
     */
    public function saveDemographics(string $sessionId, array $demographics): bool
    {
        if (empty($demographics)) {
            return false;
        }

        $this->db->update(
            'test_sessions',
            ['demographics' => json_encode($demographics)],
            'id = ?',
            [$sessionId]
        );

        return true;
    }

    /**
     * Complete a session with results
     *
     * @param string $sessionId Session ID
     * @param array $results Calculated results
     * @return bool Success
     */
    public function completeSession(string $sessionId, array $results): bool
    {
        $this->db->update(
            'test_sessions',
            [
                'calculated_results' => json_encode($results),
                'status' => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
            ],
            'id = ?',
            [$sessionId]
        );

        // Get session for logging
        $session = $this->getSessionById($sessionId);

        $this->logActivity($sessionId, $session['test_id'] ?? null, 'session_completed');

        return true;
    }

    /**
     * Update session email (for paid interpretations)
     */
    public function updateEmail(string $sessionId, string $email): bool
    {
        return $this->db->update(
            'test_sessions',
            ['user_email' => $email],
            'id = ?',
            [$sessionId]
        ) > 0;
    }

    /**
     * Delete a session (GDPR compliance)
     */
    public function deleteSession(string $sessionId): bool
    {
        // Get session info before deletion for logging
        $session = $this->getSessionById($sessionId);

        if ($session) {
            $this->db->update(
                'test_sessions',
                [
                    'status' => 'deleted',
                    'answers' => json_encode([]),
                    'calculated_results' => json_encode([]),
                    'user_email' => null,
                    'user_name' => null,
                    'demographics' => null,
                ],
                'id = ?',
                [$sessionId]
            );

            $this->logActivity($sessionId, $session['test_id'], 'session_deleted', [
                'reason' => 'user_request',
            ]);

            return true;
        }

        return false;
    }

    /**
     * Check if session exists and is valid
     */
    public function isValidSession(string $token): bool
    {
        return $this->getSessionByResultToken($token) !== null;
    }

    /**
     * A source result token can be used to create at most one pair session.
     */
    public function hasPairSessionForSourceToken(string $token): bool
    {
        return $this->db->selectOne(
            "SELECT id FROM test_sessions
             WHERE partner_token = :token
             AND expires_at > NOW()
             AND status NOT IN ('expired', 'deleted')",
            ['token' => $token],
        ) !== null;
    }

    /**
     * Atomically create the second partner's session.
     *
     * The preflight check makes the normal case clear to a visitor. The
     * database unique constraint remains authoritative when two requests race.
     * A duplicate returns null so the controller can answer with HTTP 409.
     *
     * @return array<string, mixed>|null
     */
    public function createPairSession(int $testId, string $sourceToken): ?array
    {
        try {
            return $this->createSession($testId, ['partner_token' => $sourceToken]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Confirm that the second partner's session was created from this exact
     * source invite. A pair submission must not combine an arbitrary session
     * id with another person's result-access token.
     */
    public function isPairSessionBoundToSourceToken(string $sessionId, string $token): bool
    {
        return $this->db->selectOne(
            "SELECT id FROM test_sessions
             WHERE id = :session_id
             AND partner_token = :token
             AND expires_at > NOW()
             AND status NOT IN ('expired', 'deleted')",
            [
                'session_id' => $sessionId,
                'token' => $token,
            ],
        ) !== null;
    }

    /**
     * Generate a pair comparison record
     *
     * @param int $testId Test ID
     * @param string $session1Id First session ID
     * @param string $session2Id Second session ID
     * @param array $comparisonData Comparison results
     * @return array Comparison record
     */
    public function createPairComparison(
        int $testId,
        string $session1Id,
        string $session2Id,
        array $comparisonData
    ): array {
        $comparisonId = Uuid::uuid4()->toString();
        $expiresAt = new DateTimeImmutable("+{$this->sessionTtlDays} days");

        $data = [
            'id' => $comparisonId,
            'test_id' => $testId,
            'session_1_id' => $session1Id,
            'session_2_id' => $session2Id,
            'comparison_data' => json_encode($comparisonData),
            'generated_at' => date('Y-m-d H:i:s'),
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ];

        $this->db->insert('pair_comparisons', $data);

        return [
            'id' => $comparisonId,
            'test_id' => $testId,
            'session_1_id' => $session1Id,
            'session_2_id' => $session2Id,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Get pair comparison by session ID
     */
    public function getPairComparisonBySession(string $sessionId): ?array
    {
        $sql = "SELECT * FROM pair_comparisons
                WHERE session_1_id = :id1 OR session_2_id = :id2";

        $comparison = $this->db->selectOne($sql, [
            'id1' => $sessionId,
            'id2' => $sessionId,
        ]);

        if ($comparison) {
            $comparison['comparison_data'] = !empty($comparison['comparison_data']) ? json_decode($comparison['comparison_data'], true) : [];
        }

        return $comparison;
    }

    /**
     * Generate a cryptographically secure token
     */
    public function generateSecureToken(int $length = 64): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * Generate unique session token with collision check
     */
    private function generateUniqueToken(int $maxAttempts = 3): string
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $token = $this->generateSecureToken();

            $exists = $this->db->selectOne(
                'SELECT id FROM test_sessions WHERE session_token = ?',
                [$token]
            );

            if (!$exists) {
                return $token;
            }
        }

        throw new \RuntimeException('Failed to generate unique session token');
    }

    /**
     * Log activity for audit purposes
     */
    private function logActivity(
        ?string $sessionId,
        ?int $testId,
        string $action,
        array $details = []
    ): void {
        try {
            $this->db->insert('activity_log', [
                'session_id' => $sessionId,
                'test_id' => $testId,
                'action' => $action,
                'details' => json_encode($details),
                'ip_address' => null,
                'user_agent' => null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            // Don't fail on logging errors
            error_log("Activity logging failed: " . $e->getMessage());
        }
    }

}
