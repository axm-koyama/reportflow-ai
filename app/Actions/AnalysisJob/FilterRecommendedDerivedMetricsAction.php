<?php

declare(strict_types=1);

namespace App\Actions\AnalysisJob;

/**
 * Keeps only the recommended_derived_metrics hints whose left_field and
 * right_field both resolved to a usable real column, per
 * "Recommended Derived Metrics Filtering" in
 * docs/product/ANALYSIS_TEMPLATE_MODULE.md.
 *
 * This is deterministic — never delegated to AI judgment — because
 * Planning AI has been observed silently substituting an unmapped hint's
 * missing operand with an unrelated mapped measure while keeping the
 * hint's original semantic "name" (e.g. proposing clicks / spend under
 * the name "click_through_rate" when "impressions" was unmapped),
 * producing a numerically valid but business-meaningless metric.
 *
 * This filtering only narrows which hints are offered — it never
 * restricts what Planning AI may propose on its own from
 * available_measures (see PlanDerivedMetricsAction's System Instruction
 * Rule 9); it is a hint filter, not a whitelist enforced on the final
 * Metric Plan.
 *
 * Extracted (Phase 3-C) from ResolveAnalysisTemplateAction (which still
 * uses it internally, unchanged, for the auto-confident path) so it can
 * also be re-applied by ExecuteAnalysisJobAction against the confirmed
 * Effective Mapping once a Mapping Preview / manual override has
 * happened — see docs/product/MAPPING_CONTROL.md. The Effective Mapping
 * a manual-confirmation path produces has the exact same
 * {column, status} shape ValidateColumnMappingAction already produces,
 * so this Action does not need to know or care which source (AI-only or
 * AI+manual) the mapping it is given came from.
 *
 * Mapping resolution and Derived Metric hint filtering are deliberately
 * two separate responsibilities: this Action never resolves a mapping
 * itself (see ResolveEffectiveColumnMappingAction), and never calls the
 * AI or touches the database.
 */
class FilterRecommendedDerivedMetricsAction
{
    /**
     * @param list<array<string, mixed>> $recommendations the Template's own "recommended_derived_metrics"
     * @param array<string, array{column: string|null, status: string}> $mapping a validated AI mapping or a confirmed Effective Mapping — both share the same {column, status} shape
     * @return list<array<string, mixed>>
     */
    public function execute(array $recommendations, array $mapping): array
    {
        return array_values(array_filter(
            $recommendations,
            fn (array $recommendation): bool => $this->fieldIsUsable($recommendation['left_field'] ?? null, $mapping)
                && $this->fieldIsUsable($recommendation['right_field'] ?? null, $mapping),
        ));
    }

    /**
     * @param array<string, array{column: string|null, status: string}> $mapping
     */
    private function fieldIsUsable(mixed $field, array $mapping): bool
    {
        if (! is_string($field) || ! array_key_exists($field, $mapping)) {
            return false;
        }

        $entry = $mapping[$field];

        return $entry['status'] === 'mapped' && $entry['column'] !== null;
    }
}
