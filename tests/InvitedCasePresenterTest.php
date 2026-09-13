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

    private function module(string $slug): \PsyTest\Modules\TestModuleInterface
    {
        $module = $this->modules->getModule($slug);
        self::assertNotNull($module);

        return $module;
    }
}
