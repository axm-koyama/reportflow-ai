<?php

declare(strict_types=1);

namespace App\Queries\Project;

use App\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListProjectsQuery
{
    /**
     * The number of projects to return per page.
     */
    private const int PER_PAGE = 20;

    /**
     * List projects ordered by newest first.
     *
     * Soft-deleted projects are excluded via the default SoftDeletes scope.
     *
     * @return LengthAwarePaginator<int, Project>
     */
    public function execute(): LengthAwarePaginator
    {
        return Project::query()
            ->orderByDesc('created_at')
            ->paginate(self::PER_PAGE);
    }
}
