<?php

declare(strict_types=1);

namespace Tests\Feature\Diagnosis;

use App\Actions\Diagnosis\RunDiagnosisForAnalysisJobAction;
use App\AI\AiAnalysisClient;
use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use App\Models\DiagnosisResult;
use App\Models\EvaluationFact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Direct coverage for RunDiagnosisForAnalysisJobAction's orchestration
 * contract: Eligibility filtering, per-entity soft-fail, idempotency /
 * stale cleanup. See docs/product/DIAGNOSIS_ENGINE.md.
 */
class RunDiagnosisForAnalysisJobActionTest extends TestCase
{
    use RefreshDatabase;

    private function action(): RunDiagnosisForAnalysisJobAction
    {
        return app(RunDiagnosisForAnalysisJobAction::class);
    }

    private function effectiveMapping(): array
    {
        return [
            'channel' => ['column' => 'channel', 'status' => 'mapped', 'source' => 'ai'],
            'conversions' => ['column' => 'conversions', 'status' => 'mapped', 'source' => 'ai'],
            'clicks' => ['column' => 'clicks', 'status' => 'mapped', 'source' => 'ai'],
        ];
    }

    private function analysisJob(?array $effectiveMapping): AnalysisJob
    {
        $analysisJob = AnalysisJob::factory()->create(['template_key' => 'ad_performance']);
        AnalysisJobDetail::factory()->for($analysisJob)->create([
            'effective_column_mapping' => $effectiveMapping,
        ]);

        return $analysisJob;
    }

    private function aggregatedMetrics(): array
    {
        return [
            'dimensions' => [
                [
                    'dimension' => 'channel',
                    'group_count' => 1,
                    'groups' => [
                        ['value' => 'Social', 'count' => 100, 'metrics' => [
                            'conversions' => ['sum' => 200, 'count' => 100],
                            'clicks' => ['sum' => 5000, 'count' => 100],
                        ]],
                    ],
                ],
            ],
            'measures' => ['conversions', 'clicks'],
        ];
    }

