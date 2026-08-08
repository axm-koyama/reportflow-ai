<?php

declare(strict_types=1);

namespace Tests\Feature\Project;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Project 一覧画面を表示できる
     */
    public function test_it_displays_the_project_list_page(): void
    {
        Project::factory()->count(3)->create();

        $response = $this->get(route('projects.index'));

        $response->assertOk();
        $response->assertViewIs('projects.index');
        $response->assertViewHas('projects');
    }

    /**
     * Create 画面を表示できる
     */
    public function test_it_displays_the_create_project_page(): void
    {
        $response = $this->get(route('projects.create'));

        $response->assertOk();
        $response->assertViewIs('projects.create');
    }

    /**
     * Project を作成できる
     */
    public function test_it_creates_a_project(): void
    {
        $response = $this->post(route('projects.store'), [
            'name' => 'New Project',
            'description' => 'A brand new project',
        ]);

        $response->assertRedirect(route('projects.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('projects', [
            'name' => 'New Project',
            'status' => ProjectStatus::Active->value,
        ]);
    }

    /**
     * Edit 画面を表示できる
     */
    public function test_it_displays_the_edit_project_page(): void
    {
        $project = Project::factory()->create();

        $response = $this->get(route('projects.edit', $project));

        $response->assertOk();
        $response->assertViewIs('projects.edit');
        $response->assertViewHas('project', $project);
    }

    /**
     * Project を更新できる（status を archived に更新できることを含む）
     */
    public function test_it_updates_a_project(): void
    {
        $project = Project::factory()->create([
            'name' => 'Old Name',
            'status' => ProjectStatus::Active,
        ]);

        $response = $this->put(route('projects.update', $project), [
            'name' => 'Updated Name',
            'status' => ProjectStatus::Archived->value,
        ]);

        $response->assertRedirect(route('projects.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('projects', [
            'project_id' => $project->project_id,
            'name' => 'Updated Name',
            'status' => ProjectStatus::Archived->value,
        ]);
    }

    /**
     * Validation Error が表示される（name が未入力のまま作成）
     */
    public function test_it_shows_validation_errors_when_name_is_missing_on_store(): void
    {
        $response = $this->post(route('projects.store'), [
            'description' => 'No name provided',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('name');
    }

    /**
     * Validation Error が表示される（不正な status で更新）
     */
    public function test_it_shows_validation_errors_when_status_is_invalid_on_update(): void
    {
        $project = Project::factory()->create();

        $response = $this->put(route('projects.update', $project), [
            'name' => $project->name,
            'status' => 'invalid-status',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('status');
    }

    /**
     * SoftDeleted Project が一覧に表示されない
     */
    public function test_soft_deleted_projects_are_not_listed(): void
    {
        $visible = Project::factory()->create();
        $deleted = Project::factory()->create();
        $deleted->delete();

        $response = $this->get(route('projects.index'));

        $response->assertOk();
        $response->assertSee($visible->name);
        $response->assertDontSee($deleted->name);
    }
}
