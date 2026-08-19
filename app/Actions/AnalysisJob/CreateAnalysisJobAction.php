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
     * @param DataFile $dataFile
     * @param string $title
     * @param string $prompt
     * @return AnalysisJob
     */
    public function execute(DataFile $dataFile, string $title, string $prompt): AnalysisJob
    {
        return DB::transaction(function () use ($dataFile, $title, $prompt): AnalysisJob {
            $analysisJob = AnalysisJob::create([
                'data_file_id' => $dataFile->data_file_id,
                'title' => $title,
                'status' => AnalysisJobStatus::Pending,
            ]);

            // NOTE: raw_response/result/error_message/started_at/completed_at
            // は nullable でDBデフォルトも null なので、Action では省略する
            $analysisJob->analysisJobDetail()->create([
                'prompt' => $prompt,
            ]);

            ExecuteAnalysisJob::dispatch($analysisJob->analysis_job_id)->afterCommit();

            return $analysisJob;
        });
    }
}
