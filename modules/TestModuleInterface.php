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
     * @return array {
     *     @type string $slug URL-friendly identifier
     *     @type string $name Display name
     *     @type string $description Test description
     *     @type int $question_count Number of questions
     *     @type int $estimated_time Estimated completion time in minutes
     *     @type array $scales List of scales measured
     * }
     */
    public function getMetadata(): array;
    
    /**
     * Get test questions
     * 
     * @return array Structured questions with options
     *               Example: [
     *                   ['id' => 1, 'text' => 'Question text', 'options' => [...]],
     *                   ...
     *               ]
     */
    public function getQuestions(): array;
    
    /**
     * Calculate test results from answers
     * 
     * @param array $answers User answers (question_id => answer)
     * @return array Calculated scores and raw results
     */
    public function calculateResults(array $answers): array;
    
    /**
     * Generate interpretation from scores
     * 
     * @param array $scores Calculated scores
     * @return array {
     *     @type string $summary Brief summary
     *     @type array $scales Detailed interpretation per scale
     *     @type array $recommendations Recommendations if any
     * }
     */
    public function generateInterpretation(array $scores): array;
    
    /**
     * Render results as HTML
     * 
     * @param array $results Calculated results
     * @return string HTML output for results page
     */
    public function renderResults(array $results): string;
    
    /**
     * Check if module supports pair comparison mode
     * 
     * @return bool True if pair mode is supported
     */
    public function supportsPairMode(): bool;
    
    /**
     * Compare two session results (for pair mode)
     * 
     * @param array $results1 First session results
     * @param array $results2 Second session results
     * @return array Comparison data
     */
    public function comparePairResults(array $results1, array $results2): array;
}
