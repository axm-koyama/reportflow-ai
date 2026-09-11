<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Enums\AnalysisJobStatus;
use App\Models\AnalysisJob;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FailedAnalysisRecoveryModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_lineage_relationships_support_a_direct_recovery_chain(): void
    {
        $first = AnalysisJob::factory()->create(['status' => AnalysisJobStatus::Failed]);
        $second = AnalysisJob::factory()->for($first->dataFile)->create([
            'status' => AnalysisJobStatus::Failed,
            'recovered_from_analysis_job_id' => $first->analysis_job_id,
        ]);
        $third = AnalysisJob::factory()->for($first->dataFile)->create([
            'recovered_from_analysis_job_id' => $second->analysis_job_id,
        ]);

        $this->assertTrue($second->recoveredFrom->is($first));
        $this->assertTrue($first->recoveryAttempt->is($second));
        $this->assertTrue($third->recoveredFrom->is($second));
        $this->assertTrue($second->recoveryAttempt->is($third));
        $this->assertNull($first->recovered_from_analysis_job_id);
    }

    public function test_one_source_cannot_have_two_direct_recovery_children(): void
    {
        $source = AnalysisJob::factory()->create(['status' => AnalysisJobStatus::Failed]);
        AnalysisJob::factory()->for($source->dataFile)->create([
            'recovered_from_analysis_job_id' => $source->analysis_job_id,
        ]);

        $this->expectException(QueryException::class);

        AnalysisJob::factory()->for($source->dataFile)->create([
            'recovered_from_analysis_job_id' => $source->analysis_job_id,
        ]);
    }

    public function test_source_cannot_be_force_deleted_while_a_recovery_child_references_it(): void
    {
        $source = AnalysisJob::factory()->create(['status' => AnalysisJobStatus::Failed]);
        AnalysisJob::factory()->for($source->dataFile)->create([
            'recovered_from_analysis_job_id' => $source->analysis_job_id,
        ]);

        $this->expectException(QueryException::class);

        $source->forceDelete();
    }
}
