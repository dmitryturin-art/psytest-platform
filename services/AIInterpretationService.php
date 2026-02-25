<?php
/**
 * AI Interpretation Service
 * 
 * Generates professional interpretations using OpenRouter API
 */

declare(strict_types=1);

namespace PsyTest\Services;

use PsyTest\Core\Database;
use PsyTest\Core\PDFGenerator;

class AIInterpretationService
{
    private Database $db;
    private PDFGenerator $pdfGenerator;
    private string $apiKey;
    private string $model;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->pdfGenerator = new PDFGenerator();
        
        $configLoader = require __DIR__ . '/../config.php';
        $this->apiKey = $configLoader->openrouterApiKey();
        $this->model = $configLoader->openrouterModel();
    }
    
    /**
     * Generate AI interpretation for test results
     * 
     * @param array $session Session data with results
     * @param array $test Test metadata
     * @return array Interpretation text and PDF path
     */
    public function generateInterpretation(array $session, array $test): array
    {
        $results = $session['calculated_results'];
        $interpretation = $results['interpretation'] ?? [];
        
        // Build prompt for AI
        $prompt = $this->buildPrompt($session, $test, $results, $interpretation);
        
        // Call OpenRouter API
        $aiResponse = $this->callOpenRouter($prompt);
        
        // Parse response
        $interpretationText = $this->parseAIResponse($aiResponse);
        
        // Generate PDF
        $pdfPath = $this->pdfGenerator->generateAIInterpretation($session, $test, $interpretationText);
        
        return [
            'text' => $interpretationText,
            'pdf_path' => $pdfPath,
            'raw_response' => $aiResponse,
        ];
    }
    
    /**
     * Build prompt for AI interpretation
     */
    private function buildPrompt(array $session, array $test, array $results, array $interpretation): string
    {
        $demographics = $session['demographics'] ?? [];
        $age = $demographics['age'] ?? null;
        $gender = $demographics['gender'] ?? null;
        
        $scores = $results['t_scores'] ?? [];
        $correctedScores = $results['corrected_scores'] ?? [];
        $validity = $results['validity'] ?? [];
        $profile = $results['profile'] ?? [];
        
        $prompt = <<<PROMPT
Вы - опытный клинический психолог, специалист по психодиагностике. Ваша задача - составить профессиональную, этически выверенную интерпретацию результатов психологического тестирования.

## Тест
{$test['name']}

## Данные клиента
PROMPT;
        
        if ($age || $gender) {
            $prompt .= "\n- Возраст: " . ($age ?? 'не указан');
            $prompt .= "\n- Пол: " . ($gender ?? 'не указан');
        }
        
        $prompt .= "\n\n## Показатели достоверности\n";
        $prompt .= "- L (Ложь): {$validity['L_score']}\n";
        $prompt .= "- F (Достоверность): {$validity['F_score']}\n";
        $prompt .= "- K (Коррекция): {$validity['K_score']}\n";
        $prompt .= "- F-K индекс: {$validity['FK_index']}\n";
        
        $prompt .= "\n\n## Профиль личности (T-баллы)\n";
        foreach ($correctedScores as $scale => $score) {
            if (in_array($scale, ['1', '2', '3', '4', '5', '6', '7', '8', '9'])) {
                $scaleName = $profile['scales'][$scale]['name'] ?? $scale;
                $level = $profile['scales'][$scale]['level'] ?? 'normal';
                $prompt .= "- Шкала $scale ($scaleName): $score T-баллов (уровень: $level)\n";
            }
        }
        
        $prompt .= "\n\n## Тип профиля\n";
        $prompt .= "- Тип: " . ($profile['profile_type'] ?? 'не определён') . "\n";
        $prompt .= "- Код профиля: " . ($profile['code_type'] ?? '') . "\n";
        
        $prompt .= "\n\n## Требования к интерпретации\n";
        $prompt .= "1. Начните с оценки достоверности результатов\n";
        $prompt .= "2. Опишите наиболее выраженные шкалы профиля\n";
        $prompt .= "3. Дайте содержательную интерпретацию личностных особенностей\n";
        $prompt .= "4. Укажите на возможные трудности и ресурсы личности\n";
        $prompt .= "5. Добавьте рекомендации (но помните: это не диагноз!)\n";
        $prompt .= "6. Используйте профессиональный, но доступный язык\n";
        $prompt .= "7. Обязательно включите дисклеймер о том, что это не заменяет очную консультацию\n";
        $prompt .= "8. Объём: 800-1500 слов\n";
        $prompt .= "9. Форматирование: используйте заголовки, списки для удобства чтения\n";
        
        $prompt .= "\n\n## Важные этические принципы\n";
        $prompt .= "- Не ставьте диагнозы\n";
        $prompt .= "- Избегайте категоричных формулировок\n";
        $prompt .= "- Подчёркивайте, что результаты - это не приговор, а информация для размышления\n";
        $prompt .= "- Уважайте достоинство клиента\n";
        $prompt .= "- Напоминайте о возможности обратиться к специалисту\n";
        
        $prompt .= "\n\nСоставьте развёрнутую интерпретацию на русском языке:\n";
        
        return $prompt;
    }
    
    /**
     * Call OpenRouter API
     */
    private function callOpenRouter(string $prompt): string
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('OpenRouter API key not configured');
        }
        
        $requestBody = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Вы - опытный клинический психолог, специалист по психодиагностике. ' .
                               'Вы составляете профессиональные, этически выверенные интерпретации ' .
                               'результатов психологического тестирования. Вы не ставите диагнозы, ' .
                               'а даёте информацию для размышления и рекомендации.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'temperature' => 0.7,
            'max_tokens' => 2000,
            'top_p' => 1,
            'frequency_penalty' => 0.5,
            'presence_penalty' => 0.5,
        ];
        
        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'HTTP-Referer: https://psytest.local',
            'X-Title: PsyTest Platform',
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \RuntimeException('OpenRouter API error: ' . $error);
        }
        
        if ($httpCode !== 200) {
            throw new \RuntimeException('OpenRouter API error (HTTP ' . $httpCode . '): ' . $response);
        }
        
        $responseData = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON response from OpenRouter');
        }
        
        return $responseData['choices'][0]['message']['content'] ?? '';
    }
    
    /**
     * Parse AI response and extract interpretation
     */
    private function parseAIResponse(string $response): string
    {
        // Clean up response
        $interpretation = trim($response);
        
        // Remove any markdown code blocks if present
        $interpretation = preg_replace('/^```[\w]*\n/', '', $interpretation);
        $interpretation = preg_replace('/\n```$/', '', $interpretation);
        
        // Ensure disclaimer is present
        if (!str_contains($interpretation, 'ознакомительный характер')) {
            $interpretation .= "\n\n---\n\n**Важно:** Данная интерпретация носит исключительно ознакомительный характер " .
                              "и не является диагнозом или заменой профессиональной консультации. " .
                              "Для получения квалифицированной помощи обратитесь к специалисту.";
        }
        
        return $interpretation;
    }
    
    /**
     * Get interpretation by session ID
     */
    public function getInterpretation(string $sessionId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM ai_interpretations WHERE session_id = ? AND payment_status = "completed"',
            [$sessionId]
        );
    }
    
    /**
     * Regenerate interpretation (for admin use)
     */
    public function regenerateInterpretation(string $interpretationId): array
    {
        $interpretation = $this->db->selectOne(
            'SELECT * FROM ai_interpretations WHERE id = ?',
            [$interpretationId]
        );
        
        if (!$interpretation) {
            throw new \RuntimeException('Interpretation not found');
        }
        
        $session = $this->db->selectOne(
            'SELECT * FROM test_sessions WHERE id = ?',
            [$interpretation['session_id']]
        );
        
        $test = $this->db->selectOne(
            'SELECT * FROM tests WHERE id = ?',
            [$session['test_id']]
        );
        
        // Regenerate
        $result = $this->generateInterpretation($session, $test);
        
        // Update record
        $this->db->update(
            'ai_interpretations',
            [
                'interpretation_text' => $result['text'],
                'pdf_path' => $result['pdf_path'],
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            'id = ?',
            [$interpretationId]
        );
        
        return $result;
    }
}
