<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\InvitedCasePresenter;
use PsyTest\Core\ModuleLoader;

final class InvitedCasePresenterTest extends TestCase
{
    private InvitedCasePresenter $presenter;
    private ModuleLoader $modules;

    protected function setUp(): void
    {
        $this->presenter = new InvitedCasePresenter();
        $this->modules = (new ModuleLoader())->discover();
    }

    public function testItRendersReadableSelectedOptionsForAllSingleAnswerModules(): void
    {
        $bai = $this->presenter->answers($this->module('beck-anxiety'), ['1' => 2]);
        self::assertSame('Онемение или покалывание в теле', $bai[0]['question']);
        self::assertSame('Умеренно беспокоило, было неприятно', $bai[0]['answer']);
        self::assertSame('2', $bai[0]['score']);

        $bdi = $this->presenter->answers($this->module('bdi'), ['1' => 1]);
        self::assertSame('Самочувствие', $bdi[0]['question']);
        self::assertSame('Я постоянно расстроен', $bdi[0]['answer']);

        $hads = $this->presenter->answers($this->module('hads'), ['1' => 3]);
        self::assertSame('Я всё время напряжён, мне не по себе', $hads[0]['answer']);
    }

    public function testItRendersLazarusDualAnswersAndSmilTernaryAnswers(): void
    {
        $lazarus = $this->presenter->answers($this->module('lazarus'), ['1_self' => 7, '1_partner' => 4]);
        self::assertSame('Доволен тем, сколько мы разговариваем друг с другом', $lazarus[0]['question']);
        self::assertSame('7 из 10', $lazarus[0]['self_answer']);
        self::assertSame('4 из 10', $lazarus[0]['partner_answer']);

        $smil = $this->presenter->answers($this->module('smil'), ['gender' => 'female', '1' => 1]);
        self::assertNotSame('', $smil[0]['question']);
        self::assertSame('Верно', $smil[0]['answer']);
    }

    public function testItExcludesClientPairInvitationFromTheOwnerCard(): void
    {
        $lazarus = $this->module('lazarus');
        $answers = [];
        foreach ($lazarus->getQuestions() as $question) {
            $id = (string) $question['id'];
            $answers[$id . '_self'] = 7;
            $answers[$id . '_partner'] = 6;
        }

        $sections = $this->presenter->resultSections($lazarus, $lazarus->calculateResults($answers));
        self::assertNotContains('pair_invite', array_map(static fn ($section): string => $section->type, $sections));
        self::assertContains('interpretation', array_map(static fn ($section): string => $section->type, $sections));
    }

    /**
     * Рабочее место кейса (04.D3): у результата с профилем сверху только
     * график, сводка берёт уже посчитанные модулем значения, остальное свёрнуто.
     */
    public function testProfileResultShowsTheChartAndASummaryAndFoldsTheRest(): void
    {
        $smil = $this->module('smil');
        $answers = json_decode((string) file_get_contents(__DIR__ . '/fixtures/smil-reference-answers-valid.json'), true, 512, JSON_THROW_ON_ERROR);
        $results = $smil->calculateResults($answers);
        $sections = $this->presenter->resultSections($smil, $results);

        $workspace = $this->presenter->workspace($sections);

        self::assertSame(['profile_chart'], array_map(static fn ($section): string => $section->type, $workspace['summary']));
        $folded = array_map(static fn (array $fold): string => $fold['section']->type, $workspace['details']);
        self::assertContains('validity', $folded);
        self::assertContains('scales_table', $folded);
        self::assertContains('interpretation', $folded);
        self::assertCount(count($sections) - 1, $workspace['details']);

        $profile = $workspace['profile'];
        self::assertIsArray($profile);
        self::assertSame($results['validity']['is_valid'], $profile['validity']['is_valid']);
        self::assertSame($results['validity']['F_score'], $profile['validity']['F']);
        $interpretation = array_values(array_filter($sections, static fn ($section): bool => $section->type === 'interpretation'))[0];
        self::assertSame($interpretation->data['profile_type_name'], $profile['profile_type']);

        // Вне нормы — ровно те клинические шкалы, что график отмечает красным (<30T или >70T),
        // с теми же T-баллами из расчёта; выше нормы — по убыванию.
        $expected = [];
        foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'] as $code) {
            $t = (int) $results['corrected_scores'][$code];
            if ($t > 70 || $t < 30) {
                $expected[$code] = $t;
            }
        }
        $outside = [];
        foreach ($profile['outside'] as $scale) {
            $outside[$scale['code']] = $scale['t_score'];
        }
        self::assertEqualsCanonicalizing($expected, $outside);
        $above = array_values(array_filter($profile['outside'], static fn (array $scale): bool => $scale['above']));
        for ($i = 1; $i < count($above); $i++) {
            self::assertGreaterThanOrEqual($above[$i]['t_score'], $above[$i - 1]['t_score']);
        }
    }

    public function testInvalidProtocolKeepsTheValidityBlockOpenAndNotRemembered(): void
    {
        $smil = $this->module('smil');
        $answers = json_decode((string) file_get_contents(__DIR__ . '/fixtures/smil-reference-answers-valid.json'), true, 512, JSON_THROW_ON_ERROR);
        $results = $smil->calculateResults($answers);
        self::assertFalse($results['validity']['is_valid'], 'Предусловие: эталонный протокол помечен недостоверным (F ≥ 70).');

        $workspace = $this->presenter->workspace($this->presenter->resultSections($smil, $results));
        $validity = array_values(array_filter($workspace['details'], static fn (array $fold): bool => $fold['section']->type === 'validity'));

        self::assertCount(1, $validity);
        self::assertTrue($validity[0]['open']);
        self::assertFalse($validity[0]['remember']);
        self::assertFalse($workspace['profile']['validity']['is_valid']);
    }

    public function testSimpleResultStaysVisibleAndOnlyItemTablesFold(): void
    {
        $bai = $this->module('beck-anxiety');
        $answers = [];
        foreach ($bai->getQuestions() as $question) {
            $answers[(string) $question['id']] = 1;
        }
        $sections = $this->presenter->resultSections($bai, $bai->calculateResults($answers));
        $workspace = $this->presenter->workspace($sections);
        self::assertSame($sections, $workspace['summary']);
        self::assertSame([], $workspace['details']);
        self::assertNull($workspace['profile']);

        $lazarus = $this->module('lazarus');
        $pair = [];
        foreach ($lazarus->getQuestions() as $question) {
            $pair[$question['id'] . '_self'] = 7;
            $pair[$question['id'] . '_partner'] = 6;
        }
        $workspace = $this->presenter->workspace($this->presenter->resultSections($lazarus, $lazarus->calculateResults($pair)));
        self::assertSame(['scales_table'], array_map(static fn (array $fold): string => $fold['section']->type, $workspace['details']));
        self::assertNotContains('scales_table', array_map(static fn ($section): string => $section->type, $workspace['summary']));
    }

    private function module(string $slug): \PsyTest\Modules\TestModuleInterface
    {
        $module = $this->modules->getModule($slug);
        self::assertNotNull($module);

        return $module;
    }
}
