<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AnalysisJob\CreateAnalysisJobAction;
use App\Enums\ProjectStatus;
use App\Http\Requests\AnalysisJob\CreateAnalysisJobRequest;
use App\Models\AnalysisJob;
use App\Models\DataFile;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class AnalysisJobController extends Controller
{
    /**
     * Display the form for creating a new analysis job.
     */
    public function create(Project $project, DataFile $dataFile): View
    {
        $this->ensureDataFileBelongsToProject($project, $dataFile);
        $this->ensureProjectIsActive($project);

        return view('analysis-jobs.create', compact('project', 'dataFile'));
    }

    /**
     * Store a new analysis job.
     */
    public function store(
        CreateAnalysisJobRequest $request,
        Project $project,
        DataFile $dataFile,
        CreateAnalysisJobAction $action,
    ): RedirectResponse {
        $this->ensureDataFileBelongsToProject($project, $dataFile);
        $this->ensureProjectIsActive($project);

        $analysisJob = $action->execute($dataFile, $request->title(), $request->prompt());

        return redirect()->route('projects.analysis-jobs.show', [$project, $analysisJob]);
    }

    /**
     * Display the details of an analysis job.
     */
    public function show(Project $project, AnalysisJob $analysisJob): View
    {
        $analysisJob->loadMissing(['dataFile', 'analysisJobDetail']);

        $this->ensureDataFileBelongsToProject($project, $analysisJob->dataFile);

        return view('analysis-jobs.show', compact('project', 'analysisJob'));
    }

    /**
     * Guard against a DataFile that belongs to a different Project, or that
     * no longer exists (e.g. it was soft-deleted after the AnalysisJob
     * referencing it was created).
     */
    private function ensureDataFileBelongsToProject(Project $project, ?DataFile $dataFile): void
    {
        abort_if($dataFile === null || $dataFile->project_id !== $project->project_id, 404);
    }

    /**
     * Analysis jobs may only be created for an active Project.
     *
     * The "Analyze" link is already hidden in data-files/index.blade.php
     * for non-active projects, so this is a defense-in-depth check reached
     * only via a direct GET/POST (e.g. a stale tab, or the project being
     * archived between page load and submit). ValidationException is safe
     * to throw here even for the GET create() action: Laravel redirects
     * back (typically to the data-files index) with the error flashed to
     * the session, and that page renders $errors->any(). See
     * AnalysisJobControllerTest::test_create_is_rejected_for_an_archived_project().
     */
    private function ensureProjectIsActive(Project $project): void
    {
        if ($project->status !== ProjectStatus::Active) {
            throw ValidationException::withMessages([
                'project' => ['Analysis jobs can only be created for an active project.'],
            ]);
        }
    }
}
