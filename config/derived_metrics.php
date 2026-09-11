<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Max Derived Metrics
    |--------------------------------------------------------------------------
    |
    | Maximum number of CalculationDefinition entries accepted from a single
    | Metric Planning AI response. This is enforced defensively in
    | CalculateDerivedMetricsAction regardless of what PlanDerivedMetricsAction's
    | System Instruction asks the AI to do — the AI-facing instruction is not
    | a substitute for this application-side limit.
    |
    | Any proposed definition beyond this limit (by position in the AI's
    | response) is rejected with reason "limit_exceeded" rather than raising
    | an exception, consistent with MetricAggregationAction's "safely narrow,
    | never throw" limit-handling philosophy (see
    | docs/product/METRIC_AGGREGATION.md).
    |
    | 5 matches PlanDerivedMetricsAction's own System Instruction ("propose
    | at most 5"), so under normal operation this limit is a safety net
    | against a misbehaving or non-compliant AI response rather than the
    | primary control.
    |
    */

    'max_derived_metrics' => 5,

];
