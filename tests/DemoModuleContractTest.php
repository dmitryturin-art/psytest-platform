<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\AnswerValidator;
use PsyTest\Core\ModuleLoader;
use PsyTest\Core\ResultSectionRenderer;
use PsyTest\Modules\ResultSection;
use PsyTest\Modules\TestModuleInterface;
use PsyTest\Tests\Fixtures\Demo\DemoWellbeingModule;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require_once __DIR__ . '/fixtures/demo-wellbeing/DemoWellbeingModule.php';

/**
 * Exit-criterion proof for stage 03: a new test type is added by dropping
 * a module directory (metadata + questions + module class extending
 * BaseTestModule) with zero changes to controllers, renderer, validator or
 * templates. The fixture lives under tests/fixtures and is not registered
 * in the catalog database.
 */
final class DemoModuleContractTest extends TestCase
{
    public function testFixtureModuleIsDiscoveredBySharedLoader(): void
    {
        $loader = new ModuleLoader(dirname(__DIR__) . '/tests/fixtures', null);
        $loader->discover();

        $module = $loader->getModule('demo-wellbeing');

        self::assertInstanceOf(TestModuleInterface::class, $module);
        $metadata = $module->getMetadata();
        self::assertSame('demo-wellbeing', $metadata['slug']);
        self::assertSame(4, $metadata['question_count']);
    }

    public function testModuleOverridesOnlyDomainMethods(): void
    {
        $reflection = new \ReflectionClass(DemoWellbeingModule::class);

        $overridden = [];
        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === DemoWellbeingModule::class && $method->isPublic()) {
                $overridden[] = $method->getName();
            }
        }

        self::assertSame(
            ['calculateResults', 'generateInterpretation', 'buildSections'],
            $overridden,
            'A minimal module must rely on Base declarative defaults for everything else.'
        );
    }

    public function testAnswersAreValidatedBySharedSchemaValidator(): void
    {
        $module = new DemoWellbeingModule();

        $valid = [1 => 2, 2 => 3, 3 => 0, 4 => 1];
        self::assertSame([], AnswerValidator::validate($module, $valid, true));

        $outOfRange = [1 => 9, 2 => 3, 3 => 0, 4 => 1];
        self::assertNotSame([], AnswerValidator::validate($module, $outOfRange, true));
    }

    public function testResultsFlowThroughSectionsToWebAndPdf(): void
    {
        $module = new DemoWellbeingModule();
        $answers = [1 => 2, 2 => 2, 3 => 2, 4 => 2];

        $results = $module->calculateResults($answers);
        self::assertSame(8, $results['total']);
        self::assertSame('moderate', $results['level']);

        $sections = $module->buildSections($results);
        $types = array_map(static fn ($s) => $s->type, $sections);
        self::assertSame(
            [ResultSection::TYPE_SCORE_BADGE, ResultSection::TYPE_INTERPRETATION, ResultSection::TYPE_RECOMMENDATIONS],
            $types,
        );

        $twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/templates'), [
            'cache' => false,
            'strict_variables' => true,
        ]);

        $web = '';
        foreach ($sections as $section) {
            $web .= $twig->render(
                $section->block,
                ['basePath' => '', 'appName' => 'PsyTest'] + $section->data + ['_section_type' => $section->type]
            );
        }
        self::assertStringContainsString('8', $web);
        self::assertStringContainsString('Умеренное самочувствие', $web);

        $renderer = new ResultSectionRenderer(
            fn (string $template, array $data): string => $twig->render($template . '.twig', $data)
        );
        $pdf = $renderer->renderToHtml($sections);
        self::assertStringContainsString('score-badge', $pdf);
        self::assertStringContainsString('recommendations-list', $pdf);
    }
}
