<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\ModuleLoader;
use PsyTest\Modules\Lazarus\LazarusModule;
use PsyTest\Modules\Smil\SmilModule;
use PsyTest\Modules\TestModuleInterface;

/**
 * Границы того, что уходит внешнему ИИ (PRODUCT_RULES §6 и §11).
 *
 * Полезную нагрузку строит модуль, а не общий слой; всё, что возвращает
 * aiReportContext(), провайдер имеет право отправить наружу. Поэтому здесь
 * проверяется не «удобная форма», а отсутствие лишнего.
 */
final class AiReportContextContractTest extends TestCase
{
    /** Поля сессии и респондента, которых не должно быть в полезной нагрузке. */
    private const FORBIDDEN_KEYS = [
        'session_id', 'session_token', 'result_token', 'partner_token',
        'user_email', 'email', 'user_name', 'name', 'ip_address', 'user_agent',
        'gender', 'age', 'demographics', 'created_at', 'expires_at', 'id_hash',
    ];

    private function lazarus(): LazarusModule
    {
        return new LazarusModule();
    }

    /** @return array<string, mixed> */
    private function individualResults(int $shift = 0): array
    {
        $module = $this->lazarus();
        $answers = ['gender' => 'female', 'age' => '34'];

        foreach ($module->getQuestions() as $index => $question) {
            $id = (int) $question['id'];
            $answers[$id . '_self'] = max(1, min(10, 4 + (($index + $shift) % 7)));
            $answers[$id . '_partner'] = max(1, min(10, 3 + (($index + $shift) % 5)));
        }

        return $module->calculateResults($answers);
    }

