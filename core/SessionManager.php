<?php
/**
 * Session Manager
 * 
 * Handles test session creation, token generation, and lifecycle
 */

declare(strict_types=1);

namespace PsyTest\Core;

use Ramsey\Uuid\Uuid;
use DateTime;
use DateTimeImmutable;

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
        $sessionToken = $this->generateSecureToken();
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
            'ip_address' => $options['ip_address'] ?? $this->getClientIp(),
            'user_agent' => $options['user_agent'] ?? $this->getUserAgent(),
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
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ];
    }
    
    /**
     * Get session by token
     * 
     * @param string $token Session or partner token
     * @return array|null Session data or null if not found/expired
     */
    public function getSessionByToken(string $token): ?array
    {
        $sql = "SELECT * FROM test_sessions
                WHERE (session_token = :token1 OR partner_token = :token2)
                AND expires_at > NOW()
                AND status NOT IN ('expired', 'deleted')";

        $session = $this->db->selectOne($sql, [
            'token1' => $token,
            'token2' => $token,
        ]);

        if ($session) {
            // Decode JSON fields
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
        return $this->getSessionByToken($token) !== null;
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
                'ip_address' => $this->getClientIp(),
                'user_agent' => $this->getUserAgent(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            // Don't fail on logging errors
            error_log("Activity logging failed: " . $e->getMessage());
        }
    }
    
    /**
     * Get client IP address
     */
    private function getClientIp(): string
    {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? 
              $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 
              $_SERVER['HTTP_X_REAL_IP'] ?? 
              $_SERVER['REMOTE_ADDR'] ?? 
              '0.0.0.0';
        
        // Take first IP if multiple (X-Forwarded-For can contain chain)
        if (str_contains($ip, ',')) {
            $ip = explode(',', $ip)[0];
        }
        
        return trim($ip);
    }
    
    /**
     * Get user agent
     */
    private function getUserAgent(): string
    {
        return substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 500);
    }
}
