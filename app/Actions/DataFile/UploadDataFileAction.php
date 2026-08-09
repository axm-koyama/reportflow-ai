<?php

declare(strict_types=1);

namespace App\Actions\DataFile;

use App\Enums\ProjectStatus;
use App\Models\DataFile;
use App\Models\Project;
use App\Validators\DataFile\CsvFileValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class UploadDataFileAction
{
    public function __construct(
        private readonly CsvFileValidator $csvFileValidator,
    ) {}

    /**
     * Validate, store, and register an uploaded CSV file for the given project.
     *
     * @throws ValidationException
     * @throws RuntimeException
     */
    public function execute(Project $project, UploadedFile $file): DataFile
    {
        if ($project->status !== ProjectStatus::Active) {
            throw ValidationException::withMessages([
                'file' => ['DataFiles can only be uploaded to an active project.'],
            ]);
        }

        $this->csvFileValidator->validate($file);

        $originalName = $file->getClientOriginalName();
        $mimeType = $file->getMimeType();
        $size = $file->getSize();

        if ($mimeType === null || $size === null) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded file metadata could not be read.'],
            ]);
        }

        $directory = "projects/{$project->project_id}/data-files";
        $storedFileName = Str::uuid()->toString().'.csv';

        $storedPath = $file->storeAs($directory, $storedFileName, 'local');

        if ($storedPath === false) {
            throw new RuntimeException('Failed to store the uploaded CSV file.');
        }

        try {
            return DataFile::create([
                'project_id' => $project->project_id,
                'original_name' => $originalName,
                'stored_path' => $storedPath,
                'mime_type' => $mimeType,
                'size' => $size,
            ]);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($storedPath);

            throw $e;
        }
    }
}
