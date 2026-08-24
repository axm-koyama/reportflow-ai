<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Actions\Evaluation\EvaluateAnalysisJobAction;
use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use App\Models\EvaluationFact;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Direct coverage for EvaluateAnalysisJobAction's persistence /
 * idempotency contract. See docs/product/EVALUATION_ENGINE.md
 * "Persistence" / "Idempotency" / "Rule Version".
 *
 * Zero AI I/O by construction: EvaluateAnalysisJobAction's constructor
 * depends only on ResolveEvaluationMetricDefinitionsAction and
 * EvaluateRateMetricAction — AiAnalysisClient is never injected, so
 * there is no AI call to make in the first place (see the class
 * docblock).
 */
class EvaluateAnalysisJobActionTest extends TestCase
{
    use RefreshDatabase;

    private function action(): EvaluateAnalysisJobAction
    {
        return app(EvaluateAnalysisJobAction::class);
    }

    private function effectiveMapping(): array
    {
        return [
            'channel' => ['column' => 'channel', 'status' => 'mapped', 'source' => 'ai'],
            'conversions' => ['column' => 'conversions', 'status' => 'mapped', 'source' => 'ai'],
            'clicks' => ['column' => 'clicks', 'status' => 'mapped', 'source' => 'ai'],
        ];
    }

    /**
     * @param array<string, array<string, int>> $groups value => {measureName: sum}
     */
    private function aggregatedMetrics(array $groups): array
    {
        $groupEntries = [];

        foreach ($groups as $value => $metrics) {
            $metricEntries = [];

            foreach ($metrics as $measureName => $sum) {
                $metricEntries[$measureName] = ['sum' => $sum, 'count' => 1, 'avg' => $sum];
            }

            $groupEntries[] = ['value' => (string) $value, 'count' => 1, 'metrics' => $metricEntries];
        }

        return [
            'dimensions' => [
                ['dimension' => 'channel', 'group_count' => count($groupEntries), 'groups' => $groupEntries],
            ],
            'measures' => ['conversions', 'clicks'],
        ];
    }

    private function twoChannelAggregatedMetrics(): array
    {
        return $this->aggregatedMetrics([
            'Social' => ['conversions' => 200, 'clicks' => 5000],
            'Email' => ['conversions' => 243, 'clicks' => 3000],
        ]);
    }

    public function test_it_persists_evaluation_facts_for_ad_performance(): void
    {
        $analysisJob = AnalysisJob::factory()->create(['template_key' => 'ad_performance']);
        AnalysisJobDetail::factory()->for($analysisJob)->create([
            'effective_column_mapping' => $this->effectiveMapping(),
        ]);

        $count = $this->action()->execute($analysisJob, $this->twoChannelAggregatedMetrics());

        $this->assertSame(2, $count);
        $this->assertSame(2, EvaluationFact::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->count());

        $social = EvaluationFact::query()
            ->where('analysis_job_id', $analysisJob->analysis_job_id)
            ->where('entity_key', 'Social')
            ->firstOrFail();

        $this->assertSame('channel', $social->entity_type);
        $this->assertSame('conversion_rate', $social->metric_key);
        $this->assertSame('rate', $social->metric_type);
        $this->assertEqualsWithDelta(0.04, $social->metric_value, 1e-9);
        $this->assertSame('evaluation_rule_v1.0', $social->rule_version);
        $this->assertNotNull($social->computed_at);
    }

    public function test_delete_and_recreate_idempotency_on_a_second_execution(): void
    {
        $analysisJob = AnalysisJob::factory()->create(['template_key' => 'ad_performance']);
        AnalysisJobDetail::factory()->for($analysisJob)->create([
            'effective_column_mapping' => $this->effectiveMapping(),
        ]);

        $this->action()->execute($analysisJob, $this->twoChannelAggregatedMetrics());
        $this->assertSame(2, EvaluationFact::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->count());

        // A retry with a *different* set of entities (e.g. re-aggregated
        // after a Queue retry) must fully replace the previous rows, not
        // accumulate alongside them.
        $secondRun = $this->aggregatedMetrics([
            'Social' => ['conversions' => 200, 'clicks' => 5000],
            'Email' => ['conversions' => 243, 'clicks' => 3000],
            'Paid' => ['conversions' => 220, 'clicks' => 4000],
        ]);

        $count = $this->action()->execute($analysisJob, $secondRun);

        $this->assertSame(3, $count);
        $this->assertSame(3, EvaluationFact::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->count());
        $this->assertTrue(
            EvaluationFact::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->where('entity_key', 'Paid')->exists(),
        );
    }

