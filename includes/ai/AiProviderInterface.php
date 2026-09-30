<?php
/**
 * PEPP Learning ERP — AI Provider Interface
 *
 * Pluggable abstraction for AI analysis services.
 * Ensures AI providers remain modular, testable, and secure.
 */

declare(strict_types=1);

interface AiProviderInterface {
    /**
     * Checks if the AI provider has valid API credentials configured.
     */
    public function isConfigured(): bool;

    /**
     * Returns the provider identifier (e.g. 'gemini', 'mock').
     */
    public function getProviderName(): string;

    /**
     * Returns the model identifier (e.g. 'gemini-1.5-flash', 'mock-v1').
     */
    public function getModelName(): string;

    /**
     * Analyzes structured quality assessment metrics and row items.
     *
     * @param array $summaryMetrics Deterministically calculated metrics (counts, durations, charge).
     * @param array $assessmentRows Normalized assessment rows.
     * @return array Structured analysis result matching the standardized AI report schema.
     * @throws Exception If generation fails or returns invalid/unparseable data.
     */
    public function analyzeAssessment(array $summaryMetrics, array $assessmentRows): array;
}
