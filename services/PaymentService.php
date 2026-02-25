<?php
/**
 * Payment Service
 * 
 * Handles YooMoney (ЮMoney) payment integration
 */

declare(strict_types=1);

namespace PsyTest\Services;

use PsyTest\Core\Database;

class PaymentService
{
    private Database $db;
    private string $shopId;
    private string $apiKey;
    private string $webhookSecret;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        
        $configLoader = require __DIR__ . '/../config.php';
        $this->shopId = $configLoader->yoomoneyShopId();
        $this->apiKey = $configLoader->yoomoneyApiKey();
        $this->webhookSecret = $configLoader->yoomoneyWebhookSecret();
    }
    
    /**
     * Create payment link for AI interpretation
     * 
     * @param array $session Session data
     * @param float $amount Amount in RUB
     * @return array Payment data including URL
     */
    public function createPayment(array $session, float $amount): array
    {
        $paymentId = uniqid('pay_');
        
        // Create interpretation record
        $interpretationId = $this->createInterpretationRecord($session, $paymentId, $amount);
        
        // Prepare payment request
        $label = $session['id'] . ':' . $interpretationId;
        
        // YooMoney payment URL (simple link method)
        $paymentUrl = 'https://yoomoney.ru/quickpay/confirm.xml?' . http_build_query([
            'receiver' => $this->shopId,
            'formcomment' => 'AI-интерпретация результатов тестирования',
            'short-dest' => 'AI-интерпретация',
            'label' => $label,
            'targets' => 'Оплата AI-интерпретации результатов психологического теста',
            'sum' => $amount,
            'paymentType' => 'SB', // SB=card, AC=account
        ]);
        
        return [
            'payment_id' => $paymentId,
            'interpretation_id' => $interpretationId,
            'amount' => $amount,
            'payment_url' => $paymentUrl,
            'label' => $label,
        ];
    }
    
    /**
     * Create AI interpretation record in database
     */
    private function createInterpretationRecord(array $session, string $paymentId, float $amount): string
    {
        $id = \Ramsey\Uuid\Uuid::uuid4()->toString();
        
        $this->db->insert('ai_interpretations', [
            'id' => $id,
            'session_id' => $session['id'],
            'payment_id' => $paymentId,
            'payment_status' => 'pending',
            'payment_amount' => $amount,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        
        return $id;
    }
    
    /**
     * Verify webhook notification
     */
    public function verifyWebhook(array $data): bool
    {
        if (empty($this->webhookSecret)) {
            // If no secret configured, accept all (development)
            return true;
        }
        
        // YooMoney sends notification with sha1_hash
        // Build notification string
        $notificationString = implode('&', [
            $data['notification_type'] ?? '',
            $data['operation_id'] ?? '',
            $data['amount'] ?? '',
            $data['currency'] ?? '',
            $data['datetime'] ?? '',
            $data['sender'] ?? '',
            $data['codepro'] ?? '',
            $this->webhookSecret,
        ]);
        
        $hash = sha1($notificationString);
        
        return hash_equals($hash, $data['sha1_hash'] ?? '');
    }
    
    /**
     * Check payment status
     */
    public function checkPaymentStatus(string $interpretationId): array
    {
        $interpretation = $this->db->selectOne(
            'SELECT * FROM ai_interpretations WHERE id = ?',
            [$interpretationId]
        );
        
        if (!$interpretation) {
            return ['status' => 'not_found'];
        }
        
        return [
            'status' => $interpretation['payment_status'],
            'amount' => $interpretation['payment_amount'],
            'completed_at' => $interpretation['payment_completed_at'],
            'interpretation_text' => $interpretation['interpretation_text'],
            'pdf_path' => $interpretation['pdf_path'],
        ];
    }
    
    /**
     * Get payment URL for redirect
     */
    public function getPaymentUrl(array $session, float $amount): string
    {
        $payment = $this->createPayment($session, $amount);
        return $payment['payment_url'];
    }
    
    /**
     * Create payment with API (advanced method)
     * Requires YooMoney API credentials
     */
    public function createPaymentApi(array $session, float $amount): array
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('YooMoney API key not configured');
        }
        
        $paymentId = \Ramsey\Uuid\Uuid::uuid4()->toString();
        
        // Create payment request
        $requestBody = [
            'amount' => [
                'value' => number_format($amount, 2, '.', ''),
                'currency' => 'RUB',
            ],
            'capture' => true,
            'confirmation' => [
                'type' => 'redirect',
                'return_url' => $this->getReturnUrl($session['session_token']),
            ],
            'description' => 'AI-интерпретация результатов тестирования',
            'metadata' => [
                'session_id' => $session['id'],
            ],
        ];
        
        // Make API request
        $ch = curl_init('https://api.yookassa.ru/v3/payments');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Idempotence-Key: ' . $paymentId,
            'Authorization: Basic ' . base64_encode($this->shopId . ':' . $this->apiKey),
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new \RuntimeException('YooMoney API error: ' . $response);
        }
        
        $paymentData = json_decode($response, true);
        
        // Create interpretation record
        $interpretationId = $this->createInterpretationRecord($session, $paymentData['id'], $amount);
        
        return [
            'payment_id' => $paymentData['id'],
            'interpretation_id' => $interpretationId,
            'amount' => $amount,
            'payment_url' => $paymentData['confirmation']['confirmation_url'],
            'status' => $paymentData['status'],
        ];
    }
    
    /**
     * Get return URL after payment
     */
    private function getReturnUrl(string $sessionToken): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return "$protocol://$host/interpretation/$sessionToken?payment=success";
    }
    
    /**
     * Refund payment
     */
    public function refund(string $paymentId, float $amount): array
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('YooMoney API key not configured');
        }
        
        $requestBody = [
            'amount' => [
                'value' => number_format($amount, 2, '.', ''),
                'currency' => 'RUB',
            ],
            'payment_id' => $paymentId,
        ];
        
        $ch = curl_init('https://api.yookassa.ru/v3/refunds');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($this->shopId . ':' . $this->apiKey),
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new \RuntimeException('YooMoney refund error: ' . $response);
        }
        
        return json_decode($response, true);
    }
}
