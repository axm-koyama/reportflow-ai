<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Report\GenerateHtmlReportForAnalysisJobAction;
use App\Models\AnalysisJob;
use App\Models\Project;
use App\Models\Report;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class ReportController extends Controller
{
    public function store(
        Project $project,
        AnalysisJob $analysisJob,
        GenerateHtmlReportForAnalysisJobAction $action,
    ): RedirectResponse {
        $result = $action->execute($project, $analysisJob);

        return redirect()
            ->route('projects.reports.show', [$project, $result['report']])
            ->with('success', $result['created'] ? 'Report generated.' : 'Opening the existing report.');
    }

    public function show(Project $project, Report $report): View
    {
        $report->loadMissing('analysisJob.dataFile');
        abort_if(
            $report->analysisJob === null
            || $report->analysisJob->dataFile === null
            || $report->analysisJob->dataFile->project_id !== $project->project_id,
            404,
        );

        return view('reports.show', compact('project', 'report'));
    }
}
