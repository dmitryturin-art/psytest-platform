<?php

declare(strict_types=1);

namespace PsyTest\Controllers;

/**
 * Temporary boundary for retired payment routes.
 *
 * The legacy YooMoney/AI flow must not create orders, call an AI provider, or
 * expose stored reports while the new YooKassa flow is being designed.
 */
final class RetiredPaymentController
{
    public function interpretation(string $token): string
    {
        return $this->unavailablePage();
    }

    public function payment(string $token): string
    {
        return $this->unavailablePage();
    }

    public function yoomoneyWebhook(): string
    {
        http_response_code(410);
        header('Content-Type: application/json; charset=utf-8');

        return json_encode([
            'status' => 'retired',
            'message' => 'Legacy payment endpoint is unavailable.',
        ], JSON_UNESCAPED_UNICODE);
    }

    private function unavailablePage(): string
    {
        http_response_code(410);
        header('Content-Type: text/html; charset=utf-8');

        return '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>Функция временно недоступна</title></head>'
            . '<body><h1>Функция временно недоступна</h1><p>Расширенный разбор сейчас находится в разработке.</p>'
            . '<p>Базовый результат теста остаётся доступным бесплатно.</p></body></html>';
    }
}
