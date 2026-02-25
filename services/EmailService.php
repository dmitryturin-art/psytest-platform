<?php
/**
 * Email Service
 * 
 * Handles email sending for notifications and reports
 */

declare(strict_types=1);

namespace PsyTest\Services;

use PsyTest\Core\Database;

class EmailService
{
    private Database $db;
    private array $mailConfig;
    private string $fromEmail;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        
        $configLoader = require __DIR__ . '/../config.php';
        $this->mailConfig = $configLoader->mailConfig();
        $this->fromEmail = $configLoader->mailFrom();
    }
    
    /**
     * Send interpretation ready notification
     */
    public function sendInterpretationReady(array $session, array $interpretation): bool
    {
        if (empty($session['user_email'])) {
            return false;
        }
        
        $to = $session['user_email'];
        $subject = 'Ваша AI-интерпретация готова';
        
        $body = $this->renderEmailTemplate('interpretation_ready', [
            'session' => $session,
            'interpretation' => $interpretation,
        ]);
        
        return $this->send($to, $subject, $body);
    }
    
    /**
     * Send pair comparison invitation
     */
    public function sendPairInvitation(array $session, string $partnerEmail, string $pairUrl): bool
    {
        $to = $partnerEmail;
        $subject = 'Вас приглашают пройти парное тестирование';
        
        $body = $this->renderEmailTemplate('pair_invitation', [
            'session' => $session,
            'pair_url' => $pairUrl,
        ]);
        
        return $this->send($to, $subject, $body);
    }
    
    /**
     * Generic email sender
     */
    private function send(string $to, string $subject, string $body): bool
    {
        $headers = [
            'From: ' . $this->fromEmail,
            'Reply-To: ' . $this->fromEmail,
            'X-Mailer: PHP/' . phpversion(),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
        ];
        
        // If SMTP is configured, use PHPMailer or similar
        if (!empty($this->mailConfig['host'])) {
            return $this->sendSmtp($to, $subject, $body);
        }
        
        return mail($to, $subject, $body, implode("\r\n", $headers));
    }
    
    /**
     * Send via SMTP (using PHPMailer if available)
     */
    private function sendSmtp(string $to, string $subject, string $body): bool
    {
        // This would use PHPMailer or SwiftMailer
        // For now, fall back to mail()
        return $this->send($to, $subject, $body);
    }
    
    /**
     * Render email template
     */
    private function renderEmailTemplate(string $template, array $data): string
    {
        $templates = [
            'interpretation_ready' => $this->getInterpretationReadyTemplate($data),
            'pair_invitation' => $this->getPairInvitationTemplate($data),
        ];
        
        return $templates[$template] ?? '';
    }
    
    /**
     * Interpretation ready email template
     */
    private function getInterpretationReadyTemplate(array $data): string
    {
        $session = $data['session'];
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #3498db; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .button { display: inline-block; padding: 12px 24px; background: #3498db; color: white; text-decoration: none; border-radius: 5px; }
        .footer { padding: 20px; text-align: center; font-size: 12px; color: #999; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Ваша AI-интерпретация готова!</h1>
        </div>
        <div class="content">
            <p>Здравствуйте!</p>
            <p>Ваша развёрнутая интерпретация результатов тестирования успешно подготовлена.</p>
            <p style="text-align: center; margin: 30px 0;">
                <a href="/result/smil/{$session['session_token']}" class="button">Посмотреть результаты</a>
            </p>
            <p>Интерпретация включает:</p>
            <ul>
                <li>Профессиональный анализ профиля личности</li>
                <li>Подробное описание выявленных особенностей</li>
                <li>Рекомендации для дальнейшей работы</li>
            </ul>
            <p><strong>Важно:</strong> Данная интерпретация носит ознакомительный характер и не заменяет очную консультацию специалиста.</p>
        </div>
        <div class="footer">
            <p>С уважением, команда PsyTest</p>
            <p>Это письмо отправлено автоматически, пожалуйста, не отвечайте на него.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
    
    /**
     * Pair invitation email template
     */
    private function getPairInvitationTemplate(array $data): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #9b59b6; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .button { display: inline-block; padding: 12px 24px; background: #9b59b6; color: white; text-decoration: none; border-radius: 5px; }
        .footer { padding: 20px; text-align: center; font-size: 12px; color: #999; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Приглашение к парному тестированию</h1>
        </div>
        <div class="content">
            <p>Здравствуйте!</p>
            <p>Вас приглашают пройти парное психологическое тестирование для сравнения результатов.</p>
            <p style="text-align: center; margin: 30px 0;">
                <a href="{$data['pair_url']}" class="button">Пройти тестирование</a>
            </p>
            <p>После прохождения вы сможете увидеть сравнительный анализ ваших результатов.</p>
        </div>
        <div class="footer">
            <p>С уважением, команда PsyTest</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
