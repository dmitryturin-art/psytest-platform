<?php

declare(strict_types=1);

namespace PsyTest\Core\Ai;

/**
 * HTTP-транспорт провайдера.
 *
 * Вынесен в интерфейс ради одного: тесты не должны ходить в сеть. Боевая
 * реализация — CurlTransport, в тестах подставляется фальшивка с готовым ответом.
 */
interface AiTransport
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body JSON-тело для POST; null — GET.
     *
     * @return array{status: int, body: string}
     */
    public function request(string $method, string $url, array $headers, ?array $body, int $timeoutSeconds): array;
}
