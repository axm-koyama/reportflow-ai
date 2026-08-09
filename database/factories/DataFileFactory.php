<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DataFile;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DataFile>
 */
class DataFileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $baseName = Str::slug(fake()->words(2, true));

        return [
            'project_id' => Project::factory(),
            'original_name' => "{$baseName}.csv",
            'stored_path' => 'projects/data-files/'.fake()->uuid().'.csv',
            'mime_type' => 'text/csv',
            'size' => fake()->numberBetween(1024, 9 * 1024 * 1024),
        ];
    }
}
