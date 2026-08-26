<?php

/**
 * Test Module Interface
 *
 * All test modules must implement this interface
 */

declare(strict_types=1);

namespace PsyTest\Modules;

interface TestModuleInterface
{
    /**
     * Get test metadata
     *
     * @return array<string, mixed> Metadata: slug, name, description, question_count,
     *                              estimated_time, scales, requires_demographics {gender, age, ...},
     *                              result_template, test_template.
     */
    public function getMetadata(): array;

    /**
     * Get test questions
     *
     * Question shape is module-specific:
     *  - BDI/HADS/BAI: {id, text, options: [{value, text}]}
     *  - SMIL:         {id, text_male, text_female, is_control, scales: [{scale, direction}]}
     *
     * @return list<array<string, mixed>> Structured questions.
     */
    public function getQuestions(): array;

    /**
     * Calculate test results from answers
     *
     * @param array<int|string, mixed> $answers User answers (question_id => answer).
     *
     * @return array<string, mixed> Calculated scores and raw results; shape is module-specific.
     */
    public function calculateResults(array $answers): array;

    /**
     * Build result sections for structured rendering.
     *
     * Each section is a ResultSection with type, title, data, and optional twig block.
     * Sections are rendered by result-layout.twig using reusable block components.
     *
     * @param array<string, mixed> $results Calculated results from calculateResults().
     *
     * @return list<ResultSection> Ordered list of result sections.
     */
    public function buildSections(array $results): array;

    /**
     * Generate interpretation from scores
     *
     * @param array<string, mixed> $scores Calculated scores.
     *
     * @return array{summary: string, scales?: list<array<string, mixed>>, recommendations?: list<string>}
     */
    public function generateInterpretation(array $scores): array;

    /**
     * Declarative capabilities of this module (Module API v2).
     *
     * @return list<string> Subset of ModuleCapability constants.
     */
    public function getCapabilities(): array;

    /**
     * Declarative answer schema used by the shared AnswerValidator.
     *
     * @return array{
     *   answer_type: 'ternary'|'scale10'|'options',
     *   key_template: 'plain'|'dual',
     *   extra_keys: list<string>,
     *   requires_gender: bool,
     * }
     */
    public function getAnswerSchema(): array;

    /**
     * Check if module supports pair comparison mode
     *
     * @return bool True if pair mode is supported
     */
    public function supportsPairMode(): bool;

    /**
     * Compare two session results (for pair mode)
     *
     * @param array<string, mixed> $results1 First session results.
     * @param array<string, mixed> $results2 Second session results.
     *
     * @return array<string, mixed> Comparison data.
     */
    public function comparePairResults(array $results1, array $results2): array;

    /**
     * Prepare web chart data for a pair comparison (renderer contract).
     *
     * All geometry belongs to the module; the shared layer only renders
     * the returned structure via blocks/pair-chart.twig. Modules without
     * a web pair chart return null.
     *
     * @param array<string, mixed> $comparison Result of comparePairResults().
     *
     * @return array<string, mixed>|null
     */
    public function pairChartData(array $comparison): ?array;

    /**
     * Get custom test template (optional)
     *
     * @return string|null Template name or null for default
     */
    public function getTestTemplate(): ?string;

    /**
     * Get custom JavaScript for test (optional)
     *
     * @return string|null JavaScript code or file path
     */
    public function getCustomJavaScript(): ?string;
}
