<?php

declare(strict_types=1);

namespace App\Actions\AnalysisJob;

/**
 * Builds the provider-neutral AI Context passed to AiAnalysisClient::analyze().
 *
 * Combines the fixed System Instruction, the user's prompt (kept as-is),
 * the DataProfilingAction output (kept as-is), the MetricAggregationAction
 * output (kept as-is), the CalculateDerivedMetricsAction output (kept
 * as-is), the resolved Analysis Template / column mapping (kept as-is,
 * when an Analysis Template was used), and the ReportFlow AI Output
 * Schema into a single AI Request Context. See:
 *
 * - docs/product/AI_CONTEXT.md
 * - docs/product/AI_ANALYSIS.md
 * - docs/product/DATA_PROFILING.md
 * - docs/product/METRIC_AGGREGATION.md
 * - docs/product/DERIVED_METRICS.md
 * - docs/product/ANALYSIS_TEMPLATE_MODULE.md
 *
 * This action builds the Context for the *final analysis* AI call only.
 * The separate, earlier Metric Planning AI call has its own, much smaller
 * context built by PlanDerivedMetricsAction, and the separate, earlier
 * Column Mapping AI call has its own context built by
 * MapAnalysisTemplateColumnsAction — this action is never involved in
 * either.
 *
 * analysis_template and column_mapping are passed as their own structured
 * context keys, never embedded into user_prompt: user_prompt always
 * remains exactly what the user typed (see AI_CONTEXT.md §5.1 "Prompt
 * rewriteを行わない"), and column_mapping is a Laravel-validated Fact, not
 * prose for the AI to parse (see docs/product/ANALYSIS_TEMPLATE_MODULE.md
 * "structured context設計").
 *
 * This is a pure application transformation: it does not read CSVs, call
 * an AI provider, update AnalysisJob status, or touch the database.
 * It does not validate the prompt, the Data Profile, the Aggregated
 * Metrics, the Derived Metrics, the Analysis Template, or the Column
 * Mapping (see AI_CONTEXT.md §23/§24 — all are treated as already valid
 * by the time they reach this action).
 *
 * Phase 4-B addition: execute()'s $decisionEnabled flag appends a
 * descriptive-analysis-only instruction block (Rules 23-28) for
 * AnalysisJobs whose template_key has a config/evaluation_metrics.php
 * entry — see docs/product/DIAGNOSIS_ENGINE.md "Final Analyze最終責務".
 * This action still never reads EvaluationFact or DiagnosisResult data
 * itself; ExecuteAnalysisJobAction computes $decisionEnabled from
 * template_key alone before calling execute().
 */
class BuildAnalysisContextAction
{
    /**
     * Build the AI Context for one final analysis request.
     *
     * @param string $prompt
     * @param array<string, mixed> $dataProfile
     * @param array<string, mixed> $aggregatedMetrics the MetricAggregationAction output for the same DataFile
     * @param array<string, mixed> $derivedMetrics the CalculateDerivedMetricsAction output for the same AnalysisJob
     * @param array{name: string, instruction: string, recommended_derived_metrics: list<array<string, mixed>>}|null $analysisTemplate the Template resolved by ResolveAnalysisTemplateAction, or null for free-form analysis
     * @param array<string, string> $columnMapping semantic field => real column name, resolved by ResolveAnalysisTemplateAction ([] for free-form analysis)
     * @param bool $decisionEnabled Phase 4-B: true when this AnalysisJob's
     *        template_key has a config/evaluation_metrics.php entry (see
     *        docs/product/DIAGNOSIS_ENGINE.md "Decision-enabled Analysis
     *        の定義") — appends the Decision-enabled instruction block
     *        (Rules 23-28) that restricts Final Analyze to descriptive
     *        analysis only. Always false for Free Analysis and for a
     *        Template without an evaluation_metrics.php entry (e.g.
     *        sales_analysis today), leaving their System Instruction
     *        byte-for-byte unchanged from before Phase 4-B.
     * @return array<string, mixed>
     */
    public function execute(
        string $prompt,
        array $dataProfile,
        array $aggregatedMetrics,
        array $derivedMetrics,
        ?array $analysisTemplate = null,
        array $columnMapping = [],
        bool $decisionEnabled = false,
    ): array {
        return [
            'system_instruction' => $this->systemInstruction($decisionEnabled),
            'user_prompt' => $prompt,
            'data_profile' => $dataProfile,
            'aggregated_metrics' => $aggregatedMetrics,
            'derived_metrics' => $derivedMetrics,
            'analysis_template' => $analysisTemplate,
            'column_mapping' => $columnMapping,
            'output_schema' => $this->outputSchema(),
        ];
    }

