<?php

/*
|--------------------------------------------------------------------------
| Diagnosis Categories (Phase 4-B: Controlled Diagnosis v1)
|--------------------------------------------------------------------------
|
| Declares the fixed, small set of category_key values Diagnosis AI is
| ever allowed to choose from, plus the deterministic thresholds
| BuildDiagnosisEvidencePackageAction uses to decide — per EvaluationFact,
| in Laravel, before any AI call — which of these categories currently
| have supporting evidence at all (Evidence-gated Category Set). See
| docs/product/DIAGNOSIS_ENGINE.md.
|
| This file is deliberately separate from config/evaluation_metrics.php:
| evaluation_metrics.php declares *what to evaluate* (Phase 4-A, zero AI),
| this file declares *what a controlled Diagnosis AI call is allowed to
| conclude* (Phase 4-B, one AI call per eligible EvaluationFact).
|
| Phase 4-B v1 deliberately keeps this catalog to two entries only —
| adding a "symptom" category (e.g. "conversion_efficiency_issue") that
| merely restates the EvaluationFact's own unfavorable evaluation would
| carry zero diagnostic value (its required evidence would just be
| Diagnosis Eligibility itself). "insufficient_explanatory_evidence" is
| the only category guaranteed to have supporting evidence — it is a
| normal, correct Diagnosis Result, not a failure — so it is always
| included in allowed_categories.
|
| "applicable_metrics" is consulted by
| BuildDiagnosisEvidencePackageAction as one of the Evidence Gate
| conditions for that category — never trusted, or shown, to the AI on
| its own (allowed_categories, the *result* of gating, is what the AI
| actually sees).
|
*/

return [

    'categories' => [

        'measurement_consistency_risk' => [
            'label' => 'Measurement Consistency Risk',

            // Deliberately hedged: this category means "a deterministic
            // pattern exists that makes measurement/tracking consistency
            // worth verifying" — never "tracking is confirmed broken", and
            // never "more likely than a genuine zero-conversion outcome".
            // A real, non-tracking-related zero-conversion result remains
            // an equally possible explanation for the same pattern. See
            // docs/product/DIAGNOSIS_ENGINE.md "measurement_consistency_risk".
            'description' => 'A deterministic pattern in the supplied facts (zero conversions despite non-trivial traffic) makes measurement or tracking consistency one possible area worth verifying. It does not establish a measurement or tracking problem — a genuine zero-conversion outcome remains an equally possible explanation.',

            'applicable_metrics' => ['conversion_rate'],
        ],

        'insufficient_explanatory_evidence' => [
            'label' => 'Insufficient Explanatory Evidence',
            'description' => 'The currently available evidence cannot distinguish between possible causes for this observation. This is a normal, correct Diagnosis Result, not a failure.',

            // Empty = no metric restriction; always eligible whenever
            // Diagnosis itself runs (see BuildDiagnosisEvidencePackageAction).
            'applicable_metrics' => [],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Thresholds
    |--------------------------------------------------------------------------
    |
    | Deliberately independent from config/evaluation_metrics.php's
    | "normal_approximation_min_expected" — that threshold governs whether
    | Phase 4-A trusts the normal approximation for a two-proportion
    | z-test; this one governs a completely different question (whether a
    | zero-conversion entity has enough traffic for the zero itself to be
    | worth flagging as a measurement_consistency_risk candidate). Reusing
    | the same value would be a coincidence, not a shared concept.
    |
    */

    'thresholds' => [

        // trigger_fact.denominator_value (e.g. clicks) must be at least
        // this large, in addition to trigger_fact.numerator_value being
        // exactly 0, before measurement_consistency_risk is offered to
        // the AI. See docs/product/DIAGNOSIS_ENGINE.md
        // "measurement_consistency_risk required evidence".
        'minimum_denominator_for_zero_conversion_risk' => 5,

    ],

];
