<?php

/*
|--------------------------------------------------------------------------
| Evaluation Metrics (Phase 4-A: Deterministic Evaluation Engine)
|--------------------------------------------------------------------------
|
| Each entry declares, per Analysis Template key, which metrics
| EvaluateAnalysisJobAction should deterministically evaluate against a
| statistical/practical baseline — entirely in Laravel, with zero AI
| involvement. See docs/product/EVALUATION_ENGINE.md.
|
| This file is deliberately separate from config/analysis_templates.php:
| analysis_templates.php is AI-facing configuration (its 'fields' /
| 'recommended_derived_metrics' are read into Mapping/Planning AI
| Context), whereas this file is a Laravel-only concern that AI never
| sees. Keeping them apart means there is never any doubt about which
| config a given key is exposed to.
|
| Evaluation deliberately does NOT read config/derived_metrics.php's
| output (CalculateDerivedMetricsAction's "derived_metrics"): that value
| does not retain numerator/denominator counts, is expressed on a 0-100
| scale, and its very existence/name/operator depends on Planning AI's
| non-deterministic judgment. Evaluation instead resolves its own
| numerator/denominator directly from aggregated_metrics, via the
| semantic field names below translated through effective_column_mapping
| (see ResolveEvaluationMetricDefinitionsAction).
|
| 'entity_field' / 'numerator_field' / 'denominator_field' are semantic
| field keys — the same vocabulary as the Template's own 'fields' in
| config/analysis_templates.php — not real CSV column names. Real column
| names are resolved per-AnalysisJob at runtime from
| analysis_job_details.effective_column_mapping.
|
| 'practical_significance_floor' and rate values throughout the
| Evaluation Engine are on a 0-1 internal scale (0.005 = 0.5 percentage
| points), never 0-100. This is a deliberate departure from
| CalculateDerivedMetricsAction's "percentage" operator (which is 0-100)
| precisely because Evaluation never consumes that value — see
| docs/product/EVALUATION_ENGINE.md "Rate Scale".
|
*/

return [

    'ad_performance' => [
        'metrics' => [
            [
                'metric_key' => 'conversion_rate',
                'metric_type' => 'rate',

                'entity_field' => 'channel',

                'numerator_field' => 'conversions',
                'denominator_field' => 'clicks',

                // Lower conversion_rate is the unfavorable direction (a
                // config-side interpretation only — see
                // ResolveEvaluationMetricDefinitionsAction's docblock and
                // EVALUATION_ENGINE.md "unfavorable_direction"; this is
                // never mixed into the computed "direction" fact itself).
                'unfavorable_direction' => 'below',

                // 0-1 scale: 0.005 = 0.5 percentage points.
                'practical_significance_floor' => 0.005,

                // Minimum expected successes/failures in each of the
                // entity/control groups (Cochran's rule of thumb) before
                // the normal approximation to the binomial is trusted.
                'normal_approximation_min_expected' => 5,

                'z_threshold_high' => 1.96,
                'z_threshold_medium' => 1.00,

                'rule_version' => 'evaluation_rule_v1.0',
            ],
        ],
    ],

    // sales_analysis has no metrics defined here — Phase 4-A v1
    // deliberately evaluates only ad_performance.conversion_rate (see
    // docs/product/EVALUATION_ENGINE.md "Phase 4-A Scope"). A Template
    // key absent from this file simply means EvaluateAnalysisJobAction
    // produces zero EvaluationFacts for it — never an error.

];
