<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Diagnosis;

use App\Actions\Diagnosis\BuildDiagnosisEvidencePackageAction;
use App\Models\EvaluationFact;
use Tests\TestCase;

/**
 * Direct coverage for the Evidence Package builder / Evidence Gate
 * (Phase 4-B v1). See docs/product/DIAGNOSIS_ENGINE.md "Evidence
 * Package" / "Evidence Gate".
 *
 * Zero AI I/O, zero DB I/O — everything is a plain, unsaved
 * EvaluationFact instance plus in-memory arrays, using the real
 * config/analysis_templates.php / config/evaluation_metrics.php /
 * config/diagnosis_categories.php shipped with the app (not stubs) so
 * this test exercises the actual Evidence Gate thresholds.
 */
class BuildDiagnosisEvidencePackageActionTest extends TestCase
{
    private function action(): BuildDiagnosisEvidencePackageAction
    {
        return new BuildDiagnosisEvidencePackageAction;
    }

    private function fact(array $overrides = []): EvaluationFact
    {
        $fact = new EvaluationFact(array_merge([
            'entity_type' => 'channel',
            'entity_key' => 'Social',
            'metric_key' => 'conversion_rate',
            'metric_value' => 0.04,
            'display_baseline_value' => 0.054,
            'test_baseline_value' => 0.061,
            'delta_absolute' => -0.014,
            'delta_percent' => -0.259259,
            'direction' => 'below',
            'evaluation_level' => 'high',
            'numerator_value' => 200,
            'denominator_value' => 5000,
            'control_numerator_value' => 610,
            'control_denominator_value' => 10000,
            'z_score' => -5.36,
        ], $overrides));

        // Not fillable (primary key) — set directly, matching how a
        // real EvaluationFact loaded from the database would carry it.
        $fact->evaluation_fact_id = $overrides['evaluation_fact_id'] ?? 123;

        return $fact;
    }

    private function effectiveColumnMapping(): array
    {
        return [
            'channel' => ['column' => 'channel', 'status' => 'mapped', 'source' => 'ai'],
            'spend' => ['column' => 'spend', 'status' => 'mapped', 'source' => 'ai'],
            'revenue' => ['column' => 'revenue', 'status' => 'mapped', 'source' => 'ai'],
            'conversions' => ['column' => 'conversions', 'status' => 'mapped', 'source' => 'ai'],
            'clicks' => ['column' => 'clicks', 'status' => 'mapped', 'source' => 'ai'],
            'impressions' => ['column' => 'impressions', 'status' => 'mapped', 'source' => 'ai'],
        ];
    }

    private function aggregatedMetrics(array $socialMetrics): array
    {
        return [
            'dimensions' => [
                [
                    'dimension' => 'channel',
                    'group_count' => 1,
                    'groups' => [
                        ['value' => 'Social', 'count' => 100, 'metrics' => $socialMetrics],
                    ],
                ],
            ],
            'measures' => ['spend', 'revenue', 'conversions', 'clicks', 'impressions'],
        ];
    }

    /**
     * evidence_fact_id set as evaluation_fact_id (evaluation_fact_id 123)
     * would fail EvaluationFact::create casting rules if run through the
     * database, but this action never persists — plain attribute access.
     */
    public function test_trigger_fact_is_a_verbatim_snapshot_of_the_evaluation_fact(): void
    {
        $result = $this->action()->execute(
            $this->fact(),
            $this->aggregatedMetrics(['clicks' => ['sum' => 5000, 'count' => 100]]),
            $this->effectiveColumnMapping(),
            'ad_performance',
        );

        $this->assertSame([
            'evidence_id' => 'trigger:evaluation_fact:123',
            'evaluation_fact_id' => 123,
            'entity_type' => 'channel',
            'entity_key' => 'Social',
            'metric_key' => 'conversion_rate',
            'metric_value' => 0.04,
            'display_baseline_value' => 0.054,
            'test_baseline_value' => 0.061,
            'delta_absolute' => -0.014,
            'delta_percent' => -0.259259,
            'direction' => 'below',
            'evaluation_level' => 'high',
            'numerator_value' => 200,
            'denominator_value' => 5000,
            'control_numerator_value' => 610,
            'control_denominator_value' => 10000,
            'z_score' => -5.36,
        ], $result['trigger_fact']);
    }

    /**
     * spend/revenue/impressions are included; conversions/clicks are
     * excluded (already represented as numerator_value/denominator_value
     * in trigger_fact); channel/campaign (dimensions) and date (temporal)
     * are never measures at all.
     */
    public function test_supporting_facts_exclude_the_metrics_own_numerator_and_denominator(): void
    {
        $result = $this->action()->execute(
            $this->fact(),
            $this->aggregatedMetrics([
                'spend' => ['sum' => 150000, 'count' => 100],
                'revenue' => ['sum' => 420000, 'count' => 100],
                'conversions' => ['sum' => 200, 'count' => 100],
                'clicks' => ['sum' => 5000, 'count' => 100],
                'impressions' => ['sum' => 90000, 'count' => 100],
            ]),
            $this->effectiveColumnMapping(),
            'ad_performance',
        );

        $this->assertSame([
            ['evidence_id' => 'supporting:spend', 'metric_key' => 'spend', 'value' => 150000],
            ['evidence_id' => 'supporting:revenue', 'metric_key' => 'revenue', 'value' => 420000],
            ['evidence_id' => 'supporting:impressions', 'metric_key' => 'impressions', 'value' => 90000],
        ], $result['supporting_facts']);
    }