    /**
     * @param  list<string>  $evidenceRefs  must contain at least one
     *                                      supplied evidence identifier (see
     *                                      NormalizeDiagnosisResultAction) — defaults to a
     *                                      placeholder trigger reference; pass the real
     *                                      fact's own when the caller knows it.
     */
    private function diagnoseResponse(string $categoryKey = 'insufficient_explanatory_evidence', array $evidenceRefs = ['trigger:evaluation_fact:1']): string
    {
        return json_encode([
            'primary_diagnosis' => [
                'category_key' => $categoryKey,
                'self_reported_confidence' => 0.4,
                'rationale_summary' => 'The evidence does not distinguish a specific cause.',
                'evidence_refs' => $evidenceRefs,
                'missing_evidence' => ['landing-page-level conversion rate'],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    public function test_it_persists_a_diagnosis_result_for_an_eligible_fact(): void
    {
        $analysisJob = $this->analysisJob($this->effectiveMapping());
        $fact = EvaluationFact::factory()->for($analysisJob, 'analysisJob')->create([
            'entity_key' => 'Social',
            'metric_key' => 'conversion_rate',
            'evaluation_level' => 'high',
            'direction' => 'below',
        ]);

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('diagnose')
            ->once()
            ->andReturn($this->diagnoseResponse(evidenceRefs: ['trigger:evaluation_fact:'.$fact->evaluation_fact_id]));

        $count = $this->action()->execute($analysisJob, $this->aggregatedMetrics());

        $this->assertSame(1, $count);

        $result = DiagnosisResult::query()->where('evaluation_fact_id', $fact->evaluation_fact_id)->firstOrFail();
        $this->assertSame($analysisJob->analysis_job_id, $result->analysis_job_id);
        $this->assertSame('insufficient_explanatory_evidence', $result->category_key);
        $this->assertEqualsWithDelta(0.4, $result->self_reported_confidence, 1e-9);
        $this->assertSame(['trigger:evaluation_fact:'.$fact->evaluation_fact_id], $result->evidence_refs_json);
        $this->assertSame(['landing-page-level conversion rate'], $result->missing_evidence_json);
        $this->assertNotNull($result->raw_response);
        $this->assertNotSame('', $result->model);
        $this->assertSame('diagnosis_prompt_v1.1', $result->prompt_version);
    }

    /**
     * The Diagnosis System Instruction sent to the AI must hedge
     * measurement_consistency_risk correctly (never assert or imply a
     * confirmed/probable tracking failure), require at least one
     * evidence_ref, and must not describe trigger_fact as "certain" —
     * see docs/product/DIAGNOSIS_ENGINE.md "measurement_consistency_risk
     * semantics" / "'trigger_fact is already certain' を修正".
     */
    public function test_the_system_instruction_hedges_measurement_consistency_risk_and_never_claims_trigger_fact_is_certain(): void
    {
        $analysisJob = $this->analysisJob($this->effectiveMapping());
        $fact = EvaluationFact::factory()->for($analysisJob, 'analysisJob')->create([
            'entity_key' => 'Social', 'evaluation_level' => 'high', 'direction' => 'below',
        ]);

        $capturedContext = null;

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('diagnose')
            ->once()
            ->withArgs(function (array $context) use (&$capturedContext): bool {
                $capturedContext = $context;

                return true;
            })
            ->andReturn($this->diagnoseResponse(evidenceRefs: ['trigger:evaluation_fact:'.$fact->evaluation_fact_id]));

        $this->action()->execute($analysisJob, $this->aggregatedMetrics());

        $instruction = mb_strtolower($capturedContext['system_instruction']);

        // Must never state or imply the finding is confirmed/probable.
        foreach (['tracking failure confirmed', 'confirmed tracking failure', 'tracking is broken', 'measurement failure confirmed'] as $overclaim) {
            $this->assertStringNotContainsString($overclaim, $instruction);
        }

        // Must positively hedge: a genuine zero-conversion outcome is an
        // equally possible explanation, never overridden by "certain".
        $this->assertStringContainsString('equally possible explanation', $instruction);
        $this->assertStringNotContainsString('is already certain', $instruction);
        $this->assertStringContainsString('trigger_fact is fixed', $instruction);
        $this->assertStringContainsString('must not be reassessed', $instruction);

        // Must require at least one evidence_ref.
        $this->assertStringContainsString('must contain at least one', $instruction);
        $this->assertStringContainsString('must never be empty', $instruction);
    }

    public function test_it_never_diagnoses_a_favorable_or_low_or_insufficient_data_fact(): void
    {
        $analysisJob = $this->analysisJob($this->effectiveMapping());

        EvaluationFact::factory()->for($analysisJob, 'analysisJob')->create([
            'entity_key' => 'Email', 'evaluation_level' => 'high', 'direction' => 'above',
        ]);
        EvaluationFact::factory()->for($analysisJob, 'analysisJob')->create([
            'entity_key' => 'Organic', 'evaluation_level' => 'low', 'direction' => 'below',
        ]);
        EvaluationFact::factory()->for($analysisJob, 'analysisJob')->create([
            'entity_key' => 'Paid', 'evaluation_level' => 'insufficient_data', 'direction' => null,
        ]);

        // No expectation set on diagnose() at all — an unexpected call
        // would fail this test via Mockery.
        $this->mock(AiAnalysisClient::class)->shouldNotReceive('diagnose');

        $count = $this->action()->execute($analysisJob, $this->aggregatedMetrics());

        $this->assertSame(0, $count);
        $this->assertSame(0, DiagnosisResult::query()->count());
    }

    public function test_free_analysis_never_runs_diagnosis(): void
    {
        $analysisJob = AnalysisJob::factory()->create(['template_key' => null]);
        AnalysisJobDetail::factory()->for($analysisJob)->create();

        $this->mock(AiAnalysisClient::class)->shouldNotReceive('diagnose');

        $count = $this->action()->execute($analysisJob, $this->aggregatedMetrics());

        $this->assertSame(0, $count);
    }

    public function test_a_template_without_an_evaluation_metrics_entry_never_runs_diagnosis(): void
    {
        $analysisJob = AnalysisJob::factory()->create(['template_key' => 'sales_analysis']);
        AnalysisJobDetail::factory()->for($analysisJob)->create([
            'effective_column_mapping' => ['product' => ['column' => 'product', 'status' => 'mapped', 'source' => 'ai']],
        ]);

        $this->mock(AiAnalysisClient::class)->shouldNotReceive('diagnose');

        $count = $this->action()->execute($analysisJob, $this->aggregatedMetrics());

        $this->assertSame(0, $count);
    }

    public function test_an_unconfirmed_effective_mapping_never_runs_diagnosis(): void
    {
        $analysisJob = $this->analysisJob(null);
        EvaluationFact::factory()->for($analysisJob, 'analysisJob')->create([
            'evaluation_level' => 'high', 'direction' => 'below',
        ]);

        $this->mock(AiAnalysisClient::class)->shouldNotReceive('diagnose');

        $count = $this->action()->execute($analysisJob, $this->aggregatedMetrics());

        $this->assertSame(0, $count);
    }

    /**
     * Per-entity soft-fail: one EvaluationFact's Diagnosis technical
     * failure never prevents another eligible EvaluationFact in the same
     * AnalysisJob from being diagnosed, and never throws out of execute().
     */
    public function test_one_facts_failure_does_not_prevent_another_facts_diagnosis(): void
    {
        $analysisJob = $this->analysisJob($this->effectiveMapping());

        $failingFact = EvaluationFact::factory()->for($analysisJob, 'analysisJob')->create([
            'entity_key' => 'Social', 'evaluation_level' => 'high', 'direction' => 'below',
        ]);
        $succeedingFact = EvaluationFact::factory()->for($analysisJob, 'analysisJob')->create([
            'entity_key' => 'Display', 'evaluation_level' => 'medium', 'direction' => 'below',
        ]);

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('diagnose')
            ->twice()
            ->andReturnUsing(function (array $context) {
                if ($context['trigger_fact']['entity_key'] === 'Social') {
                    throw new RuntimeException('OpenAI API request failed due to a connection error.');
                }

                return $this->diagnoseResponse(evidenceRefs: [$context['trigger_fact']['evidence_id']]);
            });

        $count = $this->action()->execute($analysisJob, $this->aggregatedMetrics());

        $this->assertSame(1, $count);
        $this->assertFalse(DiagnosisResult::query()->where('evaluation_fact_id', $failingFact->evaluation_fact_id)->exists());
        $this->assertTrue(DiagnosisResult::query()->where('evaluation_fact_id', $succeedingFact->evaluation_fact_id)->exists());
    }

    /**
     * A semantic failure (invalid Structured Output shape) is exactly as
     * soft-fail as an infrastructure failure — see
     * docs/product/DIAGNOSIS_ENGINE.md "Retry semantics".
     */
    public function test_an_invalid_structured_response_soft_fails_without_throwing(): void
    {
        $analysisJob = $this->analysisJob($this->effectiveMapping());
        EvaluationFact::factory()->for($analysisJob, 'analysisJob')->create([
            'evaluation_level' => 'high', 'direction' => 'below',
        ]);

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('diagnose')
            ->once()
            ->andReturn(json_encode(['primary_diagnosis' => ['category_key' => 'not_an_allowed_category']], JSON_THROW_ON_ERROR));

        $count = $this->action()->execute($analysisJob, $this->aggregatedMetrics());

        $this->assertSame(0, $count);
        $this->assertSame(0, DiagnosisResult::query()->count());
    }

    /**
     * Idempotency: a second execution fully replaces the first's rows —
     * no duplicates, no stale leftovers from a fact set that changed
     * between attempts.
     */
    public function test_a_second_execution_replaces_rather_than_duplicates(): void
    {
        $analysisJob = $this->analysisJob($this->effectiveMapping());
        // numerator_value 0 with a large denominator so both v1 categories
        // (measurement_consistency_risk and insufficient_explanatory_evidence)
        // are Evidence-gated as allowed for this fact — see
        // BuildDiagnosisEvidencePackageActionTest for that gate's own
        // dedicated coverage; this test only needs two *different*, both
        // legitimately allowed, category_key values to prove replacement.
        $fact = EvaluationFact::factory()->for($analysisJob, 'analysisJob')->create([
            'entity_key' => 'Social', 'evaluation_level' => 'high', 'direction' => 'below',
            'numerator_value' => 0, 'denominator_value' => 1000,
        ]);

        $callCount = 0;

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('diagnose')
            ->twice()
            ->andReturnUsing(function (array $context) use (&$callCount) {
                $callCount++;
                $evidenceRefs = [$context['trigger_fact']['evidence_id']];

                return $callCount === 1
                    ? $this->diagnoseResponse(evidenceRefs: $evidenceRefs)
                    : $this->diagnoseResponse('measurement_consistency_risk', $evidenceRefs);
            });

        $this->action()->execute($analysisJob, $this->aggregatedMetrics());
        $this->assertSame(1, DiagnosisResult::query()->count());
        $firstRowId = DiagnosisResult::query()->first()->diagnosis_result_id;

        $this->action()->execute($analysisJob, $this->aggregatedMetrics());

        $this->assertSame(1, DiagnosisResult::query()->count());
        $result = DiagnosisResult::query()->where('evaluation_fact_id', $fact->evaluation_fact_id)->firstOrFail();
        $this->assertNotSame($firstRowId, $result->diagnosis_result_id);
        $this->assertSame('measurement_consistency_risk', $result->category_key);
    }

    /**
     * Stale cleanup: an existing DiagnosisResult row is deleted at the
     * start of this attempt, *before* any new Diagnosis work runs — so a
     * technical failure on this attempt never leaves a previous attempt's
     * result looking like it belongs to the current one. See
     * docs/product/DIAGNOSIS_ENGINE.md "Idempotency / stale Diagnosis
     * cleanup".
     */
    public function test_a_failed_attempt_does_not_leave_a_previous_results_row_behind(): void
    {
        $analysisJob = $this->analysisJob($this->effectiveMapping());
        $fact = EvaluationFact::factory()->for($analysisJob, 'analysisJob')->create([
            'entity_key' => 'Social', 'evaluation_level' => 'high', 'direction' => 'below',
        ]);

        DiagnosisResult::factory()->create([
            'analysis_job_id' => $analysisJob->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
            'category_key' => 'measurement_consistency_risk',
        ]);

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('diagnose')
            ->once()
            ->andThrow(new RuntimeException('OpenAI API request failed with HTTP status 500.'));

        $count = $this->action()->execute($analysisJob, $this->aggregatedMetrics());

        $this->assertSame(0, $count);
        $this->assertFalse(DiagnosisResult::query()->where('evaluation_fact_id', $fact->evaluation_fact_id)->exists());
    }
}
