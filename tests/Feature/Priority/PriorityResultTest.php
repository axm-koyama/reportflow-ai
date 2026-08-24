<?php

declare(strict_types=1);

namespace Tests\Feature\Priority;

use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use App\Models\EvaluationFact;
use App\Models\PriorityResult;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Direct coverage for the priority_results migration / PriorityResult
 * model contract: casts, relations, the unique(evaluation_fact_id)
 * constraint, and cascadeOnDelete from both analysis_jobs and
 * evaluation_facts. See docs/product/PRIORITY_ENGINE.md "Persistence".
 * Mirrors DiagnosisResultTest's own convention exactly.
 */
class PriorityResultTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: AnalysisJob, 1: EvaluationFact}
     */
    private function analysisJobWithFact(): array
    {
        $analysisJob = AnalysisJob::factory()->create(['template_key' => 'ad_performance']);
        AnalysisJobDetail::factory()->for($analysisJob)->create();
        $fact = EvaluationFact::factory()->for($analysisJob, 'analysisJob')->create();

        return [$analysisJob, $fact];
    }

    public function test_numeric_columns_are_cast_to_float(): void
    {
        [$analysisJob, $fact] = $this->analysisJobWithFact();

        $result = PriorityResult::factory()->create([
            'analysis_job_id' => $analysisJob->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
            'impact_value' => 12000,
            'impact_total' => 60000,
            'impact_score' => 0.2,
            'gap_raw_value' => 0.014,
            'gap_reference_value' => 0.02,
            'gap_score' => 0.7,
            'priority_score' => 0.14,
        ]);

        $fresh = PriorityResult::query()->findOrFail($result->priority_result_id);

        $this->assertIsFloat($fresh->impact_value);
        $this->assertIsFloat($fresh->impact_total);
        $this->assertIsFloat($fresh->impact_score);
        $this->assertIsFloat($fresh->gap_raw_value);
        $this->assertIsFloat($fresh->gap_reference_value);
        $this->assertIsFloat($fresh->gap_score);
        $this->assertIsFloat($fresh->priority_score);
        $this->assertEqualsWithDelta(0.14, $fresh->priority_score, 1e-9);
    }

    public function test_relations_resolve(): void
    {
        [$analysisJob, $fact] = $this->analysisJobWithFact();

        $result = PriorityResult::factory()->create([
            'analysis_job_id' => $analysisJob->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
        ]);

        $this->assertSame($analysisJob->analysis_job_id, $result->analysisJob->analysis_job_id);
        $this->assertSame($fact->evaluation_fact_id, $result->evaluationFact->evaluation_fact_id);
        $this->assertSame($result->priority_result_id, $fact->fresh()->priorityResult->priority_result_id);
    }

    public function test_evaluation_fact_id_is_unique(): void
    {
        [$analysisJob, $fact] = $this->analysisJobWithFact();

        PriorityResult::factory()->create([
            'analysis_job_id' => $analysisJob->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
        ]);

        $this->expectException(QueryException::class);

        PriorityResult::factory()->create([
            'analysis_job_id' => $analysisJob->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
        ]);
    }

    public function test_deleting_the_evaluation_fact_cascades_to_its_priority_result(): void
    {
        [$analysisJob, $fact] = $this->analysisJobWithFact();

        $result = PriorityResult::factory()->create([
            'analysis_job_id' => $analysisJob->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
        ]);

        $fact->delete();

        $this->assertNull(PriorityResult::query()->find($result->priority_result_id));
    }

    public function test_deleting_the_analysis_job_cascades_to_its_priority_results(): void
    {
        [$analysisJob, $fact] = $this->analysisJobWithFact();

        $result = PriorityResult::factory()->create([
            'analysis_job_id' => $analysisJob->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
        ]);

        $analysisJob->forceDelete();

        $this->assertNull(PriorityResult::query()->find($result->priority_result_id));
    }
}
