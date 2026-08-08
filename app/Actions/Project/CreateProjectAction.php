<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Enums\ProjectStatus;
use App\Models\Project;

class CreateProjectAction
{
    /**
     * Create a new project.
     *
     * The project status is always set to ProjectStatus::Active,
     * regardless of any status value present in the given attributes.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function execute(array $attributes): Project
    {
        return Project::create([
            ...$attributes,
            'status' => ProjectStatus::Active,
        ]);
    }
}
