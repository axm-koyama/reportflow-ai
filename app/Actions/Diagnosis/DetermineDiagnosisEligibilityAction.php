<?php

declare(strict_types=1);

namespace App\Actions\Diagnosis;

use App\Models\EvaluationFact;

/**
 * Decides whether one EvaluationFact is a Diagnosis candidate at all —
 * before any Evidence Package is built and before any AI call is even
 * considered. See docs/product/DIAGNOSIS_ENGINE.md "Diagnosis
 * Eligibility".
 *
 * Eligibility:
 *
 *   evaluation_level ∈ {high, medium}      (whitelist — see below)
 *   AND
 *   direction == config('evaluation_metrics.{template}.metrics[].unfavorable_direction')
 *
 * A whitelist on evaluation_level, not a blacklist: only "high" and
 * "medium" are ever eligible. "low", "insufficient_data", and any future
 * evaluation_level value this class does not yet know about are all
 * rejected the same way — Diagnosis eligibility never silently expands
 * just because EvaluateAnalysisJobAction starts producing a new level
 * value.
 *
 * "favorable" anomalies (evaluation_level high/medium but direction is
 * the *favorable* one) are out of Phase 4-B v1 scope by construction:
 * they simply fail the direction check below. See
 * docs/product/DIAGNOSIS_ENGINE.md "favorable anomaly".
 *
 * Zero AI I/O, zero DB I/O (aside from reading the already-loaded
 * EvaluationFact passed in) — pure config lookup + comparison.
 */
class DetermineDiagnosisEligibilityAction
{
    /**
     * @var list<string>
     */
    private const array ELIGIBLE_EVALUATION_LEVELS = ['high', 'medium'];

    /**
     * @param  string  $templateKey  the AnalysisJob's template_key (Diagnosis
     *                               never runs for Free Analysis, so this is never null here —
     *                               the caller is responsible for that guard)
     */
    public function execute(EvaluationFact $fact, string $templateKey): bool
    {
        if (! in_array($fact->evaluation_level, self::ELIGIBLE_EVALUATION_LEVELS, true)) {
            return false;
        }

        $unfavorableDirection = $this->unfavorableDirectionFor($templateKey, $fact->metric_key);

        if ($unfavorableDirection === null) {
            return false;
        }

        return $fact->direction === $unfavorableDirection;
    }

    /**
     * Look up this metric's configured unfavorable_direction directly from
     * config/evaluation_metrics.php — the same Single Source of Truth
     * Phase 4-A itself uses (see ResolveEvaluationMetricDefinitionsAction).
     * No new config key is introduced for Phase 4-B.
     */
    private function unfavorableDirectionFor(string $templateKey, string $metricKey): ?string
    {
        $metrics = config("evaluation_metrics.{$templateKey}.metrics", []);

        if (! is_array($metrics)) {
            return null;
        }

        foreach ($metrics as $metric) {
            if (! is_array($metric) || ($metric['metric_key'] ?? null) !== $metricKey) {
                continue;
            }

            $unfavorableDirection = $metric['unfavorable_direction'] ?? null;

            return is_string($unfavorableDirection) ? $unfavorableDirection : null;
        }

        return null;
    }
}
