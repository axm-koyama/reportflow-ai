<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Actions\AnalysisJob\RecoverFailedAnalysisJobAction;
use App\Enums\AnalysisJobStatus;
use App\Enums\ProjectStatus;
use App\Jobs\ExecuteAnalysisJob;
use App\Models\ActionProposal;
use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use App\Models\DataFile;
use App\Models\DiagnosisResult;
use App\Models\EvaluationFact;
use App\Models\PriorityResult;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PDOException;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class RecoverFailedAnalysisJobActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_one_pending_child_with_the_copy_reset_contract_and_dispatches_once(): void
    {
        Queue::fake();
        [$project, $source, $sourceDetail] = $this->failedSource();
        $sourceBefore = $source->fresh()->only([
            'data_file_id', 'title', 'template_key', 'status', 'created_at', 'updated_at',
        ]);
        $sourceDetailBefore = $sourceDetail->fresh()->only([
            'prompt', 'column_mapping', 'manual_column_mapping', 'effective_column_mapping',
            'raw_response', 'result', 'error_message', 'started_at', 'completed_at',
            'created_at', 'updated_at',
        ]);

        $result = app(RecoverFailedAnalysisJobAction::class)->execute($project, $source);
        $child = $result['analysis_job']->fresh('analysisJobDetail');

        $this->assertTrue($result['created']);
        $this->assertSame(AnalysisJobStatus::Pending, $child->status);
        $this->assertSame($source->analysis_job_id, $child->recovered_from_analysis_job_id);
        $this->assertSame($source->data_file_id, $child->data_file_id);
        $this->assertSame($source->title, $child->title);
        $this->assertSame($source->template_key, $child->template_key);
        $this->assertSame($sourceDetail->prompt, $child->analysisJobDetail->prompt);
        $this->assertSame($sourceDetail->column_mapping, $child->analysisJobDetail->column_mapping);
        $this->assertSame($sourceDetail->manual_column_mapping, $child->analysisJobDetail->manual_column_mapping);
        $this->assertSame($sourceDetail->effective_column_mapping, $child->analysisJobDetail->effective_column_mapping);

        foreach (['raw_response', 'result', 'error_message', 'started_at', 'completed_at'] as $attribute) {
            $this->assertNull($child->analysisJobDetail->{$attribute});
        }

        $this->assertEquals($sourceBefore, $source->fresh()->only([
            'data_file_id', 'title', 'template_key', 'status', 'created_at', 'updated_at',
        ]));
        $this->assertEquals($sourceDetailBefore, $sourceDetail->fresh()->only([
            'prompt', 'column_mapping', 'manual_column_mapping', 'effective_column_mapping',
            'raw_response', 'result', 'error_message', 'started_at', 'completed_at',
            'created_at', 'updated_at',
        ]));
        Queue::assertPushed(ExecuteAnalysisJob::class, 1);
        Queue::assertPushed(
            ExecuteAnalysisJob::class,
            fn (ExecuteAnalysisJob $job): bool => $job->analysisJobId === $child->analysis_job_id,
        );
    }

    public function test_duplicate_recovery_returns_the_existing_child_without_redispatch(): void
    {
        Queue::fake();
        [$project, $source] = $this->failedSource();
        $action = app(RecoverFailedAnalysisJobAction::class);

        $first = $action->execute($project, $source);
        $second = $action->execute($project, $source);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertTrue($first['analysis_job']->is($second['analysis_job']));
        $this->assertSame(1, AnalysisJob::query()->where('recovered_from_analysis_job_id', $source->analysis_job_id)->count());
        Queue::assertPushed(ExecuteAnalysisJob::class, 1);
    }

    public function test_simulated_unique_race_returns_competing_child_without_dispatch(): void
    {
        Queue::fake();
        [$project, $source] = $this->failedSource();
        $competingChildId = null;

        AnalysisJob::creating(function (AnalysisJob $creating) use ($source, &$competingChildId): void {
            if ($creating->recovered_from_analysis_job_id !== $source->analysis_job_id || $competingChildId !== null) {
                return;
            }

            $now = now();
            $competingChildId = DB::table('analysis_jobs')->insertGetId([
                'data_file_id' => $source->data_file_id,
                'title' => $source->title,
                'template_key' => $source->template_key,
                'status' => AnalysisJobStatus::Pending->value,
                'recovered_from_analysis_job_id' => $source->analysis_job_id,
                'created_at' => $now,
                'updated_at' => $now,
            ], 'analysis_job_id');
            DB::table('analysis_job_details')->insert([
                'analysis_job_id' => $competingChildId,
                'prompt' => $source->analysisJobDetail()->firstOrFail()->prompt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        try {
            $result = app(RecoverFailedAnalysisJobAction::class)->execute($project, $source);
        } finally {
            AnalysisJob::flushEventListeners();
            AnalysisJob::clearBootedModels();
        }

        $this->assertFalse($result['created']);
        $this->assertSame($competingChildId, $result['analysis_job']->analysis_job_id);
        $this->assertSame(1, AnalysisJob::query()->where('recovered_from_analysis_job_id', $source->analysis_job_id)->count());
        $this->assertDatabaseHas('analysis_job_details', ['analysis_job_id' => $competingChildId]);
        Queue::assertNothingPushed();
    }

    public function test_unique_violation_detection_requires_sqlstate_and_recovery_constraint_identity(): void
    {
        $action = app(RecoverFailedAnalysisJobAction::class);
        $method = new ReflectionMethod($action, 'isRecoveryLineageUniqueViolation');

        $this->assertFalse($method->invoke($action, $this->queryException(
            'HY000',
            19,
            'UNIQUE constraint failed: analysis_jobs.recovered_from_analysis_job_id',
        )));
        $this->assertFalse($method->invoke($action, $this->queryException(
            '23000',
            1062,
            "Duplicate entry '1' for key 'analysis_jobs_other_uniq'",
        )));
        $this->assertFalse($method->invoke($action, $this->queryException(
            '23000',
            999,
            'UNIQUE constraint failed: analysis_jobs.recovered_from_analysis_job_id',
        )));
        $this->assertTrue($method->invoke($action, $this->queryException(
            '23000',
            1062,
            "Duplicate entry '1' for key 'analysis_jobs_recovery_source_uniq'",
        )));
        $this->assertTrue($method->invoke($action, $this->queryException(
            '23000',
            19,
            'UNIQUE constraint failed: analysis_jobs.recovered_from_analysis_job_id',
        )));
    }

    public function test_recovery_chain_uses_each_failed_attempt_as_the_direct_parent(): void
    {
        Queue::fake();
        [$project, $first] = $this->failedSource();
        $action = app(RecoverFailedAnalysisJobAction::class);
        $second = $action->execute($project, $first)['analysis_job'];
        $second->update(['status' => AnalysisJobStatus::Failed]);
        $third = $action->execute($project, $second)['analysis_job'];

        $this->assertSame($first->analysis_job_id, $second->recovered_from_analysis_job_id);
        $this->assertSame($second->analysis_job_id, $third->recovered_from_analysis_job_id);
        $this->assertNotSame($first->analysis_job_id, $third->recovered_from_analysis_job_id);
        Queue::assertPushed(ExecuteAnalysisJob::class, 2);
    }

    public function test_all_non_failed_statuses_are_rejected_without_creation_or_dispatch(): void
    {
        foreach ([AnalysisJobStatus::Pending, AnalysisJobStatus::Processing, AnalysisJobStatus::Completed, AnalysisJobStatus::AwaitingMappingConfirmation] as $status) {
            Queue::fake();
            [$project, $source] = $this->failedSource($status);

            try {
                app(RecoverFailedAnalysisJobAction::class)->execute($project, $source);
                $this->fail("Expected recovery rejection for {$status->name}.");
            } catch (ValidationException) {
                $this->assertSame(0, AnalysisJob::query()->where('recovered_from_analysis_job_id', $source->analysis_job_id)->count());
                Queue::assertNothingPushed();
            }
        }
    }

    public function test_archived_wrong_project_deleted_data_file_and_missing_detail_are_rejected(): void
    {
        Queue::fake();
        [$project, $source] = $this->failedSource();
        $project->update(['status' => ProjectStatus::Archived]);

        try {
            app(RecoverFailedAnalysisJobAction::class)->execute($project->fresh(), $source);
            $this->fail('Expected archived Project rejection.');
        } catch (ValidationException) {
            Queue::assertNothingPushed();
        }

        $project->update(['status' => ProjectStatus::Active]);
        $otherProject = Project::factory()->create();
        $this->expectException(NotFoundHttpException::class);
        app(RecoverFailedAnalysisJobAction::class)->execute($otherProject, $source);
    }

    public function test_deleted_data_file_and_missing_detail_create_no_child(): void
    {
        Queue::fake();
        [$project, $source] = $this->failedSource();
        $source->dataFile->delete();

        try {
            app(RecoverFailedAnalysisJobAction::class)->execute($project, $source);
            $this->fail('Expected deleted DataFile rejection.');
        } catch (NotFoundHttpException) {
            $this->assertDatabaseMissing('analysis_jobs', ['recovered_from_analysis_job_id' => $source->analysis_job_id]);
        }

        [$secondProject, $missingDetailSource] = $this->failedSource(createDetail: false);

        try {
            app(RecoverFailedAnalysisJobAction::class)->execute($secondProject, $missingDetailSource);
            $this->fail('Expected missing detail invariant exception.');
        } catch (RuntimeException) {
            $this->assertDatabaseMissing('analysis_jobs', ['recovered_from_analysis_job_id' => $missingDetailSource->analysis_job_id]);
            Queue::assertNothingPushed();
        }
    }

    public function test_downstream_records_are_not_copied(): void
    {
        Queue::fake();
        [$project, $source] = $this->failedSource();
        $fact = EvaluationFact::factory()->for($source, 'analysisJob')->create();
        $diagnosis = DiagnosisResult::factory()->create(['analysis_job_id' => $source->analysis_job_id, 'evaluation_fact_id' => $fact->evaluation_fact_id]);
        $priority = PriorityResult::factory()->create(['analysis_job_id' => $source->analysis_job_id, 'evaluation_fact_id' => $fact->evaluation_fact_id]);
        ActionProposal::factory()->create([
            'analysis_job_id' => $source->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
            'diagnosis_result_id' => $diagnosis->diagnosis_result_id,
            'priority_result_id' => $priority->priority_result_id,
        ]);

        $child = app(RecoverFailedAnalysisJobAction::class)->execute($project, $source)['analysis_job'];

        $this->assertSame(0, $child->evaluationFacts()->count());
        $this->assertSame(0, DiagnosisResult::query()->where('analysis_job_id', $child->analysis_job_id)->count());
        $this->assertSame(0, PriorityResult::query()->where('analysis_job_id', $child->analysis_job_id)->count());
        $this->assertSame(0, $child->actionProposals()->count());
        $this->assertSame(1, $source->evaluationFacts()->count());
        $this->assertSame(1, $source->actionProposals()->count());
    }

    /** @return array{Project, AnalysisJob, AnalysisJobDetail|null} */
    private function failedSource(AnalysisJobStatus $status = AnalysisJobStatus::Failed, bool $createDetail = true): array
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $dataFile = DataFile::factory()->for($project)->create();
        $source = AnalysisJob::factory()->for($dataFile)->create([
            'title' => 'Original title',
            'template_key' => 'ad_performance',
            'status' => $status,
        ]);
        $detail = $createDetail ? AnalysisJobDetail::factory()->for($source)->create([
            'prompt' => 'Original prompt',
            'column_mapping' => ['channel' => ['column' => 'channel', 'confidence' => 'high', 'status' => 'mapped']],
            'manual_column_mapping' => ['channel' => ['column' => 'channel']],
            'effective_column_mapping' => ['channel' => ['column' => 'channel', 'status' => 'mapped', 'source' => 'manual']],
            'raw_response' => 'old raw response',
            'result' => ['summary' => 'partial'],
            'error_message' => 'provider failure',
            'started_at' => now()->subMinute()->startOfSecond(),
            'completed_at' => now()->startOfSecond(),
        ]) : null;

        return [$project, $source, $detail];
    }

    private function queryException(string $sqlState, int $driverCode, string $message): QueryException
    {
        $previous = new PDOException($message);
        $previous->errorInfo = [$sqlState, $driverCode, $message];

        return new QueryException('testing', 'insert into analysis_jobs', [], $previous);
    }
}
