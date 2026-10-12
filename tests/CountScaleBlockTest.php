<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\ResultSectionRenderer;
use PsyTest\Core\TemplateFunctions;
use PsyTest\Modules\AdhdAdult\AdhdAdultModule;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Шкала «N из 9» с отметкой порога (04.D6): число закрашенных делений, отметка
 * порога, подпись, обрезка значений вне диапазона, PDF- и Word-ветки.
 */
final class CountScaleBlockTest extends TestCase
{
    private const SCALE_NAME = 'Невнимательность';

    private function twig(): Environment
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/templates'), [
            'cache' => false,
            'strict_variables' => true,
        ]);
        TemplateFunctions::register($twig);

        return $twig;
    }

    /** @param array<string, mixed> $extra */
    private function render(mixed $score, array $extra = []): string
    {
        return $this->twig()->render('blocks/count-scale.twig', [
            'scales' => [['name' => self::SCALE_NAME, 'score' => $score, 'max' => AdhdAdultModule::GROUP_MAX, 'threshold' => AdhdAdultModule::GROUP_THRESHOLD]],
        ] + $extra);
    }

    /** @return array<string, array{mixed, int, string, string}> */
    public static function webProvider(): array
    {
        $above = 'Набрано 5 и больше — число признаков значимо';
        $below = 'Ниже порога (5)';

        return [
            'zero' => [0, 0, '0 из 9', $below],
            'four' => [4, 4, '4 из 9', $below],
            'threshold' => [5, 5, '5 из 9', $above],
            'nine' => [9, 9, '9 из 9', $above],
            'negative' => [-3, 0, '0 из 9', $below],
            'above max' => [14, 9, '9 из 9', $above],
            'not a number' => ['abc', 0, '0 из 9', $below],
            'null' => [null, 0, '0 из 9', $below],
        ];
    }

    #[DataProvider('webProvider')]
    public function testWebFilledStepsMarkAndHint(mixed $score, int $filled, string $value, string $hint): void
    {
        $html = $this->render($score);

        self::assertSame(9, substr_count($html, 'class="count-scale__step'));
        self::assertSame($filled, substr_count($html, 'count-scale__step--on'));
        self::assertSame(1, substr_count($html, 'count-scale__mark'));
        self::assertStringContainsString('--count-t: 5', $html);
        self::assertStringContainsString($value, $html);
        self::assertStringContainsString($hint, $html);
        self::assertStringContainsString('role="img"', $html);
        self::assertStringContainsString(self::SCALE_NAME . ': ' . $value . ', порог 5', $html);
    }

    /** @return array<string, array{mixed, int}> */
    public static function pdfProvider(): array
    {
        return ['zero' => [0, 0], 'four' => [4, 4], 'five' => [5, 5], 'nine' => [9, 9], 'over' => [20, 9], 'negative' => [-1, 0], 'text' => ['x', 0]];
    }

    #[DataProvider('pdfProvider')]
    public function testPdfBranchIsTableWithFilledCells(mixed $score, int $filled): void
    {
        $html = $this->render($score, ['_pdf' => true, 'pdf_heading' => 'Число признаков']);

        self::assertStringContainsString('<h2>Число признаков</h2>', $html);
        self::assertStringNotContainsString('count-scale__bar', $html);
        self::assertSame($filled, substr_count($html, 'background-color:#2F6B5E'));
        self::assertSame(9 - $filled, substr_count($html, 'background-color:#E4DDD3'));
        self::assertSame(1, substr_count($html, 'border-left:2px solid'));
        self::assertStringContainsString($filled . ' из 9', $html);
    }

    public function testDocxBranchKeepsPlainText(): void
    {
        $html = $this->render(6, ['_pdf' => true, '_docx' => true]);

        self::assertStringNotContainsString('<table', $html);
        self::assertStringContainsString('6 из 9', $html);
        self::assertStringContainsString('Набрано 5 и больше', $html);
    }

    public function testAdhdSectionUsesScaleBlockAndKeepsTextIndices(): void
    {
        $module = new AdhdAdultModule();
        $results = $module->calculateResults([]);
        $results['inattention_count'] = 6;
        $results['hyperactivity_count'] = 2;
        $section = $module->buildSections($results)[1];

        self::assertSame('blocks/count-scale.twig', $section->block);
        self::assertSame('Число признаков', $section->title);
        self::assertSame(6, $section->data['scales'][0]['score']);
        self::assertSame(2, $section->data['scales'][1]['score']);
        self::assertSame('6 из 9', $section->data['indices'][0]['value']);

        $html = $this->twig()->render($section->block, $section->data);
        self::assertSame(18, substr_count($html, 'class="count-scale__step'));
        self::assertSame(8, substr_count($html, 'count-scale__step--on'));

        $twig = $this->twig();
        $pdf = (new ResultSectionRenderer(static fn (string $t, array $d): string => $twig->render($t . '.twig', $d)))->renderToHtml([$section]);
        self::assertSame(8, substr_count($pdf, 'background-color:#2F6B5E'));
    }
}
