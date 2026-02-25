<?php
/**
 * Base Abstract Test Module
 * 
 * Provides common functionality for all test modules
 */

declare(strict_types=1);

namespace PsyTest\Modules;

abstract class BaseTestModule implements TestModuleInterface
{
    protected string $modulePath;
    protected array $metadata;
    protected ?array $questions = null;
    
    public function __construct()
    {
        // Auto-detect module path
        $reflector = new \ReflectionClass($this);
        $this->modulePath = dirname($reflector->getFileName());
        
        $this->initialize();
    }
    
    /**
     * Initialize module (override in child classes)
     */
    protected function initialize(): void
    {
        // Load metadata from JSON if exists
        $metadataFile = $this->modulePath . '/metadata.json';
        if (file_exists($metadataFile)) {
            $this->metadata = json_decode(file_get_contents($metadataFile), true) ?? [];
        }
    }
    
    /**
     * Get module path
     */
    public function getModulePath(): string
    {
        return $this->modulePath;
    }
    
    /**
     * Load questions from JSON file
     */
    protected function loadQuestionsFromJson(string $filename = 'questions.json'): array
    {
        $filepath = $this->modulePath . '/' . $filename;
        
        if (!file_exists($filepath)) {
            return [];
        }
        
        $content = file_get_contents($filepath);
        return json_decode($content, true) ?? [];
    }
    
    /**
     * Get metadata (override if not using JSON)
     */
    public function getMetadata(): array
    {
        return array_merge([
            'slug' => $this->getSlug(),
            'name' => 'Test',
            'description' => '',
            'question_count' => 0,
            'estimated_time' => 10,
            'scales' => [],
        ], $this->metadata);
    }
    
    /**
     * Get questions (override in child classes)
     */
    public function getQuestions(): array
    {
        if ($this->questions === null) {
            $this->questions = $this->loadQuestionsFromJson();
        }
        
        return $this->questions;
    }
    
    /**
     * Calculate results (must be implemented)
     */
    abstract public function calculateResults(array $answers): array;
    
    /**
     * Generate interpretation (must be implemented)
     */
    abstract public function generateInterpretation(array $scores): array;
    
    /**
     * Render results (must be implemented)
     */
    abstract public function renderResults(array $results): string;
    
    /**
     * Supports pair mode (override if supported)
     */
    public function supportsPairMode(): bool
    {
        return false;
    }
    
    /**
     * Compare pair results (override if pair mode supported)
     */
    public function comparePairResults(array $results1, array $results2): array
    {
        return [
            'results_1' => $results1,
            'results_2' => $results2,
            'differences' => [],
        ];
    }
    
    /**
     * Get slug from class name
     */
    protected function getSlug(): string
    {
        $className = (new \ReflectionClass($this))->getShortName();
        return strtolower(str_replace('Module', '', $className));
    }
    
    /**
     * Calculate T-scores (standardized scores)
     * 
     * @param float $rawScore Raw score
     * @param float $mean Population mean
     * @param float $stdDev Population standard deviation
     * @return float T-score (mean=50, SD=10)
     */
    protected function calculateTScore(float $rawScore, float $mean, float $stdDev): float
    {
        if ($stdDev == 0) {
            return 50.0;
        }
        
        $zScore = ($rawScore - $mean) / $stdDev;
        $tScore = 50 + ($zScore * 10);
        
        return round($tScore, 1);
    }
    
    /**
     * Normalize score to a range
     */
    protected function normalizeScore(float $score, float $min, float $max): float
    {
        if ($max == $min) {
            return 0;
        }
        
        return ($score - $min) / ($max - $min);
    }
    
    /**
     * Get interpretation level based on score
     */
    protected function getInterpretationLevel(float $score, array $thresholds): string
    {
        foreach ($thresholds as $threshold) {
            if ($score >= $threshold['min'] && $score <= $threshold['max']) {
                return $threshold['level'];
            }
        }
        
        return 'normal';
    }
    
    /**
     * Sanitize answer value
     */
    protected function sanitizeAnswer(mixed $answer): mixed
    {
        if (is_string($answer)) {
            return trim($answer);
        }
        
        return $answer;
    }
    
    /**
     * Validate answers structure
     */
    protected function validateAnswers(array $answers, array $questions): bool
    {
        $questionIds = array_column($questions, 'id');
        
        foreach ($answers as $questionId => $answer) {
            if (!in_array($questionId, $questionIds)) {
                return false;
            }
        }
        
        return true;
    }
}
