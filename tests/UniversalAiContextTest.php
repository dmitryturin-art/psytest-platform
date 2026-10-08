<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Ai\AiReportContextBuilder;
use PsyTest\Core\Ai\PromptFixtureContext;
use PsyTest\Core\ModuleLoader;
use PsyTest\Modules\TestModuleInterface;

/**
 * Универсальный контекст ИИ-разбора в базовом модуле (07.WP10, D-056).
 *
 * Модулю без собственного контекста достаточно своих результатов: модели
 * уходят методика, балл с максимумом, уровень, подшкалы и описание уровня.
 * Ответы по пунктам — только по второй галочке владельца. СМИЛ и Лазарус
 * сохраняют свои контексты байт в байт, пока галочка выключена.
 */
final class UniversalAiContextTest extends TestCase
{
    /**
     * Нагрузка СМИЛ и Лазаруса на синтетическом кейсе до пакета 07.WP10
     * (sha256 от JSON без экранирования юникода).
     */
    private const GOLDEN = [
        'smil|individual' => 'be6aca076f6a9034659bed95e69cbca5ff27e7b4abf1c216ea1765b1f81a5b78',
        'lazarus|individual' => 'febf56674b5ab613e39e4f039605773df8fd8aae053cec184533ca6ffcdd2faf',
        'lazarus|pair' => '32ae2a2218c2f1888a18d84ee173034a1a725052807ac5de05a25005b6e0f64d',
    ];

    /** Ключи, которых в нагрузке не может быть ни на каком уровне. */
    private const FORBIDDEN_KEYS = [
        'name', 'email', 'note', 'owner_note', 'label', 'client_label', 'client_id',
        'session_id', 'session_token', 'result_token', 'user_email', 'user_name',
        'ip_address', 'user_agent', 'gender', 'age', 'demographics',
    ];

    private function module(string $slug): TestModuleInterface
    {
        $module = (new ModuleLoader(null, null))->discover()->getModule($slug);
        self::assertNotNull($module, $slug);

        return $module;
    }

    /** @return iterable<string, array{string}> */
    public static function universalModules(): iterable
    {
        yield 'BAI' => ['beck-anxiety'];
        yield 'BDI' => ['bdi'];
        yield 'HADS' => ['hads'];
    }

    #[DataProvider('universalModules')]
    public function testUniversalContextCarriesScoresLevelsAndNothingPersonal(string $slug): void
    {
        $context = PromptFixtureContext::build($this->module($slug), 'individual');

        self::assertSame($slug, $context['test']);
        self::assertNotSame('', $context['test_name']);
        self::assertSame('individual', $context['mode']);
        self::assertArrayNotHasKey('items', $context, 'Без галочки ответы по пунктам не уходят.');
        self::assertNotEmpty($context['score_ranges']);
        self::assertSame(
            ['answered' => count($this->module($slug)->getQuestions()), 'total' => count($this->module($slug)->getQuestions())],
            $context['completeness'],
        );

        if ($slug === 'hads') {
            // Две подшкалы со своими уровнями; суммы по шкалам на странице нет — нет и здесь.
            self::assertArrayNotHasKey('total', $context);
            self::assertCount(2, $context['subscales']);
            foreach ($context['subscales'] as $subscale) {
                self::assertIsInt($subscale['score']);
                self::assertSame(21, $subscale['max']);
                self::assertIsString($subscale['level']);
                self::assertIsString($subscale['level_name']);
                self::assertIsString($subscale['title']);
            }
        } else {
            self::assertIsInt($context['total']['score']);
            self::assertSame(63, $context['total']['max']);
            self::assertIsString($context['level']);
            self::assertIsString($context['level_name']);
            self::assertIsString($context['interpretation']);
        }

        self::assertSame([], array_values(array_intersect(self::FORBIDDEN_KEYS, self::keys($context))));
    }

    #[DataProvider('universalModules')]
    public function testItemsAreAddedOnlyWithTheOwnersSecondCheckbox(string $slug): void
    {
        $module = $this->module($slug);
        $context = PromptFixtureContext::build($module, 'individual', null, true);

        self::assertCount(count($module->getQuestions()), $context['items']);
        $first = $context['items'][0];
        self::assertSame(['number', 'text', 'answer_label', 'value'], array_keys($first));
        self::assertSame(1, $first['number']);
        self::assertSame((string) $module->getQuestions()[0]['text'], $first['text']);
        self::assertIsInt($first['value']);
        self::assertNotSame('Нет ответа', $first['answer_label']);
        self::assertSame([], array_values(array_intersect(self::FORBIDDEN_KEYS, self::keys($context))));

        // Всё остальное в нагрузке не меняется от галочки.
        $without = PromptFixtureContext::build($module, 'individual');
        unset($context['items']);
        self::assertSame($without, $context);
    }

    public function testSmilAndLazarusContextsAreUnchangedWithoutTheCheckbox(): void
    {
        foreach (self::GOLDEN as $key => $hash) {
            [$slug, $mode] = explode('|', $key);
            $context = PromptFixtureContext::build($this->module($slug), $mode);

            self::assertSame(
                $hash,
                hash('sha256', json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                "Контекст {$key} изменился, хотя галочка ответов по пунктам выключена.",
            );
        }
    }

    public function testSmilGetsItemsOnlyWithTheCheckboxAndKeepsEverythingElse(): void
    {
        $smil = $this->module('smil');
        $with = PromptFixtureContext::build($smil, 'individual', null, true);

        self::assertCount(count($smil->getQuestions()), $with['items']);
        self::assertContains($with['items'][0]['answer_label'], ['Верно', 'Неверно', 'Не знаю']);
        // Текст пункта — тот, что видел респондент (синтетический кейс — женская форма).
        $question = $smil->getQuestions()[0];
        self::assertSame((string) ($question['text'] ?? $question['text_female']), $with['items'][0]['text']);

        unset($with['items']);
        self::assertSame(
            self::GOLDEN['smil|individual'],
            hash('sha256', json_encode($with, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        );
    }

    public function testLazarusOwnItemsAreNeverReplacedOrDuplicated(): void
    {
        $lazarus = $this->module('lazarus');
        $without = PromptFixtureContext::build($lazarus, 'individual');
        $with = PromptFixtureContext::build($lazarus, 'individual', null, true);

        self::assertSame($without, $with);
        self::assertSame($without, AiReportContextBuilder::withItems($lazarus, $without, 'individual', []));
        self::assertSame(
            PromptFixtureContext::build($lazarus, 'pair'),
            PromptFixtureContext::build($lazarus, 'pair', null, true),
            'Для пары единой формы ответов нет: нагрузка не меняется.',
        );
    }

    public function testMissingAnswerIsLabelledAsSuch(): void
    {
        $items = $this->module('beck-anxiety')->aiReportItems([]);

        self::assertSame('Нет ответа', $items[0]['answer_label']);
        self::assertNull($items[0]['value']);
    }

    /** @param array<mixed> $node @return list<string> */
    private static function keys(array $node): array
    {
        $keys = [];
        foreach ($node as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            if (is_array($value)) {
                $keys = array_merge($keys, self::keys($value));
            }
        }

        return $keys;
    }
}
