<?php

declare(strict_types=1);

namespace App\Actions\AnalysisJob;

use App\Enums\AnalysisJobStatus;
use App\Jobs\ExecuteAnalysisJob;
use App\Models\AnalysisJob;
use App\Models\DataFile;
use Illuminate\Support\Facades\DB;

class CreateAnalysisJobAction
{
    /**
     * Create an AnalysisJob together with its AnalysisJobDetail for the given data file.
     *
     * AnalysisJob and AnalysisJobDetail are created together inside a single
     * database transaction so a partial pair is never left behind. The
     * queue job is dispatched only after the transaction commits, so a
     * worker can never pick up the job before its records are visible.
     *
     * $prompt is stored as-is: it is always exactly what the user typed
     * (their whole request for free-form analysis, or their optional
     * additional request when $templateKey is set) — this action never
     * merges an Analysis Template's instruction into it. Resolving
     * $templateKey into an actual Analysis Template / Column Mapping is
     * ExecuteAnalysisJobAction's responsibility, not this one's (see
     * ResolveAnalysisTemplateAction).
     *
     * @param DataFile $dataFile
     * @param string $title
     * @param string $prompt
     * @param string|null $templateKey a config('analysis_templates') key, or null for free-form analysis
     * @return AnalysisJob
     */
    public function execute(DataFile $dataFile, string $title, string $prompt, ?string $templateKey = null): AnalysisJob
    {
        return DB::transaction(function () use ($dataFile, $title, $prompt, $templateKey): AnalysisJob {
            $analysisJob = AnalysisJob::create([
                'data_file_id' => $dataFile->data_file_id,
                'title' => $title,
                'template_key' => $templateKey,
                'status' => AnalysisJobStatus::Pending,
            ]);

            // NOTE: raw_response/result/error_message/started_at/completed_at/
            // column_mapping は nullable でDBデフォルトも null なので、
            // Action では省略する
            $analysisJob->analysisJobDetail()->create([
                'prompt' => $prompt,
            ]);

            ExecuteAnalysisJob::dispatch($analysisJob->analysis_job_id)->afterCommit();

            return $analysisJob;
        });
    }
}
