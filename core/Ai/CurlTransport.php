<?php

declare(strict_types=1);

namespace PsyTest\Core\Ai;

final class CurlTransport implements AiTransport
{
    public function request(string $method, string $url, array $headers, ?array $body, int $timeoutSeconds): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new AiProviderException('Не удалось инициализировать HTTP-запрос к провайдеру.');
        }

        $formattedHeaders = [];
        foreach ($headers as $name => $value) {
            $formattedHeaders[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $formattedHeaders,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeoutSeconds),
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        // curl_close() не вызывается: с PHP 8.0 дескриптор закрывается сам,
        // а с 8.5 вызов помечен deprecated и печатает предупреждение.
        unset($handle);

        if ($response === false) {
            // Текст ошибки curl не содержит тела запроса, поэтому клинические
            // данные не попадут ни в исключение, ни в лог выше по стеку.
            throw new AiProviderException('Сетевая ошибка при обращении к провайдеру: ' . $error);
        }

        return ['status' => $status, 'body' => (string) $response];
    }
}
