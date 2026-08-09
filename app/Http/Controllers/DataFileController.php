<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DataFile\UploadDataFileAction;
use App\Enums\ProjectStatus;
use App\Http\Requests\DataFile\StoreDataFileRequest;
use App\Models\DataFile;
use App\Models\Project;
use App\Queries\DataFile\ListDataFilesQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class DataFileController extends Controller
{
    /**
     * Display a paginated listing of data files belonging to the project.
     */
    public function index(Project $project, ListDataFilesQuery $query): View
    {
        $dataFiles = $query->execute($project);

        // Presentation-only data, kept separate from the DataFile models
        // themselves so persisted attributes and display data don't mix.
        $formattedSizes = collect($dataFiles->items())
            ->mapWithKeys(fn (DataFile $dataFile): array => [
                $dataFile->data_file_id => $this->formatFileSize($dataFile->size),
            ]);

        return view('data-files.index', [
            'project' => $project,
            'dataFiles' => $dataFiles,
            'formattedSizes' => $formattedSizes,
            'canUpload' => $project->status === ProjectStatus::Active,
        ]);
    }

    /**
     * Upload a new CSV data file to the project.
     */
    public function store(
        StoreDataFileRequest $request,
        Project $project,
        UploadDataFileAction $action,
    ): RedirectResponse {
        $action->execute($project, $request->uploadedFile());

        return redirect()
            ->route('projects.data-files.index', $project)
            ->with('success', 'Data file uploaded successfully.');
    }

    /**
     * Format a byte count as a human-readable KB/MB string for display.
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1).' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }
}