    /** @param array<string, mixed> $payload @return list<string> */
    private function collectKeys(array $payload): array
    {
        $keys = [];
        $walk = static function (array $node) use (&$walk, &$keys): void {
            foreach ($node as $key => $value) {
                if (is_string($key)) {
                    $keys[] = $key;
                }
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($payload);

        return $keys;
    }

    public function testModulesSendNothingOutsideUntilTheyDeclareIt(): void
    {
        $loader = (new ModuleLoader(null, null))->discover();

        foreach (array_keys($loader->getAllModules()) as $slug) {
            $module = $loader->getModule($slug);
            self::assertInstanceOf(TestModuleInterface::class, $module);

            if (in_array($slug, ['lazarus', 'smil'], true)) {
                continue;
            }

            self::assertNull(
                $module->aiReportContext(['total' => 1], 'individual'),
                "Модуль {$slug} ещё не объявлял, что отдаёт ИИ — по умолчанию должен быть null.",
            );
        }
    }

    private function smil(): SmilModule
    {
        return new SmilModule();
    }

    /** @return array<string, mixed> */
    private function smilResults(string $gender = 'female'): array
    {
        $module = $this->smil();
        $answers = ['gender' => $gender];
        foreach ($module->getQuestions() as $index => $question) {
            $answers[$question['id']] = $index % 3;
        }

        return $module->calculateResults($answers);
    }

    public function testSmilPayloadIsScaleLevelOnly(): void
    {
        $payload = $this->smil()->aiReportContext($this->smilResults(), 'individual');

        self::assertIsArray($payload);
        self::assertSame(
            ['test', 'mode', 'form', 'validity', 'profile', 'indices', 'additional_scales', 'completeness'],
            array_keys($payload),
        );
        self::assertSame('smil', $payload['test']);
        self::assertCount(10, $payload['profile']['scales'], 'Десять базовых шкал; L, F и K живут в блоке достоверности.');
        self::assertSame(['code', 'name', 't', 'level'], array_keys($payload['profile']['scales'][0]));
        self::assertSame(566, $payload['completeness']['total']);
    }

    public function testSmilNeverShipsItemWordingOrPerItemAnswers(): void
    {
        // 566 формулировок принадлежат авторской адаптации методики, и модели
        // они не нужны: она работает с профилем, а не с отдельными пунктами.
        $module = $this->smil();
        $payload = $module->aiReportContext($this->smilResults(), 'individual');
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        self::assertIsString($json);

        foreach (array_slice($module->getQuestions(), 0, 40) as $question) {
            $text = (string) ($question['text'] ?? '');
            if (mb_strlen($text) > 15) {
                self::assertStringNotContainsString(
                    mb_substr($text, 0, 15),
                    $json,
                    'Формулировка пункта не должна уходить наружу.',
                );
            }
        }

        // Ответы по пунктам тоже остаются внутри платформы.
        self::assertArrayNotHasKey('raw_scores', $payload);
        self::assertArrayNotHasKey('answers', $payload);
    }

    public function testSmilDoesNotFeedThePlatformsOwnInterpretationBackToTheModel(): void
    {
        // Иначе модель пересказывает наш готовый вывод вместо собственного анализа.
        $results = $this->smilResults();
        $ownText = $results['profile']['scales']['1']['interpretation'] ?? '';
        self::assertNotSame('', $ownText, 'Предусловие: платформа действительно пишет свой текст по шкале.');

        $json = json_encode($this->smil()->aiReportContext($results, 'individual'), JSON_UNESCAPED_UNICODE);

        self::assertIsString($json);
        self::assertStringNotContainsString(mb_substr($ownText, 0, 20), $json);
        self::assertStringNotContainsString('interpretation', $json);

        // Ведущие шкалы приходят из расчёта целыми объектами — на этом и
        // произошла утечка, поэтому проверяется их форма отдельно.
        foreach ($this->smil()->aiReportContext($results, 'individual')['profile']['dominant'] as $scale) {
            self::assertSame(['name', 't', 'level'], array_keys($scale));
        }
    }

    public function testSmilShipsNoNormativeConstantsOfAdditionalScales(): void
    {
        // T-балл уже посчитан на нашей стороне; нормативные M и σ взяты из
        // руководства и наружу не отправляются.
        $payload = $this->smil()->aiReportContext($this->smilResults(), 'individual');

        self::assertIsArray($payload);
        self::assertNotEmpty($payload['additional_scales']);

        foreach ($payload['additional_scales'] as $scale) {
            self::assertSame(['code', 'name', 't', 'raw'], array_keys($scale));
        }
    }

    public function testSmilReportsTheFormInsteadOfBareGender(): void
    {
        $module = $this->smil();

        self::assertSame('взрослая женская', $module->aiReportContext($this->smilResults('female'), 'individual')['form']);
        self::assertSame('взрослая мужская', $module->aiReportContext($this->smilResults('male'), 'individual')['form']);

        $json = json_encode($module->aiReportContext($this->smilResults('female'), 'individual'), JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('"female"', (string) $json);
    }

    public function testSmilCarriesValidityBecauseTheProfileIsUnreadableWithoutIt(): void
    {
        $payload = $this->smil()->aiReportContext($this->smilResults(), 'individual');

        self::assertIsArray($payload);
        self::assertSame(
            ['is_valid', 'L', 'F', 'K', 'FK_index', 'unknown_count', 'warnings'],
            array_keys($payload['validity']),
        );
        self::assertIsBool($payload['validity']['is_valid']);
    }

    public function testSmilProfileTypeCarriesARussianLabel(): void
    {
        // Модель повторяет внутренний код буквально: в первом живом прогоне
        // в клинический отчёт попало «psychotic».
        $payload = $this->smil()->aiReportContext($this->smilResults(), 'individual');

        self::assertIsArray($payload);
        self::assertSame('psychotic', $payload['profile']['profile_type'], 'Код расчёта сохраняется для прослеживаемости.');
        self::assertSame('психотический', $payload['profile']['profile_type_label']);
    }

    public function testSmilHasNoPairMode(): void
    {
        self::assertNull($this->smil()->aiReportContext($this->smilResults(), 'pair'));
        self::assertNull($this->smil()->aiReportContext([], 'individual'));
    }

    public function testUnknownModeReturnsNothing(): void
    {
        self::assertNull($this->lazarus()->aiReportContext($this->individualResults(), 'professional'));
        self::assertNull($this->lazarus()->aiReportContext($this->individualResults(), ''));
    }

    public function testIncompleteResultsProduceNoPayload(): void
    {
        self::assertNull($this->lazarus()->aiReportContext([], 'individual'));
        self::assertNull($this->lazarus()->aiReportContext(['level' => 'satisfied'], 'individual'));
        self::assertNull($this->lazarus()->aiReportContext([], 'pair'));
    }

    public function testIndividualPayloadMatchesTheDocumentedShape(): void
    {
        $payload = $this->lazarus()->aiReportContext($this->individualResults(), 'individual');

        self::assertIsArray($payload);
        self::assertSame(
            ['test', 'mode', 'items', 'totals', 'level', 'level_name', 'weak_domains', 'large_perception_gaps'],
            array_keys($payload),
        );
        self::assertSame('lazarus', $payload['test']);
        self::assertSame('individual', $payload['mode']);
        self::assertCount(16, $payload['items']);
        self::assertSame(['id', 'domain', 'text', 'self', 'partner_expected', 'gap'], array_keys($payload['items'][0]));
        self::assertSame(['self', 'partner_expected', 'max'], array_keys($payload['totals']));
        self::assertSame(160, $payload['totals']['max']);
    }

    public function testGapIsTheDifferenceBetweenOwnAndExpectedRating(): void
    {
        $payload = $this->lazarus()->aiReportContext($this->individualResults(), 'individual');

        self::assertIsArray($payload);
        foreach ($payload['items'] as $item) {
            self::assertSame(
                $item['self'] - $item['partner_expected'],
                $item['gap'],
                "Пункт {$item['id']}: gap обязан быть self − partner_expected.",
            );
        }
    }

    public function testWeakDomainsAndLargeGapsFollowTheDocumentedThresholds(): void
    {
        $payload = $this->lazarus()->aiReportContext($this->individualResults(), 'individual');

        self::assertIsArray($payload);

        $expectedWeak = [];
        $expectedGaps = [];
        foreach ($payload['items'] as $item) {
            if ($item['self'] <= 5) {
                $expectedWeak[$item['domain']] = true;
            }
            if (abs((int) $item['gap']) >= 3) {
                $expectedGaps[$item['domain']] = true;
            }
        }

        self::assertSame(array_keys($expectedWeak), $payload['weak_domains']);
        self::assertSame(array_keys($expectedGaps), $payload['large_perception_gaps']);
    }

    public function testPairPayloadCarriesBothPartnersAndTheirAgreement(): void
    {
        $module = $this->lazarus();
        $comparison = $module->comparePairResults($this->individualResults(), $this->individualResults(2));

        $payload = $module->aiReportContext($comparison, 'pair');

        self::assertIsArray($payload);
        self::assertSame(
            ['test', 'mode', 'items', 'agreement', 'partner1', 'partner2'],
            array_keys($payload),
        );
        self::assertSame('pair', $payload['mode']);
        self::assertCount(16, $payload['items']);
        self::assertSame(
            ['id', 'domain', 'text', 'partner1_self', 'partner2_self', 'difference', 'partner1_accuracy', 'partner2_accuracy'],
            array_keys($payload['items'][0]),
        );
        self::assertSame('individual', $payload['partner1']['mode']);
        self::assertSame('individual', $payload['partner2']['mode']);
        // Голое число модель прочитать не может — значение идёт с определением.
        self::assertSame(['percent', 'means'], array_keys($payload['agreement']));
        self::assertIsFloat($payload['agreement']['percent']);
        self::assertStringContainsString('собственных оценок', $payload['agreement']['means']);
        self::assertStringContainsString('Ожидаемые оценки партнёра в этот показатель не входят', $payload['agreement']['means']);
    }

    public function testNoIdentifyingFieldTravelsWithEitherPayload(): void
    {
        $module = $this->lazarus();
        $individual = $module->aiReportContext($this->individualResults(), 'individual');
        $comparison = $module->comparePairResults($this->individualResults(), $this->individualResults(2));
        $pair = $module->aiReportContext($comparison, 'pair');

        self::assertIsArray($individual);
        self::assertIsArray($pair);

        foreach (['individual' => $individual, 'pair' => $pair] as $label => $payload) {
            $keys = $this->collectKeys($payload);
            foreach (self::FORBIDDEN_KEYS as $forbidden) {
                self::assertNotContains($forbidden, $keys, "Полезная нагрузка {$label} не должна содержать «{$forbidden}».");
            }
        }
    }

    public function testDemographicsAnsweredByTheRespondentNeverLeak(): void
    {
        // Пол и возраст вводит респондент, они попадают в calculateResults —
        // и обязаны остаться внутри платформы.
        $results = $this->individualResults();
        self::assertSame('female', $results['gender'], 'Предусловие: демография действительно есть в расчёте.');

        $payload = $this->lazarus()->aiReportContext($results, 'individual');

        self::assertIsArray($payload);
        self::assertStringNotContainsString('female', json_encode($payload, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('"34"', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    public function testPayloadIsPlainDataThatSurvivesJsonEncoding(): void
    {
        $payload = $this->lazarus()->aiReportContext($this->individualResults(), 'individual');

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        self::assertSame($payload, json_decode($json, true), 'Наружу уходит ровно то, что вернул модуль.');
        self::assertStringNotContainsString('<', $json, 'Ни HTML, ни разметки — только расчёт (PRODUCT_RULES §6).');
    }
}