    /**
     * The V1 System Instruction. See docs/product/AI_CONTEXT.md §8.
     *
     * When $decisionEnabled is true (Phase 4-B, see execute()'s
     * docblock), Rules 23-28 are appended verbatim after Rule 22 — Rules
     * 1-22 are never edited or reordered for this case, only added to.
     * This keeps a Decision-enabled AnalysisJob's System Instruction a
     * strict superset of the pre-Phase-4-B text, so every existing
     * assertion against Rules 1-22's exact wording
     * (BuildAnalysisContextActionTest) stays valid unchanged. See
     * docs/product/DIAGNOSIS_ENGINE.md "Final Analyze System Instruction
     * 変更案".
     *
     * @return string
     */
    private function systemInstruction(bool $decisionEnabled): string
    {
        $instruction = <<<'TEXT'
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

            14. The supplied derived_metrics (e.g. ratios such as ROAS) are exact
            values computed by the application from aggregated_metrics, not an
            estimate. Treat them as ground truth, exactly like aggregated_metrics.

            15. Do not recompute a derived metric yourself. If derived_metrics
            already contains a value that answers the user's question, use that
            value instead of dividing, multiplying, adding, or subtracting
            aggregated_metrics figures on your own.

            16. When derived_metrics contains a metric relevant to the user's
            request, prefer it over any equivalent figure you could compute
            yourself from aggregated_metrics or sample_rows.

            17. Never derive a ratio, percentage, or other calculated figure from
            sample_rows under any circumstances.

            18. A derived_metrics group entry with "result": null means that value
            could not be computed (for example, division by zero or missing data)
            — state this as insufficient data rather than guessing a number.

            19. When analysis_template is not null, it describes the business
            analysis purpose the user selected. Treat its "instruction" as the
            primary analysis objective, alongside user_prompt.

            20. column_mapping (semantic field name -> real column name) is a Fact
            already validated by the application, exactly like aggregated_metrics.
            Do not second-guess it, and do not assume a semantic field exists if it
            is not a key in column_mapping.

            21. When describing a semantic field from analysis_template or
            column_mapping in your response, prefer a business-friendly term (for
            example the field's own name, such as "channel") over the raw CSV
            column name, so the result reads naturally for a business user.

            22. Do not assume a semantic field is present in the data just because
            analysis_template mentions it. Only fields that are keys in
            column_mapping were actually found in this dataset.
            TEXT;

        if (! $decisionEnabled) {
            return $instruction;
        }

        return $instruction."\n\n".<<<'TEXT'
            23. This analysis is Decision-enabled. A separate deterministic
            Evaluation layer and a dedicated Diagnosis layer handle
            evaluation, diagnosis, priority, and action responsibilities for
            this data.

            24. Do not diagnose causes. Do not state or imply why a metric
            moved.

            25. Do not assign priority such as high, medium, or low.

            26. Do not recommend operational actions, including:
            - budget increase or decrease
            - targeting changes
            - creative changes
            - bid changes
            - landing-page changes

            27. "recommendations" must be an empty array. Express any
            noteworthy observation as a descriptive insight instead.

            28. Restrict your output to descriptive analysis: what happened,
            not why it happened, and not what should be done.
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
