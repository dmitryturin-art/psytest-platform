<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\PDFGenerator;
use PsyTest\Core\ResultSectionRenderer;
use PsyTest\Core\TemplateFunctions;
use PsyTest\Modules\ResultSection;
use PsyTest\Modules\Smil\SmilModule;
use PsyTest\Modules\Smil\SmilProfileImageRenderer;
use PsyTest\Tests\Support\FailingProfileChartImageRenderer;
use PsyTest\Tests\Support\UnavailableProfileChartImageRenderer;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Канонический профиль СМИЛ в PDF (07.WP7b).
 *
 * В PDF результата и выгрузки кейса профиль — картинка того же бланка с теми
 * же ломаными, а не столбчатая подмена; столбцы остаются только откатом,
 * когда картинку нарисовать нельзя.
 */
final class ProfileChartPdfTest extends TestCase
{
    /** Признак прежней столбчатой подмены. */
    private const BAR_CHART_MARKER = 'Норма: 30–70 T';

    private TestHandler $log;

    protected function setUp(): void
    {
        $this->log = new TestHandler();
        UnavailableProfileChartImageRenderer::$renderCalled = false;
    }

    public function testSmilPdfProfileIsTheCanonicalImageNotBars(): void
    {
        $this->requireRenderer();

        $html = $this->renderer()->renderToHtml([$this->smilChartSection()]);

        self::assertMatchesRegularExpression('#<img src="data:image/png;base64,[A-Za-z0-9+/=]+"#', $html);
        self::assertStringNotContainsString(self::BAR_CHART_MARKER, $html, 'Столбчатая подмена профиля в PDF запрещена.');
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<canvas', $html);
        // Легенда — тексты веб-графика.
        self::assertStringContainsString('Кривая 1: Шкалы достоверности (L, F, K)', $html);
        self::assertStringContainsString('Кривая 2: Клинические шкалы (1-9, 0)', $html);
        self::assertStringContainsString('Норма (30-70T)', $html);

        preg_match('#data:image/png;base64,([A-Za-z0-9+/=]+)#', $html, $match);
        $size = getimagesizefromstring((string) base64_decode($match[1], true));
        self::assertIsArray($size);
        self::assertSame(SmilProfileImageRenderer::BASE_WIDTH * SmilProfileImageRenderer::SCALE, $size[0]);
        self::assertSame([], $this->log->getRecords(), 'Удачный рендер не пишет в журнал.');
    }

    public function testResultPdfDocumentCarriesTheImage(): void
    {
        $this->requireRenderer();

        $module = new SmilModule();
        $html = $this->renderer()->renderToHtml($module->buildSections(['is_pdf' => true] + $this->smilResults()));
        $pdf = (new PDFGenerator(sys_get_temp_dir()))->generate($html, 'profile.pdf', false);

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertMatchesRegularExpression('#/Subtype\s*/Image#', $pdf, 'DomPDF встроил картинку профиля.');
    }

    public function testFailingRendererFallsBackToTheStaticChartAndLogsWithoutClientData(): void
    {
        $html = $this->renderer()->renderToHtml([$this->section(FailingProfileChartImageRenderer::class)]);

        self::assertStringContainsString(self::BAR_CHART_MARKER, $html);
        self::assertStringNotContainsString('data:image/png', $html);

        self::assertTrue($this->log->hasErrorThatContains('profile chart image failed'));
        $record = $this->log->getRecords()[0];
        self::assertSame(['renderer', 'exception', 'message'], array_keys($record->context));
        self::assertStringNotContainsString('83', json_encode($record->context, JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function testUnavailableRendererIsNotCalledAndFallsBack(): void
    {
        $html = $this->renderer()->renderToHtml([$this->section(UnavailableProfileChartImageRenderer::class)]);

        self::assertStringContainsString(self::BAR_CHART_MARKER, $html);
        self::assertFalse(UnavailableProfileChartImageRenderer::$renderCalled);
        self::assertTrue($this->log->hasWarningThatContains('unavailable'));
    }

    public function testForeignClassIsNeverInstantiated(): void
    {
        $html = $this->renderer()->renderToHtml([$this->section(\ArrayObject::class)]);

        self::assertStringContainsString(self::BAR_CHART_MARKER, $html);
        self::assertTrue($this->log->hasWarningThatContains('is not a ProfileChartImageRenderer'));
    }

    public function testSectionWithoutDeclaredRendererKeepsTheStaticChart(): void
    {
        $section = new ResultSection(
            type: ResultSection::TYPE_PROFILE_CHART,
            title: 'Профиль',
            data: ['scores' => [50, 60], 'labels' => ['A', 'B']],
        );

        $html = $this->renderer()->renderToHtml([$section]);

        self::assertStringContainsString(self::BAR_CHART_MARKER, $html);
        self::assertSame([], $this->log->getRecords());
    }

    private function requireRenderer(): void
    {
        if (!SmilProfileImageRenderer::isAvailable()) {
            self::markTestSkipped('Нет GD с PNG: профиль СМИЛ в PDF в этом окружении идёт откатом.');
        }
    }

    private function section(string $rendererClass): ResultSection
    {
        return new ResultSection(
            type: ResultSection::TYPE_PROFILE_CHART,
            title: 'Профиль личности',
            data: [
                'scores' => [83, 83, 83, 83, 83, 83, 83, 83, 83, 83, 83, 83, 83],
                'labels' => ['L', 'F', 'K', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0'],
                'pdf_image_renderer' => $rendererClass,
            ],
        );
    }

    private function smilChartSection(): ResultSection
    {
        foreach ((new SmilModule())->buildSections(['is_pdf' => true] + $this->smilResults()) as $section) {
            if ($section->type === ResultSection::TYPE_PROFILE_CHART) {
                return $section;
            }
        }
        self::fail('СМИЛ обязан отдавать секцию профиля.');
    }

    /**
     * Детерминированный протокол, как в RendererContractTest.
     *
     * @return array<string, mixed>
     */
    private function smilResults(): array
    {
        $module = new SmilModule();
        $answers = [];
        foreach ($module->getQuestions() as $i => $question) {
            $answers[$question['id']] = [0, 1, 2][$i % 3];
        }
        $answers['gender'] = 'male';

        return $module->calculateResults($answers);
    }

    private function renderer(): ResultSectionRenderer
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/templates'), [
            'cache' => false,
            'strict_variables' => true,
        ]);
        TemplateFunctions::register($twig);

        return new ResultSectionRenderer(
            static fn (string $template, array $data): string => $twig->render($template . '.twig', $data),
            new Logger('pdf-test', [$this->log]),
        );
    }
}
