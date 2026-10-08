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
     *   requires_age: bool,
     *   age_range: array{min: int, max: int},
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
     * Structured payload for an external AI report, or null when the module
     * has no AI report in this mode. BaseTestModule gives every methodology a
     * universal individual context (07.WP10); whether it is sent at all is the
     * owner's switch per methodology (AiReportAvailability).
     *
     * The module — not the shared layer — decides what leaves the platform.
     * PRODUCT_RULES §6: the AI receives the computed result, never HTML, PDF
     * or a screenshot; §11: nothing identifying travels with it. Whatever this
     * returns is exactly what a provider adapter is allowed to send, so it must
     * contain no tokens, ids, email, names or free text typed by the respondent.
     *
     * @param array<string, mixed> $results Module results: calculateResults()
     *                                      for 'individual', comparePairResults()
     *                                      for 'pair'.
     * @param string $mode 'individual' or 'pair'.
     *
     * @return array<string, mixed>|null
     */
    public function aiReportContext(array $results, string $mode): ?array;

    /**
     * Respondent's answers to every item for the AI context (07.WP10).
     *
     * Called by the context builder only when the owner allowed item answers
     * for this methodology and the module context has no `items` of its own.
     * Each row: item number, the question text the respondent saw, the
     * answer label and its value. Nothing identifying.
     *
     * @param array<int|string, mixed> $answers Raw session answers.
     *
     * @return list<array{number: int, text: string, answer_label: string, value: int|string|null}>
     */
    public function aiReportItems(array $answers): array;

    /**
     * Respondent instruction shown before the first question (07.K13).
     *
     * Plain-text paragraphs from metadata.json `instruction`; no HTML.
     * A module without an instruction returns an empty list.
     *
     * @return list<string>
     */
    public function getInstruction(): array;

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
