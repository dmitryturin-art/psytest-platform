<?php
/**
 * API Controller
 * 
 * Handles API endpoints and webhooks
 */

declare(strict_types=1);

namespace PsyTest\Controllers;

use PsyTest\Core\Database;
use PsyTest\Services\PaymentService;
use PsyTest\Services\AIInterpretationService;

class ApiController
{
    private Database $db;
    private PaymentService $paymentService;
    private AIInterpretationService $aiService;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->paymentService = new PaymentService();
        $this->aiService = new AIInterpretationService();
    }
    
    /**
     * Health check endpoint
     * GET /api/health
     */
    public function health(): array
    {
        return [
            'status' => 'ok',
            'timestamp' => date('c'),
            'version' => '1.0.0',
        ];
    }
    
    /**
     * YooMoney webhook handler
     * POST /webhook/yoomoney
     */
    public function yoomoneyWebhook(): void
    {
        header('Content-Type: application/json');
        
        // Get raw POST data
        $rawData = file_get_contents('php://input');
        $data = $_POST;
        
        // Verify notification secret (if configured)
        if (!$this->paymentService->verifyWebhook($data)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid webhook signature']);
            return;
        }
        
        // Extract payment information
        $notificationType = $data['notification_type'] ?? '';
        if ($notificationType !== 'payment_received') {
            echo json_encode(['status' => 'ok']);
            return;
        }
        
        $amount = $data['amount'] ?? 0;
        $transactionId = $data['transaction_id'] ?? '';
        $paymentMethod = $data['payment_method'] ?? '';
        
        // Get session_id from label
        $label = $data['label'] ?? '';
        $parts = explode(':', $label);
        $sessionId = $parts[0] ?? null;
        
        if (!$sessionId) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid label']);
            return;
        }
        
        // Find interpretation record
        $interpretation = $this->db->selectOne(
            'SELECT * FROM ai_interpretations WHERE session_id = ? AND payment_status = "pending"',
            [$sessionId]
        );
        
        if (!$interpretation) {
            echo json_encode(['status' => 'ok']);
            return;
        }
        
        // Update payment status
        $this->db->update(
            'ai_interpretations',
            [
                'payment_status' => 'completed',
                'payment_id' => $transactionId,
                'payment_amount' => $amount,
                'payment_completed_at' => date('Y-m-d H:i:s'),
            ],
            'id = ?',
            [$interpretation['id']]
        );
        
        // Log transaction
        $this->db->insert('payment_transactions', [
            'transaction_id' => $transactionId,
            'session_id' => $sessionId,
            'interpretation_id' => $interpretation['id'],
            'amount' => $amount,
            'currency' => 'RUB',
            'status' => 'completed',
            'payment_method' => $paymentMethod,
            'raw_payload' => json_encode($data),
            'processed_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Generate AI interpretation
        try {
            $this->processInterpretation($interpretation);
        } catch (\Exception $e) {
            error_log("AI interpretation failed: " . $e->getMessage());
        }
        
        echo json_encode(['status' => 'ok']);
    }
    
    /**
     * Process AI interpretation after payment
     */
    private function processInterpretation(array $interpretation): void
    {
        $session = $this->db->selectOne('SELECT * FROM test_sessions WHERE id = ?', [$interpretation['session_id']]);
        if (!$session) {
            throw new \Exception('Session not found');
        }
        
        $test = $this->db->selectOne('SELECT * FROM tests WHERE id = ?', [$session['test_id']]);
        if (!$test) {
            throw new \Exception('Test not found');
        }
        
        // Generate AI interpretation
        $aiResult = $this->aiService->generateInterpretation($session, $test);
        
        // Update interpretation record
        $this->db->update(
            'ai_interpretations',
            [
                'interpretation_text' => $aiResult['text'],
                'pdf_path' => $aiResult['pdf_path'],
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            'id = ?',
            [$interpretation['id']]
        );
        
        // Send email notification
        if (!empty($session['user_email'])) {
            $this->sendInterpretationEmail($session, $aiResult);
        }
    }
    
    /**
     * Send interpretation email
     */
    private function sendInterpretationEmail(array $session, array $aiResult): void
    {
        // Email sending logic would go here
        // Using PHP mail() or a service like PHPMailer/SwiftMailer
        
        $to = $session['user_email'];
        $subject = 'Ваша AI-интерпретация результатов тестирования';
        
        $message = "Здравствуйте!\n\n";
        $message .= "Ваша развёрнутая AI-интерпретация готова.\n\n";
        $message .= "Вы можете просмотреть её по ссылке: " . $this->getResultUrl($session['session_token']) . "\n\n";
        $message .= "С уважением,\nКоманда PsyTest";
        
        $headers = "From: PsyTest <noreply@psytest.local>\r\n";
        $headers .= "Reply-To: noreply@psytest.local\r\n";
        
        mail($to, $subject, $message, $headers);
        
        // Update email sent timestamp
        $this->db->update(
            'ai_interpretations',
            ['email_sent_at' => date('Y-m-d H:i:s')],
            'session_id = ?',
            [$session['id']]
        );
    }
    
    /**
     * Get result URL
     */
    private function getResultUrl(string $token): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return "$protocol://$host/result/smil/$token";
    }
}
