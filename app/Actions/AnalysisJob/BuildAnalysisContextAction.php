<?php

declare(strict_types=1);

namespace App\Actions\AnalysisJob;

/**
 * Builds the provider-neutral AI Context passed to AiAnalysisClient.
 *
 * Combines the fixed System Instruction, the user's prompt (kept as-is),
 * the DataProfilingAction output (kept as-is), the MetricAggregationAction
 * output (kept as-is), and the ReportFlow AI Output Schema into a single
 * AI Request Context. See:
 *
 * - docs/product/AI_CONTEXT.md
 * - docs/product/AI_ANALYSIS.md
 * - docs/product/DATA_PROFILING.md
 * - docs/product/METRIC_AGGREGATION.md
 *
 * This is a pure application transformation: it does not read CSVs, call
 * an AI provider, update AnalysisJob status, or touch the database.
 * It does not validate the prompt, the Data Profile, or the Aggregated
 * Metrics (see AI_CONTEXT.md §23/§24 — all are treated as already valid
 * by the time they reach this action).
 */
class BuildAnalysisContextAction
{
    /**
     * Build the AI Context for one analysis request.
     *
     * @param string $prompt
     * @param array<string, mixed> $dataProfile
     * @param array<string, mixed> $aggregatedMetrics the MetricAggregationAction output for the same DataFile
     * @return array<string, mixed>
     */
    public function execute(string $prompt, array $dataProfile, array $aggregatedMetrics): array
    {
        return [
            'system_instruction' => $this->systemInstruction(),
            'user_prompt' => $prompt,
            'data_profile' => $dataProfile,
            'aggregated_metrics' => $aggregatedMetrics,
            'output_schema' => $this->outputSchema(),
        ];
    }

    /**
     * The V1 System Instruction. See docs/product/AI_CONTEXT.md §8.
     *
     * @return string
     */
    private function systemInstruction(): string
    {
        return <<<'TEXT'
            You are a professional business data analyst.

            Analyze the supplied business data profile according to the user's request.

            Rules:

            1. Use only the information provided in the supplied data profile.

            2. Do not invent facts, metrics, trends, causes, relationships, or events
            that are not supported by the supplied data profile.

            3. Clearly distinguish observed facts from interpretations.

            4. If the supplied data is insufficient to answer part of the user's request,
            clearly state that the available context is insufficient.

            5. Do not claim row-level facts that are not represented in the supplied
            sample rows, statistics, or summaries.

            6. Recommendations must be logically connected to observed data.

            7. Prefer concise, decision-oriented business analysis.

            8. Do not assume business context that was not provided by the user or data.

            9. When a requested conclusion cannot be supported by the available data,
            explain what additional data would be required.

            10. Return only the required structured output.

            11. The supplied aggregated_metrics are exact values computed by the
            application from the complete dataset, not an estimate. Treat them as
            ground truth.

            12. Do not recompute, re-derive, or re-estimate sums, counts, or
            averages from sample_rows. sample_rows exist only to help you
            understand the data's shape and meaning, not to calculate figures.

            13. Whenever a number in aggregated_metrics answers a numeric question
            (totals, comparisons, rankings across groups), use that number instead
            of reasoning from sample_rows.
            TEXT;
    }

    /**
     * The provider-neutral Output Schema. See docs/product/AI_CONTEXT.md §19.
     *
     * This describes ReportFlow AI's own Result Schema; it is not an
     * OpenAI/Anthropic-specific JSON Schema. Mapping to a specific
     * provider's structured-output format belongs to AiAnalysisClient (or
     * a future provider layer), not this action.
     *
     * @return array<string, mixed>
     */
    private function outputSchema(): array
    {
        return [
            'summary' => [
                'type' => 'string',
                'required' => true,
            ],

            'highlights' => [
                'type' => 'array',
                'items' => 'string',
            ],

            'metrics' => [
                'type' => 'array',
                'items' => [
                    'label' => 'string',
                    'value' => 'string',
                    'unit' => 'string|null',
                    'change' => 'string|null',
                ],
            ],

            'tables' => [
                'type' => 'array',
                'items' => [
                    'title' => 'string',
                    'columns' => 'string[]',
                    'rows' => 'string[][]',
                ],
            ],

            'insights' => [
                'type' => 'array',
                'items' => [
                    'title' => 'string',
                    'description' => 'string',
                    'evidence' => 'string|null',
                ],
            ],

            'recommendations' => [
                'type' => 'array',
                'items' => [
                    'title' => 'string',
                    'description' => 'string',
                    'priority' => 'high|medium|low|null',
                ],
            ],
        ];
    }
}
