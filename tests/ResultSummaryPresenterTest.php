<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\InvitedCasePresenter;
use PsyTest\Core\ModuleLoader;
use PsyTest\Core\ResultSummaryPresenter;
use PsyTest\Core\TemplateFunctions;
use PsyTest\Modules\ResultSection;
use PsyTest\Modules\TestModuleInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Страница результата (04.D5): «Коротко о профиле» рядом с графиком СМИЛ и
 * две шкалы HADS рядом. Секции модулей не пересчитываются и не переставляются.
 */
final class ResultSummaryPresenterTest extends TestCase
{
    private ResultSummaryPresenter $presenter;
    private ModuleLoader $modules;

    protected function setUp(): void
    {
        $this->modules = (new ModuleLoader())->discover();
        $this->presenter = new ResultSummaryPresenter();
    }

    public function testBriefNamesTheSameScalesAsTheCaseCardInPlainWords(): void
    {
        $sections = $this->smilSections(true);

        $brief = $this->presenter->profileBrief($sections);
        self::assertIsArray($brief);
        self::assertTrue($brief['validity']['is_valid']);

        $case = (new InvitedCasePresenter())->workspace($sections)['profile'];
        self::assertIsArray($case);
        $expected = array_map(
            static fn (array $scale): string => ResultSummaryPresenter::SMIL_PLAIN_NAMES[(string) $scale['code']],
            $case['outside'],
        );
        $shown = array_merge(array_column($brief['above'], 'name'), array_column($brief['below'], 'name'));
        self::assertNotSame([], $shown, 'Предусловие: в эталонном профиле есть шкалы вне 30–70T.');
        self::assertSame($expected, $shown);

        foreach (array_merge($brief['above'], $brief['below']) as $scale) {
            self::assertDoesNotMatchRegularExpression('/\(|Шизофрения|Паранойя|Психопатия|Истерия|\d/u', $scale['name']);
            self::assertNotSame('', $scale['level_name']);
        }
    }

    public function testInvalidProtocolIsSaidInTheBrief(): void
    {
        $brief = $this->presenter->profileBrief($this->smilSections(false));

        self::assertIsArray($brief);
        self::assertFalse($brief['validity']['is_valid']);
    }

    public function testResultsWithoutAProfileHaveNoBrief(): void
    {
        $bai = $this->module('beck-anxiety');
        self::assertNull($this->presenter->profileBrief($bai->buildSections($bai->calculateResults($this->sameAnswer($bai, 1)))));
    }

    /**
     * Названия шкал в сводке — названия Собчик из норм модуля, а не
     * придуманные пересказы: словарь сверяется с `basic_scales_norms.json`.
     */
    public function testPlainScaleNamesMatchTheModuleNorms(): void
    {
        $norms = json_decode((string) file_get_contents(dirname(__DIR__) . '/modules/smil/basic_scales_norms.json'), true, 512, JSON_THROW_ON_ERROR);
        $found = [];
        array_walk_recursive($norms, static function (mixed $value, mixed $key) use (&$found): void {
            if ($key === 'name' && is_string($value)) {
                $found[] = mb_strtolower(trim((string) preg_replace('/\s*\(.*\)\s*$/u', '', $value)));
            }
        });

        self::assertCount(10, ResultSummaryPresenter::SMIL_PLAIN_NAMES);
        foreach (ResultSummaryPresenter::SMIL_PLAIN_NAMES as $code => $name) {
            self::assertContains(mb_strtolower($name), $found, 'Шкала ' . $code . ': «' . $name . '» нет в basic_scales_norms.json');
        }
    }

    public function testSmilPageKeepsTheValidityBadgeAndPutsTheBriefNextToTheUnchangedChart(): void
    {
        $sections = $this->smilSections(true);
        $html = $this->render('smil', $sections, $this->presenter->profileBrief($sections));
        $xpath = $this->xpath($html);

        self::assertCount(1, $xpath->query('//div[@class="result-profile"]/div[@class="result-profile__chart"]/div[@class="classic-profile-container"]/div[@id="smilClassicProfile"]'));
        self::assertCount(1, $xpath->query('//div[@class="result-profile"]/aside[@class="result-brief"]'));
        // Значок достоверности остаётся на прежнем месте — его показывают специалисту.
        self::assertCount(1, $xpath->query('//p[contains(@class, "validity-status") and contains(., "Протокол достоверен")]'));
        // Действия по-прежнему сверху, до содержимого.
        self::assertLessThan(strpos($html, 'class="results-content'), strpos($html, 'class="results-actions"'));

        $brief = (string) $xpath->query('//aside[@class="result-brief"]')->item(0)?->textContent;
        foreach (['Шизофрения', 'Паранойя', 'Психастения', 'T-балл', '(Sc)'] as $clinical) {
            self::assertStringNotContainsString($clinical, $brief);
        }
    }

    public function testHadsPutsBothSubscalesSideBySideAndOtherTestsStayAsBefore(): void
    {
        $hads = $this->module('hads');
        $html = $this->render('hads', $hads->buildSections($hads->calculateResults($this->sameAnswer($hads, 1))), null);
        self::assertStringContainsString('class="results-content results-content--paired-scores"', $html);

        $bai = $this->module('beck-anxiety');
        $html = $this->render('beck-anxiety', $bai->buildSections($bai->calculateResults($this->sameAnswer($bai, 1))), null);
        self::assertStringContainsString('<div class="results-content">', $html);
        self::assertStringNotContainsString('result-brief', $html);
    }

    // ---------------------------------------------------------------- helpers

    /** @return list<ResultSection> */
    private function smilSections(bool $valid): array
    {
        $smil = $this->module('smil');
        $answers = json_decode((string) file_get_contents(__DIR__ . '/fixtures/smil-reference-answers-valid.json'), true, 512, JSON_THROW_ON_ERROR);
        $results = $smil->calculateResults($answers);
        self::assertFalse($results['validity']['is_valid'], 'Предусловие: эталонный протокол помечен недостоверным (F ≥ 70).');
        if ($valid) {
            // Вид сводки проверяется и на достоверном протоколе; расчёт не меняется.
            $results['validity']['is_valid'] = true;
        }

        return $smil->buildSections($results);
    }

    /**
     * @param list<ResultSection> $sections
     * @param array<string, mixed>|null $brief
     */
    private function render(string $slug, array $sections, ?array $brief): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/templates'), [
            'cache' => false,
            'strict_variables' => true,
        ]);
        TemplateFunctions::register($twig);

        return $twig->render('result-layout.twig', [
            'appName' => 'PsyTest',
            'basePath' => '',
            'csrf_token' => 'synthetic-csrf-token',
            'test' => ['name' => 'Методика', 'slug' => $slug],
            'session' => [
                'id' => 'synthetic-session-id',
                'session_token' => 'synthetic-result-token',
                'created_at' => '2026-10-02 11:20:00',
                'status' => 'completed',
                'retention_class' => 'anonymous',
                'account_id' => null,
            ],
            'sections' => $sections,
            'profile_brief' => $brief,
            'clinical_safety_notice' => null,
            'result_base' => '/result/' . $slug . '/synthetic-result-token',
            'account_view' => false,
            'visitor_account' => null,
        ]);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function module(string $slug): TestModuleInterface
    {
        $module = $this->modules->getModule($slug);
        self::assertNotNull($module);

        return $module;
    }

    /** @return array<string, int> */
    private function sameAnswer(TestModuleInterface $module, int $value): array
    {
        $answers = [];
        foreach ($module->getQuestions() as $question) {
            $answers[(string) $question['id']] = $value;
        }

        return $answers;
    }
}
