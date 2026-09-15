<?php

declare(strict_types=1);

namespace PsyTest\Tests\Smil;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\Smil\Scoring\AdditionalScalesCalculator;

/**
 * Поведение калькулятора дополнительных шкал на партиях 05.S3.1–05.S3.5.
 *
 * Численные ожидания живут в AdditionalScalesReferenceTest (независимый
 * эталон по источнику); здесь проверяется контракт: форма результата,
 * кодировка ответов, применение норм по полу.
 */
final class AdditionalScalesCalculatorTest extends TestCase
{
    private AdditionalScalesCalculator $calc;

    /** @var list<array<string, mixed>> */
    private array $definitions;

    protected function setUp(): void
    {
        $runtime = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/modules/smil/additional-scales-v2.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->definitions = $runtime['scales'];
        $this->calc = new AdditionalScalesCalculator($this->definitions);
    }

    public function testReturnsBothVerifiedBatches(): void
    {
        $results = $this->calc->calculate($this->uniformAnswers(1), 'male');

        self::assertCount(104, $results);
        $expected = [
            'A', 'R', 'Es', 'Do', 'Re', 'CYN', 'OH', 'LRN', 'MAT', 'DPR', 'DSU', 'DRT', 'DOV', 'DRX', 'DPN', 'EGO',
            'CNV', 'GLM', 'DNS', 'EGC', 'HDC', 'HLT', 'HYP', 'HYS', 'ARP', 'SOM', 'HYO', 'HYL', 'IMP',
            'ANC', 'GLT', 'NEU', 'NOC', 'NUC', 'SOR',
            'EPI', 'PAR', 'PRS', 'POI', 'NAI', 'PAO', 'PAS', 'PRC', 'PPD', 'FMD',
            'AUT', 'PDO', 'PDS', 'SZP', 'PFA', 'PNE', 'PSZ', 'SAL', 'EAL', 'BSE',
            'ALD', 'RSP', 'Ca', 'Cl', 'Cs', 'CRM1', 'Do2', 'CRM2', 'Ds', 'IMV',
            'GMA', 'HCO', 'Hv', 'Hy2', 'Hy5', 'In', 'IQR', 'CHO', 'Mf4', 'Or',
            'Pr', 'RCD', 'SCZ', 'To', 'TCH', 'ULC', 'LAC', 'Wa', 'SDF',
        ];
        foreach ($expected as $code) {
            self::assertArrayHasKey($code, $results, "шкала {$code} отсутствует");
        }

        // №72 «Предипохондрическое состояние»: нормы издания — дубль строки №74,
        // считать T-балл не по чему, поэтому шкалы в расчёте нет.
        self::assertArrayNotHasKey('PHC', $results);
    }

    /** Единственные шкалы, которым владелец утвердил пояснительное поле note. */
    private const CODES_WITH_NOTE = ['SOM', 'RPL', 'CRM1', 'Do2', 'CRM2', 'Ds', 'HCO', 'Pr', 'To'];

    public function testScaleCarriesRawTNormsProvenanceAndNoClinicalText(): void
    {
        $results = $this->calc->calculate($this->uniformAnswers(1), 'male');
        $scale = $results['A'];

        self::assertSame(
            ['id', 'code', 'name', 'raw', 't', 'M', 'sigma', 'max_raw', 'answered', 'level', 'level_name', 'source', 'status', 'note'],
            array_keys($scale)
        );
        self::assertSame('sobchik-001', $scale['id']);
        self::assertSame('verified', $scale['status']);
        self::assertSame('', $scale['note'], 'у шкалы без оговорки примечание пустое');
        self::assertSame(195, $scale['source']['page_print']);
        self::assertArrayNotHasKey('interpretation', $scale, 'клинические тексты для новых шкал не выдаются');
    }

    public function testTheEntryWithASourceTypoStaysVerifiedAndCarriesItsExplanation(): void
    {
        $results = $this->calc->calculate($this->uniformAnswers(1), 'male');
        $som = $results['SOM'];

        self::assertSame(87, $som['source']['entry']);
        self::assertSame('verified', $som['status'], 'состав ключа подтверждён, статус не понижается');
        self::assertStringContainsString('Harris', $som['note'], 'примечание называет источник сверки');
        self::assertSame(17, $som['max_raw'], 'Hy4: 17 пунктов');
        self::assertStringNotContainsStringIgnoringCase('норм', $som['note'], 'речь не о нормах, а о строке ключа');

        foreach ($results as $code => $scale) {
            if (!in_array($code, self::CODES_WITH_NOTE, true)) {
                self::assertSame('', $scale['note'], "{$code}: лишнее примечание");
            }
        }
    }

    /**
     * Опечатка в заголовке источника правится в имени, но не прячется.
     *
     * У записи №177 книга печатает «Шкала „Играния роли“». В runtime идёт
     * грамматически верное название, а написание источника остаётся в note,
     * чтобы сверка с книгой не требовала догадок. Ключ и нормы не затронуты.
     */
    public function testTheEntryWithATitleTypoIsRenamedAndSaysSoInItsNote(): void
    {
        $results = $this->calc->calculate($this->uniformAnswers(1), 'male');
        $rpl = $results['RPL'];

        self::assertSame(177, $rpl['source']['entry']);
        self::assertSame('verified', $rpl['status'], 'опечатка заголовка не понижает статус');
        self::assertSame('Шкала «Играние роли»', $rpl['name']);
        self::assertStringContainsString('Играния роли', $rpl['note'], 'примечание сохраняет написание источника');
        self::assertSame(31, $rpl['max_raw']);
    }

