<?php

declare(strict_types=1);

namespace App\Queries\AnalysisJob;

use App\Models\AnalysisJob;
use App\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListAnalysisJobsQuery
{
    private const int PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, AnalysisJob> */
    public function execute(Project $project): LengthAwarePaginator
    {
        return AnalysisJob::query()
            ->whereHas('dataFile', function ($query) use ($project): void {
                $query->where('project_id', $project->project_id);
            })
            ->with('dataFile')
            ->orderByDesc('created_at')
            ->orderByDesc('analysis_job_id')
            ->paginate(self::PER_PAGE);
    }
}
