<?php

declare(strict_types=1);

namespace App\Actions\Report;

use App\Enums\AnalysisJobStatus;
use App\Enums\ProjectStatus;
use App\Models\AnalysisJob;
use App\Models\Project;
use App\Models\Report;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerateHtmlReportForAnalysisJobAction
{
    public function __construct(
        private readonly BuildReportSnapshotAction $buildReportSnapshotAction,
        private readonly RenderHtmlReportAction $renderHtmlReportAction,
    ) {}

    /** @return array{report: Report, created: bool} */
    public function execute(Project $project, AnalysisJob $source): array
    {
        try {
            return DB::transaction(function () use ($project, $source): array {
                $analysisJob = AnalysisJob::query()
                    ->with([
                        'dataFile.project',
                        'analysisJobDetail',
                        'evaluationFacts' => fn ($query) => $query->orderBy('evaluation_fact_id'),
                        'evaluationFacts.diagnosisResult',
                        'evaluationFacts.priorityResult',
                        'actionProposals' => fn ($query) => $query->orderBy('action_proposal_id'),
                        'actionProposals.evaluationFact',
                        'actionProposals.priorityResult',
                        'report',
                    ])
                    ->lockForUpdate()
                    ->findOrFail($source->analysis_job_id);

                abort_if(
                    $analysisJob->dataFile === null || $analysisJob->dataFile->project_id !== $project->project_id,
                    404,
                );

                if ($analysisJob->report !== null) {
                    return ['report' => $analysisJob->report, 'created' => false];
                }

                if ($analysisJob->dataFile->project->status !== ProjectStatus::Active) {
                    throw ValidationException::withMessages(['project' => ['Reports can only be generated for an active project.']]);
                }

                if ($analysisJob->status !== AnalysisJobStatus::Completed) {
                    throw ValidationException::withMessages(['analysis_job' => ['Only a completed analysis can generate a Report.']]);
                }

                $snapshot = $this->buildReportSnapshotAction->execute($analysisJob);
                $html = $this->renderHtmlReportAction->execute($snapshot);

                try {
                    $report = Report::query()->create([
                        'analysis_job_id' => $analysisJob->analysis_job_id,
                        'title' => $analysisJob->title,
                        'snapshot_json' => $snapshot,
                        'rendered_html' => $html,
                        'schema_version' => BuildReportSnapshotAction::SCHEMA_VERSION,
                        'renderer_version' => RenderHtmlReportAction::RENDERER_VERSION,
                        'content_hash' => hash('sha256', $html),
                        'generated_at' => $snapshot['generated_at'],
                    ]);
                } catch (QueryException $exception) {
                    return $this->resolveUniqueConflict($exception, $analysisJob);
                }

                return ['report' => $report, 'created' => true];
            });
        } catch (QueryException $exception) {
            return $this->resolveUniqueConflict($exception, $source);
        }
    }

    /** @return array{report: Report, created: false} */
    private function resolveUniqueConflict(QueryException $exception, AnalysisJob $source): array
    {
        if (! $this->isReportUniqueViolation($exception)) {
            throw $exception;
        }

        $report = Report::query()->where('analysis_job_id', $source->analysis_job_id)->first();

        if ($report === null) {
            throw $exception;
        }

        return ['report' => $report, 'created' => false];
    }

    private function isReportUniqueViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = $exception->errorInfo[1] ?? null;
        $message = $exception->getMessage();

        if ($sqlState !== '23000') {
            return false;
        }

        return ($driverCode === 1062 && str_contains($message, 'reports_analysis_job_uniq'))
            || ($driverCode === 19 && str_contains($message, 'reports.analysis_job_id'));
    }
}