    /**
     * Омонимы приложения различимы в расчёте, а не только в документации.
     *
     * Источник печатает «Шкала „Преступность“» дважды (№47 и №52) и «Доминирование»
     * дважды (№49 и №50). Шкалы при этом разные: у пары «Преступность» общих пунктов
     * всего четыре, а №50 — короткая форма внутри №49. В таблице результата и в PDF
     * одинаковые имена читались бы как дубль строки, поэтому runtime разводит их
     * римскими цифрами, а note объясняет, откуда цифры взялись.
     */
    public function testHomonymousTitlesAreSeparatedInRuntimeAndExplainedInNotes(): void
    {
        $results = $this->calc->calculate($this->uniformAnswers(1), 'male');

        self::assertSame('Шкала «Преступность (I)»', $results['CRM1']['name']);
        self::assertSame('Шкала «Преступность (II)»', $results['CRM2']['name']);
        self::assertSame(12, $results['CRM1']['max_raw']);
        self::assertSame(33, $results['CRM2']['max_raw']);
        self::assertStringContainsString('одинаково', $results['CRM1']['note']);
        self::assertStringContainsString('одинаково', $results['CRM2']['note']);

        self::assertSame('Шкала «Доминирование»', $results['Do']['name']);
        self::assertSame('Доминирование (II)', $results['Do2']['name']);
        self::assertSame(28, $results['Do']['max_raw']);
        self::assertSame(16, $results['Do2']['max_raw']);
        self::assertStringContainsString('№49', $results['Do2']['note']);

        $names = array_map(static fn (array $scale): string => $scale['name'], $results);
        self::assertSame(count($names), count(array_unique($names)), 'имена шкал в расчёте дублируются');
    }

    /**
     * Запись с чужими нормами в расчёт не попала, а её «двойник» — попал с оговоркой.
     *
     * №74 несёт те же M и sigma, что напечатаны у выведенной №72. Владелец решил,
     * что строка принадлежит №74 (для 34 пунктов значения правдоподобны, для 55 —
     * нет), и note обязан это сказать: иначе совпадение выглядит как ошибка сборки.
     */
    public function testTheScaleThatInheritedTheDisputedNormsSaysSoInItsNote(): void
    {
        $results = $this->calc->calculate($this->uniformAnswers(1), 'male');
        $hco = $results['HCO'];

        self::assertSame(74, $hco['source']['entry']);
        self::assertSame('verified', $hco['status']);
        self::assertSame(34, $hco['max_raw']);
        self::assertStringContainsString('№72', $hco['note']);
        self::assertArrayNotHasKey('PHC', $results);
    }

    public function testAllTrueScoresEveryTrueItemAndNoFalseItem(): void
    {
        $results = $this->calc->calculate($this->uniformAnswers(1), 'male');

        foreach ($this->definitions as $definition) {
            $code = $definition['code'];
            self::assertSame(count($definition['key']['true']), $results[$code]['raw'], "{$code}: raw при всех «верно»");
            self::assertSame($results[$code]['max_raw'], $results[$code]['answered'], "{$code}: answered");
        }
    }

    public function testUnknownAnswersAreNotCountedAsAnswered(): void
    {
        $results = $this->calc->calculate($this->uniformAnswers(2), 'male');

        foreach ($results as $code => $scale) {
            self::assertSame(0, $scale['raw'], "{$code}: «не знаю» не даёт сырых баллов");
            self::assertSame(0, $scale['answered'], "{$code}: «не знаю» не считается отвеченным");
        }
    }

    public function testNormsFollowTheRespondentSex(): void
    {
        $answers = $this->uniformAnswers(1);
        $male = $this->calc->calculate($answers, 'male');
        $female = $this->calc->calculate($answers, 'female');

        $definitions = [];
        foreach ($this->definitions as $definition) {
            $definitions[$definition['code']] = $definition;
        }

        foreach ($male as $code => $scale) {
            self::assertSame($definitions[$code]['norms']['male']['M'], $scale['M'], "{$code}: мужская M");
            self::assertSame($definitions[$code]['norms']['female']['M'], $female[$code]['M'], "{$code}: женская M");
        }

        self::assertNotEquals($male['A']['t'], $female['A']['t'], 'нормы пола должны влиять на T');
    }

    public function testTScoresStayInsideTheBasicScaleRange(): void
    {
        foreach ([$this->uniformAnswers(1), $this->uniformAnswers(0)] as $answers) {
            foreach ($this->calc->calculate($answers, 'female') as $code => $scale) {
                self::assertGreaterThanOrEqual(20, $scale['t'], "{$code}: T >= 20");
                self::assertLessThanOrEqual(100, $scale['t'], "{$code}: T <= 100");
            }
        }
    }

    public function testNeutralLevelLabelsHaveNoClinicalWording(): void
    {
        $results = $this->calc->calculate($this->uniformAnswers(1), 'male');

        foreach ($results as $code => $scale) {
            self::assertContains($scale['level'], ['above', 'normal', 'below'], "{$code}: уровень");
            self::assertContains(
                $scale['level_name'],
                ['выше нормы', 'в пределах нормы', 'ниже нормы'],
                "{$code}: подпись уровня"
            );
        }
    }

    /**
     * @return array<int, int>
     */
    private function uniformAnswers(int $value): array
    {
        $answers = [];
        for ($questionId = 1; $questionId <= 566; $questionId++) {
            $answers[$questionId] = $value;
        }

        return $answers;
    }
}
