<?php

declare(strict_types=1);

namespace Tests\Feature\Diagnosis;

use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use App\Models\DiagnosisResult;
use App\Models\EvaluationFact;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Direct coverage for the diagnosis_results migration / DiagnosisResult
 * model contract: casts, relations, the unique(evaluation_fact_id)
 * constraint, and cascadeOnDelete from both analysis_jobs and
 * evaluation_facts. See docs/product/DIAGNOSIS_ENGINE.md "Persistence".
 */
class DiagnosisResultTest extends TestCase
{
    use RefreshDatabase;

    private function analysisJobWithFact(): array
    {
        $analysisJob = AnalysisJob::factory()->create(['template_key' => 'ad_performance']);
        AnalysisJobDetail::factory()->for($analysisJob)->create();
        $fact = EvaluationFact::factory()->for($analysisJob, 'analysisJob')->create();

        return [$analysisJob, $fact];
    }

    public function test_json_and_confidence_columns_are_cast(): void
    {
        [$analysisJob, $fact] = $this->analysisJobWithFact();

        $result = DiagnosisResult::factory()->create([
            'analysis_job_id' => $analysisJob->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
            'self_reported_confidence' => 0.375,
            'evidence_refs_json' => ['trigger:evaluation_fact:1'],
            'missing_evidence_json' => ['landing-page-level conversion rate'],
            'supporting_facts_json' => [['metric_key' => 'spend', 'value' => 1000]],
        ]);

        $fresh = DiagnosisResult::query()->findOrFail($result->diagnosis_result_id);

        $this->assertIsFloat($fresh->self_reported_confidence);
        $this->assertEqualsWithDelta(0.375, $fresh->self_reported_confidence, 1e-9);
        $this->assertIsArray($fresh->evidence_refs_json);
        $this->assertSame(['trigger:evaluation_fact:1'], $fresh->evidence_refs_json);
        $this->assertSame(['landing-page-level conversion rate'], $fresh->missing_evidence_json);
        $this->assertSame([['metric_key' => 'spend', 'value' => 1000]], $fresh->supporting_facts_json);
    }

    public function test_relations_resolve(): void
    {
        [$analysisJob, $fact] = $this->analysisJobWithFact();

        $result = DiagnosisResult::factory()->create([
            'analysis_job_id' => $analysisJob->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
        ]);

        $this->assertSame($analysisJob->analysis_job_id, $result->analysisJob->analysis_job_id);
        $this->assertSame($fact->evaluation_fact_id, $result->evaluationFact->evaluation_fact_id);
        $this->assertSame($result->diagnosis_result_id, $fact->fresh()->diagnosisResult->diagnosis_result_id);
    }

    public function test_evaluation_fact_id_is_unique(): void
    {
        [$analysisJob, $fact] = $this->analysisJobWithFact();

        DiagnosisResult::factory()->create([
            'analysis_job_id' => $analysisJob->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
        ]);

        $this->expectException(QueryException::class);

        DiagnosisResult::factory()->create([
            'analysis_job_id' => $analysisJob->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
        ]);
    }

    public function test_deleting_the_evaluation_fact_cascades_to_its_diagnosis_result(): void
    {
        [$analysisJob, $fact] = $this->analysisJobWithFact();

        $result = DiagnosisResult::factory()->create([
            'analysis_job_id' => $analysisJob->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
        ]);

        $fact->delete();

        $this->assertNull(DiagnosisResult::query()->find($result->diagnosis_result_id));
    }

    public function test_deleting_the_analysis_job_cascades_to_its_diagnosis_results(): void
    {
        [$analysisJob, $fact] = $this->analysisJobWithFact();

        $result = DiagnosisResult::factory()->create([
            'analysis_job_id' => $analysisJob->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
        ]);

        $analysisJob->forceDelete();

        $this->assertNull(DiagnosisResult::query()->find($result->diagnosis_result_id));
    }
}
