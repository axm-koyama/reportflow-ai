<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use App\Models\DataFile;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class UiPolishTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_to_the_product_projects_entry(): void
    {
        $this->get('/')->assertRedirect(route('projects.index'));
    }

    public function test_product_layout_has_primary_navigation_and_accessible_empty_state(): void
    {
        $this->get(route('projects.index'))
            ->assertOk()
            ->assertSee('ReportFlow AI')
            ->assertSee('aria-label="Primary navigation"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('No projects yet.')
            ->assertSee(route('projects.create'), false);
    }

    public function test_projects_navigation_is_current_only_on_the_projects_index(): void
    {
        $project = Project::factory()->create();
        $dataFile = DataFile::factory()->for($project)->create();
        $analysisJob = AnalysisJob::factory()->for($dataFile)->create();
        AnalysisJobDetail::factory()->for($analysisJob)->create();

        $this->get(route('projects.analysis-jobs.show', [$project, $analysisJob]))
            ->assertOk()
            ->assertSee('class="nav-link"', false)
            ->assertDontSee('class="nav-link" aria-current="page"', false);
    }

    public function test_breadcrumb_marks_only_the_last_non_link_item_as_current(): void
    {
        $html = Blade::render(
            '<x-breadcrumb :items="$items" />',
            ['items' => [
                ['label' => 'Projects', 'href' => '/projects'],
                ['label' => 'Intermediate'],
                ['label' => 'Analysis', 'href' => '/analysis'],
                ['label' => 'Current'],
            ]],
        );

        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('/<span\s*>Intermediate<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<span\s+aria-current="page"\s*>Current<\/span>/', $html);
        $this->assertStringNotContainsString('<a aria-current=', $html);
    }
}