    public function test_clear_for_analysis_job_deletes_only_this_jobs_facts(): void
    {
        $analysisJob = AnalysisJob::factory()->create(['template_key' => 'ad_performance']);
        AnalysisJobDetail::factory()->for($analysisJob)->create([
            'effective_column_mapping' => $this->effectiveMapping(),
        ]);
        $this->action()->execute($analysisJob, $this->twoChannelAggregatedMetrics());
        $this->assertSame(2, EvaluationFact::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->count());

        $otherAnalysisJob = AnalysisJob::factory()->create(['template_key' => 'ad_performance']);
        AnalysisJobDetail::factory()->for($otherAnalysisJob)->create([
            'effective_column_mapping' => $this->effectiveMapping(),
        ]);
        $this->action()->execute($otherAnalysisJob, $this->twoChannelAggregatedMetrics());
        $this->assertSame(2, EvaluationFact::query()->where('analysis_job_id', $otherAnalysisJob->analysis_job_id)->count());

        $this->action()->clearForAnalysisJob($analysisJob);

        $this->assertSame(0, EvaluationFact::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->count());
        // A sibling AnalysisJob's facts are never touched.
        $this->assertSame(2, EvaluationFact::query()->where('analysis_job_id', $otherAnalysisJob->analysis_job_id)->count());
    }

    public function test_clear_for_analysis_job_is_a_no_op_when_none_exist(): void
    {
        $analysisJob = AnalysisJob::factory()->create(['template_key' => 'ad_performance']);

        $this->action()->clearForAnalysisJob($analysisJob);

        $this->assertSame(0, EvaluationFact::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->count());
    }

    public function test_unique_constraint_rejects_a_duplicate_row(): void
    {
        $analysisJob = AnalysisJob::factory()->create(['template_key' => 'ad_performance']);

        $attributes = [
            'analysis_job_id' => $analysisJob->analysis_job_id,
            'entity_type' => 'channel',
            'entity_key' => 'Social',
            'metric_key' => 'conversion_rate',
            'metric_type' => 'rate',
            'evaluation_level' => 'high',
            'rule_version' => 'evaluation_rule_v1.0',
            'computed_at' => now(),
        ];

        EvaluationFact::query()->create($attributes);

        $this->expectException(QueryException::class);

        EvaluationFact::query()->create($attributes);
    }

    public function test_analysis_job_relation_resolves_its_evaluation_facts(): void
    {
        $analysisJob = AnalysisJob::factory()->create(['template_key' => 'ad_performance']);
        AnalysisJobDetail::factory()->for($analysisJob)->create([
            'effective_column_mapping' => $this->effectiveMapping(),
        ]);

        $this->action()->execute($analysisJob, $this->twoChannelAggregatedMetrics());

        $this->assertCount(2, $analysisJob->fresh()->evaluationFacts);
        $fact = EvaluationFact::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->firstOrFail();
        $this->assertSame($analysisJob->analysis_job_id, $fact->analysisJob->analysis_job_id);
    }

    public function test_uses_effective_mapping_only_and_no_ops_when_it_is_not_yet_confirmed(): void
    {
        $analysisJob = AnalysisJob::factory()->create(['template_key' => 'ad_performance']);
        AnalysisJobDetail::factory()->for($analysisJob)->create([
            // column_mapping present (AI already ran) but no
            // effective_column_mapping yet (e.g. still
            // AwaitingMappingConfirmation) — must not evaluate.
            'column_mapping' => $this->effectiveMapping(),
            'effective_column_mapping' => null,
        ]);

        $count = $this->action()->execute($analysisJob, $this->twoChannelAggregatedMetrics());

        $this->assertSame(0, $count);
        $this->assertSame(0, EvaluationFact::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->count());
    }

    public function test_free_analysis_is_a_no_op(): void
    {
        $analysisJob = AnalysisJob::factory()->create(['template_key' => null]);
        AnalysisJobDetail::factory()->for($analysisJob)->create();

        $count = $this->action()->execute($analysisJob, $this->twoChannelAggregatedMetrics());

        $this->assertSame(0, $count);
        $this->assertSame(0, EvaluationFact::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->count());
    }

    public function test_sales_analysis_produces_no_facts(): void
    {
        $analysisJob = AnalysisJob::factory()->create(['template_key' => 'sales_analysis']);
        AnalysisJobDetail::factory()->for($analysisJob)->create([
            'effective_column_mapping' => [
                'revenue' => ['column' => 'revenue', 'status' => 'mapped', 'source' => 'ai'],
            ],
        ]);

        $count = $this->action()->execute($analysisJob, $this->twoChannelAggregatedMetrics());

        $this->assertSame(0, $count);
    }
}
