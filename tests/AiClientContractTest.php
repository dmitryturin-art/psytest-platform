<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\Ai\AiClient;
use PsyTest\Core\Ai\AiProviderException;
use PsyTest\Core\Ai\AiProviderSettings;
use PsyTest\Core\Ai\AiTransport;
use PsyTest\Core\Ai\Prompt;

/**
 * Контракт клиента провайдера. Сеть здесь не используется: транспорт подменён,
 * поэтому проверяется то, что действительно важно — что уходит в запросе,
 * что признаётся ответом и когда вызов запрещён вовсе.
 */
final class AiClientContractTest extends TestCase
{
    private function transport(int $status, array $body): AiTransport&\PHPUnit\Framework\MockObject\Stub
    {
        $transport = $this->createStub(AiTransport::class);
        $transport->method('request')->willReturnCallback(
            function (string $method, string $url, array $headers, ?array $payload, int $timeout) use ($status, $body): array {
                $this->recorded = compact('method', 'url', 'headers', 'payload', 'timeout');

                return ['status' => $status, 'body' => json_encode($body, JSON_UNESCAPED_UNICODE)];
            }
        );

        return $transport;
    }

    /** @var array<string, mixed> */
    private array $recorded = [];

    private function settings(string $key = 'test-key', string $model = 'openrouter/free'): AiProviderSettings
    {
        return new AiProviderSettings('https://provider.example/api/v1', $key, $model, 60);
    }

    private function prompt(string $status = Prompt::STATUS_PUBLISHED, bool $allowsOwnerContext = true): Prompt
    {
        return new Prompt('smil', 'individual', 'professional', 1, $status, 'СИСТЕМНЫЙ ПРОМПТ', $allowsOwnerContext, 'test');
    }

    private function completionBody(): array
    {
        return [
            'model' => 'nvidia/nemotron-3-ultra-550b-a55b:free',
            'choices' => [['message' => ['content' => '  Заключение.  ']]],
            'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 800],
        ];
    }

    public function testCompletionSendsSystemPromptAndStructuredContext(): void
    {
        $client = new AiClient($this->settings(), $this->transport(200, $this->completionBody()));

        $client->complete($this->prompt(), ['test' => 'smil', 'level' => 'high']);

        self::assertSame('POST', $this->recorded['method']);
        self::assertSame('https://provider.example/api/v1/chat/completions', $this->recorded['url']);
        self::assertSame('Bearer test-key', $this->recorded['headers']['Authorization']);
        self::assertSame(60, $this->recorded['timeout']);

        $messages = $this->recorded['payload']['messages'];
        self::assertSame('system', $messages[0]['role']);
        self::assertSame('СИСТЕМНЫЙ ПРОМПТ', $messages[0]['content']);
        self::assertStringContainsString('"level": "high"', $messages[1]['content']);
        self::assertCount(2, $messages, 'Без клинического контекста третьего сообщения быть не должно.');
    }

    public function testServedModelIsRecordedSeparatelyFromTheRequestedOne(): void
    {
        $client = new AiClient($this->settings(), $this->transport(200, $this->completionBody()));

        $completion = $client->complete($this->prompt(), ['test' => 'smil']);

        self::assertSame('Заключение.', $completion->text);
        self::assertSame('openrouter/free', $completion->requestedModel);
        self::assertSame('nvidia/nemotron-3-ultra-550b-a55b:free', $completion->servedModel);
        self::assertTrue($completion->usedRouter(), 'Router выбрал другую модель — это обязано быть видно.');
        self::assertSame(1200, $completion->promptTokens);
        self::assertSame(800, $completion->completionTokens);
    }

    public function testOwnerContextTravelsAsItsOwnMessage(): void
    {
        $client = new AiClient($this->settings(), $this->transport(200, $this->completionBody()));

        $client->complete($this->prompt(), ['test' => 'smil'], 'Женщина, 39 лет. Запрос: тревога.');

        $messages = $this->recorded['payload']['messages'];
        self::assertCount(3, $messages);
        self::assertStringContainsString('Клинический контекст от специалиста', $messages[2]['content']);
        self::assertStringContainsString('Женщина, 39 лет', $messages[2]['content']);
    }

