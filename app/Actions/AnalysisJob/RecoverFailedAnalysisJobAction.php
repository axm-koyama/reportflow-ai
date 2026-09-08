<?php

declare(strict_types=1);

namespace App\Actions\AnalysisJob;

use App\Enums\AnalysisJobStatus;
use App\Enums\ProjectStatus;
use App\Jobs\ExecuteAnalysisJob;
use App\Models\AnalysisJob;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RecoverFailedAnalysisJobAction
{
    /** @return array{analysis_job: AnalysisJob, created: bool} */
    public function execute(Project $project, AnalysisJob $source): array
    {
        try {
            return DB::transaction(function () use ($project, $source): array {
                $lockedSource = AnalysisJob::query()
                    ->with(['analysisJobDetail', 'dataFile.project'])
                    ->lockForUpdate()
                    ->findOrFail($source->analysis_job_id);

                abort_if(
                    $lockedSource->dataFile === null
                    || $lockedSource->dataFile->project_id !== $project->project_id,
                    404,
                );

                if ($lockedSource->dataFile->project->status !== ProjectStatus::Active) {
                    throw ValidationException::withMessages([
                        'project' => ['Recovery attempts can only be created for an active project.'],
                    ]);
                }

                $existingChild = AnalysisJob::query()
                    ->where('recovered_from_analysis_job_id', $lockedSource->analysis_job_id)
                    ->first();

                if ($existingChild !== null) {
                    return ['analysis_job' => $existingChild, 'created' => false];
                }

                if ($lockedSource->status !== AnalysisJobStatus::Failed) {
                    throw ValidationException::withMessages([
                        'analysis_job' => ['Only a failed analysis can create a recovery attempt.'],
                    ]);
                }

                $sourceDetail = $lockedSource->analysisJobDetail;

                if ($sourceDetail === null) {
                    throw new RuntimeException('A failed AnalysisJob must have an AnalysisJobDetail before recovery.');
                }

                try {
                    $child = AnalysisJob::query()->create([
                        'data_file_id' => $lockedSource->data_file_id,
                        'title' => $lockedSource->title,
                        'template_key' => $lockedSource->template_key,
                        'status' => AnalysisJobStatus::Pending,
                        'recovered_from_analysis_job_id' => $lockedSource->analysis_job_id,
                    ]);
                } catch (QueryException $exception) {
                    return $this->resolveRecoveryLineageConflict($exception, $lockedSource);
                }

                $child->analysisJobDetail()->create([
                    'prompt' => $sourceDetail->prompt,
                    'column_mapping' => $sourceDetail->column_mapping,
                    'manual_column_mapping' => $sourceDetail->manual_column_mapping,
                    'effective_column_mapping' => $sourceDetail->effective_column_mapping,
                ]);

                ExecuteAnalysisJob::dispatch($child->analysis_job_id)->afterCommit();

                return ['analysis_job' => $child, 'created' => true];
            });
        } catch (QueryException $exception) {
            return $this->resolveRecoveryLineageConflict($exception, $source);
        }
    }

    /** @return array{analysis_job: AnalysisJob, created: false} */
    private function resolveRecoveryLineageConflict(QueryException $exception, AnalysisJob $source): array
    {
        if (! $this->isRecoveryLineageUniqueViolation($exception)) {
            throw $exception;
        }

        $existingChild = AnalysisJob::query()
            ->where('recovered_from_analysis_job_id', $source->analysis_job_id)
            ->first();

        if ($existingChild === null) {
            throw $exception;
        }

        return ['analysis_job' => $existingChild, 'created' => false];
    }

    private function isRecoveryLineageUniqueViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = $exception->errorInfo[1] ?? null;
        $message = $exception->getMessage();

        if ($sqlState !== '23000') {
            return false;
        }

        return ($driverCode === 1062 && str_contains($message, 'analysis_jobs_recovery_source_uniq'))
            || ($driverCode === 19 && str_contains($message, 'analysis_jobs.recovered_from_analysis_job_id'));
    }
}
