<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Project\CreateProjectAction;
use App\Actions\Project\UpdateProjectAction;
use App\Enums\ProjectStatus;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Models\Project;
use App\Queries\Project\ListProjectsQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class ProjectController extends Controller
{
    /**
     * Constructor for the ProjectController.
     * 
     * @param ListProjectsQuery   $listProjectsQuery
     * @param CreateProjectAction $createProjectAction
     * @param UpdateProjectAction $updateProjectAction
     */
    public function __construct(
        private readonly ListProjectsQuery $listProjectsQuery,
        private readonly CreateProjectAction $createProjectAction,
        private readonly UpdateProjectAction $updateProjectAction,
    ) {}

    /**
     * Display a paginated listing of projects.
     */
    public function index(): View
    {
        $projects = $this->listProjectsQuery->execute();

        return view('projects.index', [
            'projects' => $projects,
        ]);
    }

    /**
     * Show the form for creating a new project.
     */
    public function create(): View
    {
        return view('projects.create');
    }

    /**
     * Store a newly created project.
     */
    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $this->createProjectAction->execute($request->validated());

        return redirect()
            ->route('projects.index')
            ->with('success', 'Project created successfully.');
    }

    /**
     * Show the form for editing the specified project.
     */
    public function edit(Project $project): View
    {
        return view('projects.edit', [
            'project' => $project,
            'statusOptions' => ProjectStatus::cases(),
        ]);
    }

    /**
     * Update the specified project.
     */
    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $this->updateProjectAction->execute($project, $request->validated());

        return redirect()
            ->route('projects.index')
            ->with('success', 'Project updated successfully.');
    }
}
