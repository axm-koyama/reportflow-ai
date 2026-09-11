<?php

/*
|--------------------------------------------------------------------------
| Priority Rules (Phase 4-C: Deterministic Priority Layer v1.1)
|--------------------------------------------------------------------------
|
| Each entry declares, per Analysis Template key and metric_key, how
| PrioritizeAnalysisJobAction should deterministically score an already
| Priority-eligible EvaluationFact — entirely in Laravel, with zero AI
| involvement. See docs/product/PRIORITY_ENGINE.md.
|
| This file is deliberately separate from config/evaluation_metrics.php
| (what to evaluate, Phase 4-A) and config/diagnosis_categories.php (what a
| controlled Diagnosis AI call may conclude, Phase 4-B) — Priority answers
| a third, independent question: "which already-evaluated problem should a
| human look at first". See PRIORITY_ENGINE.md "Responsibility separation".
|
| 'practical_significance_floor' is deliberately NOT duplicated here — Gap
| Score always resolves it from config/evaluation_metrics.php's matching
| metric definition (the Single Source of Truth Phase 4-A itself
| maintains). See ResolvePriorityRuleAction and
| docs/product/PRIORITY_ENGINE.md "Gap Reference".
|
| 'impact_basis' is currently descriptive only — v1 supports exactly one
| basis, "denominator_share" (see CalculatePriorityAction and
| docs/product/PRIORITY_ENGINE.md "Impact Basis"). It is still declared
| per metric (rather than hardcoded) so that a future basis (e.g.
| "spend_share") can be introduced through config alone.
|
| 'gap_reference_multiple' and 'band_thresholds' are v1's first Golden
| Tuning pass (see docs/product/PRIORITY_ENGINE.md "Band Thresholds — why
| 0.35 / 0.10") — expect these to be revisited once more real-world
| AnalysisJobs exist, always by bumping 'formula_version', never by
| silently mutating v1's meaning for already-persisted rows.
|
*/

return [

    'ad_performance' => [

        'metrics' => [

            'conversion_rate' => [

                'impact_basis' => 'denominator_share',

                // gap_reference_value = practical_significance_floor (from
                // config/evaluation_metrics.php) * this multiple. See
                // docs/product/PRIORITY_ENGINE.md "Gap Reference" for why
                // the floor itself is never reused unscaled.
                'gap_reference_multiple' => 4,

                'band_thresholds' => [
                    'high' => 0.35,
                    'medium' => 0.10,
                ],

                // 'priority_v1' measured Gap against display_baseline_value
                // and self-diluted for a dominant-traffic-share entity; the
                // fix (measuring against test_baseline_value instead — see
                // docs/product/PRIORITY_ENGINE.md "Gap — 定義" / "自己希釈問題")
                // changes the output for the same EvaluationFact, so the
                // version was bumped to 'priority_v1.1' rather than silently
                // reused — see docs/product/PRIORITY_ENGINE.md "formula_version".
                'formula_version' => 'priority_v1.1',
            ],

        ],

    ],

    // sales_analysis has no metrics defined here — it has no
    // config/evaluation_metrics.php entry either, so it never produces an
    // EvaluationFact for Priority to act on in the first place. A Template
    // key (or metric_key) absent from this file simply means
    // PrioritizeAnalysisJobAction produces zero PriorityResults for it —
    // never an error (see ResolvePriorityRuleAction).

];