    public function testPromptThatForbidsOwnerContextRefusesIt(): void
    {
        $client = new AiClient($this->settings(), $this->transport(200, $this->completionBody()));

        $this->expectException(AiProviderException::class);
        $client->complete($this->prompt(allowsOwnerContext: false), ['test' => 'smil'], 'Данные интервью.');
    }

    public function testBlankOwnerContextDoesNotTriggerTheRefusal(): void
    {
        $client = new AiClient($this->settings(), $this->transport(200, $this->completionBody()));

        $client->complete($this->prompt(allowsOwnerContext: false), ['test' => 'smil'], '   ');

        self::assertCount(2, $this->recorded['payload']['messages']);
    }

    public function testDraftPromptIsNeverSentToTheProvider(): void
    {
        $client = new AiClient($this->settings(), $this->transport(200, $this->completionBody()));

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('не опубликован');
        $client->complete($this->prompt(status: Prompt::STATUS_DRAFT), ['test' => 'smil']);
    }

    public function testMissingKeyStopsTheCallBeforeAnyRequest(): void
    {
        $client = new AiClient($this->settings(key: ''), $this->transport(200, $this->completionBody()));

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('не настроен');
        $client->complete($this->prompt(), ['test' => 'smil']);
    }

    /**
     * Транспорт, отдающий заранее заданную последовательность ответов.
     *
     * @param list<array{0: int, 1: array<string, mixed>}> $responses
     */
    private function sequenceTransport(array $responses): AiTransport
    {
        $transport = $this->createStub(AiTransport::class);
        $transport->method('request')->willReturnCallback(
            function () use (&$responses): array {
                $this->attempts++;
                [$status, $body] = array_shift($responses) ?? [500, []];

                return ['status' => $status, 'body' => json_encode($body, JSON_UNESCAPED_UNICODE)];
            }
        );

        return $transport;
    }

    private int $attempts = 0;

    /** @var list<int> */
    private array $pauses = [];

    private function sleeper(): callable
    {
        return function (int $seconds): void {
            $this->pauses[] = $seconds;
        };
    }

    public function testOverloadedFreeEndpointIsRetriedAndCanSucceed(): void
    {
        // Бесплатные эндпоинты живут в общем пуле и регулярно отвечают
        // «перегружено, повторите» — без повтора такой поток нерабочий.
        $client = new AiClient(
            $this->settings(),
            $this->sequenceTransport([
                [429, ['error' => 'rate limited']],
                [429, ['error' => 'rate limited']],
                [200, $this->completionBody()],
            ]),
            3,
            $this->sleeper(),
        );

        $completion = $client->complete($this->prompt(), ['test' => 'smil']);

        self::assertSame('Заключение.', $completion->text);
        self::assertSame(3, $this->attempts);
        self::assertSame([1, 2], $this->pauses, 'Пауза между попытками должна расти.');
    }

    public function testRetriesStopAtTheConfiguredLimit(): void
    {
        $client = new AiClient(
            $this->settings(),
            $this->sequenceTransport([
                [429, []], [429, []], [429, []], [429, []],
            ]),
            3,
            $this->sleeper(),
        );

        try {
            $client->complete($this->prompt(), ['test' => 'smil']);
            self::fail('Ожидалось исключение.');
        } catch (AiProviderException $e) {
            self::assertSame(3, $this->attempts);
            self::assertStringContainsString('попыток: 3', $e->getMessage());
            self::assertStringContainsString('перегружена', $e->getMessage());
        }
    }

