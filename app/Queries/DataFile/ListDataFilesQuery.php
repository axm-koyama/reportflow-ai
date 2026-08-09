<?php

declare(strict_types=1);

namespace App\Queries\DataFile;

use App\Models\DataFile;
use App\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListDataFilesQuery
{
    /**
     * The number of data files to return per page.
     */
    private const int PER_PAGE = 20;

    /**
     * List data files belonging to the given project, ordered by newest first.
     *
     * Soft-deleted data files are excluded via the default SoftDeletes scope.
     *
     * @return LengthAwarePaginator<int, DataFile>
     */
    public function execute(Project $project): LengthAwarePaginator
    {
        return $project->dataFiles()
            ->orderByDesc('created_at')
            ->orderByDesc('data_file_id')
            ->paginate(self::PER_PAGE);
    }
}