    public function test_supporting_facts_skip_a_measure_dropped_from_aggregated_metrics(): void
    {
        $result = $this->action()->execute(
            $this->fact(),
            $this->aggregatedMetrics([
                'spend' => ['sum' => 150000, 'count' => 100],
                // 'revenue' deliberately absent — as if MetricAggregationAction's
                // max_measures dropped it.
            ]),
            $this->effectiveColumnMapping(),
            'ad_performance',
        );

        $this->assertSame([
            ['evidence_id' => 'supporting:spend', 'metric_key' => 'spend', 'value' => 150000],
        ], $result['supporting_facts']);
    }

    public function test_supporting_facts_skip_an_unmapped_measure(): void
    {
        $mapping = $this->effectiveColumnMapping();
        $mapping['revenue'] = ['column' => null, 'status' => 'unmapped'];

        $result = $this->action()->execute(
            $this->fact(),
            $this->aggregatedMetrics([
                'spend' => ['sum' => 150000, 'count' => 100],
                'revenue' => ['sum' => 420000, 'count' => 100],
            ]),
            $mapping,
            'ad_performance',
        );

        $this->assertSame([
            ['evidence_id' => 'supporting:spend', 'metric_key' => 'spend', 'value' => 150000],
        ], $result['supporting_facts']);
    }

    public function test_supporting_facts_are_empty_when_the_entity_group_cannot_be_found(): void
    {
        $result = $this->action()->execute(
            $this->fact(['entity_key' => 'Unknown Channel']),
            $this->aggregatedMetrics(['spend' => ['sum' => 150000, 'count' => 100]]),
            $this->effectiveColumnMapping(),
            'ad_performance',
        );

        $this->assertSame([], $result['supporting_facts']);
    }

    /**
     * insufficient_explanatory_evidence is always allowed, regardless of
     * evidence (Abstention — see docs/product/DIAGNOSIS_ENGINE.md).
     */
    public function test_abstention_category_is_always_allowed(): void
    {
        $result = $this->action()->execute(
            $this->fact(['numerator_value' => 200]), // not a zero-conversion pattern
            $this->aggregatedMetrics([]),
            $this->effectiveColumnMapping(),
            'ad_performance',
        );

        $this->assertSame(['insufficient_explanatory_evidence'], $result['allowed_categories']);
    }

    /**
     * measurement_consistency_risk's required evidence (Phase 4-B v1):
     * numerator_value === 0 AND denominator_value >= the configured
     * threshold (config/diagnosis_categories.php,
     * 'minimum_denominator_for_zero_conversion_risk', default 5).
     */
    public function test_measurement_consistency_risk_is_allowed_when_zero_conversions_with_enough_traffic(): void
    {
        $result = $this->action()->execute(
            $this->fact(['numerator_value' => 0, 'denominator_value' => 1000]),
            $this->aggregatedMetrics([]),
            $this->effectiveColumnMapping(),
            'ad_performance',
        );

        $this->assertSame(
            ['measurement_consistency_risk', 'insufficient_explanatory_evidence'],
            $result['allowed_categories'],
        );
    }

    public function test_measurement_consistency_risk_is_not_allowed_when_denominator_is_below_the_threshold(): void
    {
        $threshold = (int) config('diagnosis_categories.thresholds.minimum_denominator_for_zero_conversion_risk');

        $result = $this->action()->execute(
            $this->fact(['numerator_value' => 0, 'denominator_value' => $threshold - 1]),
            $this->aggregatedMetrics([]),
            $this->effectiveColumnMapping(),
            'ad_performance',
        );

        $this->assertSame(['insufficient_explanatory_evidence'], $result['allowed_categories']);
    }

    public function test_measurement_consistency_risk_is_not_allowed_when_conversions_are_nonzero(): void
    {
        $result = $this->action()->execute(
            $this->fact(['numerator_value' => 1, 'denominator_value' => 1000]),
            $this->aggregatedMetrics([]),
            $this->effectiveColumnMapping(),
            'ad_performance',
        );

        $this->assertSame(['insufficient_explanatory_evidence'], $result['allowed_categories']);
    }

    public function test_measurement_consistency_risk_is_not_allowed_for_a_metric_it_is_not_applicable_to(): void
    {
        $result = $this->action()->execute(
            $this->fact(['metric_key' => 'some_other_rate', 'numerator_value' => 0, 'denominator_value' => 1000]),
            $this->aggregatedMetrics([]),
            $this->effectiveColumnMapping(),
            'ad_performance',
        );

        $this->assertSame(['insufficient_explanatory_evidence'], $result['allowed_categories']);
    }
}