    public function testRejectedKeyIsNotRetried(): void
    {
        // Повторять запрос с заведомо негодным ключом бессмысленно и только
        // растягивает отказ на минуты.
        $client = new AiClient(
            $this->settings(),
            $this->sequenceTransport([[401, []], [200, $this->completionBody()]]),
            3,
            $this->sleeper(),
        );

        try {
            $client->complete($this->prompt(), ['test' => 'smil']);
            self::fail('Ожидалось исключение.');
        } catch (AiProviderException $e) {
            self::assertSame(1, $this->attempts);
            self::assertStringContainsString('ключ отклонён', $e->getMessage());
            self::assertSame([], $this->pauses);
        }
    }

    public function testForbiddenDoesNotBlameTheKeyAlone(): void
    {
        // Проверено на рабочем сервере: провайдер отвечает 403 на запросы
        // с адреса хостинга ещё до проверки ключа. Сообщение «ключ отклонён»
        // отправило бы владельца искать несуществующую проблему.
        $client = new AiClient($this->settings(), $this->sequenceTransport([[403, []]]), 3, $this->sleeper());

        try {
            $client->complete($this->prompt(), ['test' => 'smil']);
            self::fail('Ожидалось исключение.');
        } catch (AiProviderException $e) {
            self::assertStringContainsString('адрес отправителя', $e->getMessage());
            self::assertSame(1, $this->attempts, 'Повторять запрос с того же адреса бессмысленно.');
        }
    }

    public function testDataPolicyRefusalIsNamedExplicitly(): void
    {
        $client = new AiClient($this->settings(), $this->sequenceTransport([[404, []]]), 3, $this->sleeper());

        try {
            $client->complete($this->prompt(), ['test' => 'smil']);
            self::fail('Ожидалось исключение.');
        } catch (AiProviderException $e) {
            self::assertStringContainsString('политике данных', $e->getMessage());
        }
    }

    public function testProviderErrorBodyIsNotRepeatedInTheException(): void
    {
        // Тело ошибки провайдера может содержать эхо запроса, то есть клинические
        // данные; сообщение исключения попадает в лог, поэтому пересказывать его нельзя.
        $client = new AiClient($this->settings(), $this->transport(400, [
            'error' => ['message' => 'invalid request: {"level":"high","items":[...]}'],
        ]));

        try {
            $client->complete($this->prompt(), ['test' => 'smil']);
            self::fail('Ожидалось исключение.');
        } catch (AiProviderException $e) {
            self::assertStringContainsString('HTTP 400', $e->getMessage());
            self::assertStringNotContainsString('level', $e->getMessage());
            self::assertStringNotContainsString('items', $e->getMessage());
        }
    }

    public function testEmptyAnswerIsTreatedAsFailureNotAsAReport(): void
    {
        $client = new AiClient($this->settings(), $this->transport(200, [
            'model' => 'x',
            'choices' => [['message' => ['content' => '   ']]],
        ]));

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('пустой ответ');
        $client->complete($this->prompt(), ['test' => 'smil']);
    }

    public function testModelCatalogueMarksFreeModels(): void
    {
        $client = new AiClient($this->settings(), $this->transport(200, [
            'data' => [
                ['id' => 'openrouter/free', 'name' => 'Free Router', 'context_length' => 200000,
                    'pricing' => ['prompt' => '0', 'completion' => '0']],
                ['id' => 'anthropic/claude', 'name' => 'Claude', 'context_length' => 200000,
                    'pricing' => ['prompt' => '0.000003', 'completion' => '0.000015']],
                ['name' => 'без идентификатора'],
            ],
        ]));

        $models = $client->models();

        self::assertSame('GET', $this->recorded['method']);
        self::assertSame('https://provider.example/api/v1/models', $this->recorded['url']);
        self::assertCount(2, $models, 'Запись без идентификатора не является моделью.');
        self::assertTrue($models[0]->isFree);
        self::assertFalse($models[1]->isFree);
    }

    public function testSettingsNeverExposeTheKeyInTheirDescription(): void
    {
        $description = $this->settings(key: 'sk-secret-value')->describe();

        self::assertTrue($description['key_present']);
        self::assertStringNotContainsString('sk-secret-value', json_encode($description, JSON_UNESCAPED_UNICODE));
    }
}
