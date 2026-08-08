<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Models\Project;

class UpdateProjectAction
{
    /**
     * Update an existing project with the given attributes.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Project $project, array $attributes): Project
    {
        $project->fill($attributes);
        $project->save();

        return $project;
    }
}
