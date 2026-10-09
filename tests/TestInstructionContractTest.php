<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PsyTest\Modules\BaseTestModule;
use PsyTest\Modules\BeckAnxiety\BeckAnxietyModule;
use PsyTest\Modules\BeckDepression\BeckDepressionModule;
use PsyTest\Modules\Hads\HadsModule;
use PsyTest\Modules\IpipNeo120\IpipNeo120Module;
use PsyTest\Modules\Lazarus\LazarusModule;
use PsyTest\Modules\Smil\SmilModule;
use PsyTest\Modules\TestModuleInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Инструкция методики перед первым вопросом (07.K13): данные модуля,
 * запреты для текста СМИЛ и разметка стартовой страницы приглашения и теста.
 */
final class TestInstructionContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    /** @return array<string, array{class-string<TestModuleInterface>}> */
    public static function moduleProvider(): array
    {
        return [
            'beck-anxiety' => [BeckAnxietyModule::class],
            'beck-depression' => [BeckDepressionModule::class],
            'hads' => [HadsModule::class],
            'ipip-neo-120' => [IpipNeo120Module::class],
            'lazarus' => [LazarusModule::class],
            'smil' => [SmilModule::class],
        ];
    }

    public function testEveryModuleMetadataDeclaresAPlainTextInstruction(): void
    {
        $files = glob($this->root . '/modules/*/metadata.json') ?: [];
        self::assertCount(count(self::moduleProvider()), $files, 'A new module must be added to this contract.');

        foreach ($files as $file) {
            $metadata = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($metadata['instruction'] ?? null, $file);
            self::assertNotEmpty($metadata['instruction'], $file);
            self::assertTrue(array_is_list($metadata['instruction']), $file);
            foreach ($metadata['instruction'] as $paragraph) {
                self::assertIsString($paragraph, $file);
                self::assertNotSame('', trim($paragraph), $file);
                self::assertSame(strip_tags($paragraph), $paragraph, "{$file}: plain text only, no HTML.");
                self::assertStringNotContainsString('psytests', mb_strtolower($paragraph), $file);
            }
            self::assertArrayHasKey('description', $metadata, 'description stays alongside the instruction.');
        }
    }

    /** @param class-string<TestModuleInterface> $class */
    #[DataProvider('moduleProvider')]
    public function testModuleExposesItsInstruction(string $class): void
    {
        $module = new $class();
        $instruction = $module->getInstruction();

        self::assertNotEmpty($instruction);
        self::assertTrue(array_is_list($instruction));
        self::assertGreaterThanOrEqual(2, count($instruction));
        self::assertArrayNotHasKey('instruction_html', $module->getMetadata());
    }

    public function testSmilInstructionFollowsSobchikWithoutTheAttentionItemsOrTheAnonymityPromise(): void
    {
        $text = mb_strtolower(implode("\n", (new SmilModule())->getInstruction()));

        // В нашем наборе из 566 пунктов нет пунктов «обведите номер кружочком».
        self::assertStringNotContainsString('кружоч', $text);
        self::assertStringNotContainsString('обвед', $text);
        self::assertStringNotContainsString('обвести', $text);
        // Для приглашения ответы видит специалист: обещание анонимности ложно.
        self::assertStringNotContainsString('никто не станет читать', $text);
        self::assertStringNotContainsString('никто не читает', $text);
        self::assertStringNotContainsString('не имеет доступа', $text);
        self::assertStringNotContainsString('регистрационн', $text, 'No paper answer sheet online.');

        foreach (['первая, непосредственная реакция', 'до конца', '«верно»', '«неверно»', 'двойным отрицанием', 'припадков с судорогами', '«не знаю»', 'недостоверн', 'искренне', 'пол'] as $needle) {
            self::assertStringContainsString($needle, $text, $needle);
        }
    }

    public function testLazarusInstructionMatchesTheDualCardRows(): void
    {
        $text = implode("\n", (new LazarusModule())->getInstruction());

        self::assertStringContainsString('от 1 до 10', $text);
        self::assertStringContainsString('«Я»', $text);
        self::assertStringContainsString('«Партнёр (по моему мнению)»', $text);
        self::assertStringContainsString('Партнёр<span class="dual-scale__who-hint"> (по моему мнению)</span>', $this->read('templates/test-wrapper.twig'));
    }

    public function testAccessorKeepsOnlyNonEmptyStringParagraphs(): void
    {
        $module = new class () extends BaseTestModule {
            protected function initialize(): void
            {
                $this->metadata = ['instruction' => ['  Первый абзац. ', '', 42, ['x'], 'Второй']];
            }

            public function calculateResults(array $answers): array
            {
                return [];
            }

            public function generateInterpretation(array $scores): array
            {
                return [];
            }
        };

        self::assertSame(['Первый абзац.', 'Второй'], $module->getInstruction());
    }

    public function testModuleWithoutInstructionReturnsEmptyList(): void
    {
        $module = new class () extends BaseTestModule {
            protected function initialize(): void
            {
                $this->metadata = ['instruction' => 'не список'];
            }

            public function calculateResults(array $answers): array
            {
                return [];
            }

            public function generateInterpretation(array $scores): array
            {
                return [];
            }
        };

        self::assertSame([], $module->getInstruction());
    }

    public function testInviteStartPageShowsTheInstructionBlockBeforeTheStartButton(): void
    {
        $html = $this->renderInvite(null);

        self::assertStringContainsString('data-test-instruction="block"', $html);
        self::assertStringContainsString('<h2 class="test-instruction__title" id="testInstructionTitle">Инструкция</h2>', $html);
        self::assertStringContainsString('Первый &lt;b&gt;абзац&lt;/b&gt;', $html, 'Paragraphs are escaped plain text.');
        self::assertStringNotContainsString('<details', $html);
        self::assertLessThan(strpos($html, 'Начать тест'), strpos($html, 'data-test-instruction'));
        self::assertLessThan(strpos($html, 'data-test-instruction'), strpos($html, 'Это персональное приглашение'));
    }

    public function testInviteResumePageDoesNotRepeatTheInstruction(): void
    {
        $html = $this->renderInvite(['answered' => 3, 'total' => 566]);

        // 07.K15: человек инструкцию уже читал — при продолжении она не показывается.
        self::assertStringNotContainsString('data-test-instruction', $html);
        self::assertStringNotContainsString('<details', $html);
        self::assertStringContainsString('Продолжить', $html);
    }

    public function testInviteStartWithoutInstructionRendersNoBlock(): void
    {
        $html = $this->twig()->render('test-invite-start.twig', ['token' => 't', 'test_name' => 'X', 'resume' => null, 'csrf_token' => 'c', 'instruction' => []]);

        self::assertStringNotContainsString('data-test-instruction', $html);
        self::assertStringContainsString('Начать тест', $html);
    }

    public function testGuestTestPageShowsTheInstructionAsAPlainBlockBeforeTheFirstQuestion(): void
    {
        $html = $this->renderWrapper([]);

        self::assertStringContainsString('data-test-instruction="block"', $html);
        self::assertStringContainsString('<section class="test-instruction" id="testInstruction"', $html);
        self::assertStringNotContainsString('<details class="ai-report__details" id="testInstruction"', $html);
        self::assertLessThan(strpos($html, 'id="testForm"'), strpos($html, 'id="testInstruction"'));
        self::assertLessThan(strpos($html, 'id="testInstruction"'), strpos($html, 'id="progressContainer"'));
        // 04.T1 hint and K12 resume config stay in place.
        self::assertStringContainsString('id="keyHint"', $html);
        self::assertStringContainsString('resume: false', $html);
    }

    public function testResumedOrInvitedTestPageShowsNoInstruction(): void
    {
        foreach ([['is_resume' => true], ['instruction_collapsed' => true]] as $context) {
            $html = $this->renderWrapper($context);
            self::assertStringNotContainsString('data-test-instruction', $html);
            self::assertStringNotContainsString('id="testInstruction"', $html);
            self::assertStringContainsString('id="progressContainer"', $html);
        }
    }

    public function testScriptHidesTheInstructionOnceTheTestStarts(): void
    {
        $script = $this->read('public/js/test-taking.js');

        self::assertStringContainsString("document.getElementById('testInstruction')", $script);
        self::assertStringContainsString('instruction.hidden = true;', $script);
        self::assertStringContainsString("demographicsSection.style.display = 'none';\n        }\n        hideInstruction();", $script);
        self::assertStringContainsString("hideInstruction();\n                    saveAnswer(e.target);", $script);
        self::assertStringNotContainsString('collapseInstruction', $script);
    }

    public function testControllerPassesTheInstructionToEveryStartPage(): void
    {
        $controller = $this->read('controllers/TestController.php');

        // 07.K15: показ идёт через подмену владельца, а не напрямую из файла методики.
        self::assertSame(3, substr_count($controller, "'instruction' => \$this->instructionOf(\$module),"));
        self::assertStringContainsString("'instruction' => \$this->instructionFor(", $controller);
        self::assertStringContainsString("'instruction_collapsed' => true,", $controller);
        self::assertStringContainsString('tests.slug AS test_slug', $this->read('core/TestInviteService.php'));
    }

    public function testQuietDisclosureRulesAreAvailableOnPublicPages(): void
    {
        $main = $this->read('public/css/main.css');

        self::assertStringContainsString('.disclosure-stack .ai-report__summary {', $main);
        self::assertStringContainsString('.test-instruction__body {', $main);
        self::assertMatchesRegularExpression('/\.test-instruction__body \{[^}]*max-width: var\(--measure-wide\);/', $main);
        self::assertStringNotContainsString('.disclosure-stack .ai-report__summary {', $this->read('public/css/cabinet.css'));
    }

    /** @param array{answered: int, total: int}|null $resume */
    private function renderInvite(?array $resume): string
    {
        return $this->twig()->render('test-invite-start.twig', [
            'token' => str_repeat('a', 64),
            'test_name' => 'СМИЛ',
            'resume' => $resume,
            'csrf_token' => 'csrf',
            'instruction' => ['Первый <b>абзац</b>', 'Второй абзац'],
        ]);
    }

    /** @param array<string, mixed> $context */
    private function renderWrapper(array $context): string
    {
        $module = new BeckAnxietyModule();

        return $this->twig()->render('test-wrapper.twig', array_merge([
            'test' => array_merge(['slug' => 'beck-anxiety', 'id' => 1], $module->getMetadata()),
            'session' => ['id' => 's', 'session_token' => 'tok'],
            'questions' => array_slice($module->getQuestions(), 0, 2),
            'module' => $module,
            'csrf_token' => 'csrf',
            'instruction' => $module->getInstruction(),
        ], $context));
    }

    private function twig(): Environment
    {
        $twig = new Environment(new FilesystemLoader($this->root . '/templates'), ['cache' => false]);
        \PsyTest\Core\TemplateFunctions::register($twig);

        return $twig;
    }

    private function read(string $path): string
    {
        return (string) file_get_contents($this->root . '/' . $path);
    }
}
