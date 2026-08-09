<?php

declare(strict_types=1);

namespace Tests\Feature\DataFile;

use App\Enums\ProjectStatus;
use App\Models\DataFile;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DataFileControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. DataFile 一覧画面を表示できる
     */
    public function test_it_displays_the_data_files_list_page(): void
    {
        $project = Project::factory()->create([
            'name' => 'My Project',
            'status' => ProjectStatus::Active,
        ]);

        $response = $this->get(route('projects.data-files.index', $project));

        $response->assertOk();
        $response->assertViewIs('data-files.index');
        $response->assertSee('My Project');
    }

    /**
     * 2. Project に所属する DataFile のみ表示される
     */
    public function test_it_only_shows_data_files_belonging_to_the_project(): void
    {
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();

        DataFile::factory()->for($projectA)->create(['original_name' => 'a-file.csv']);
        DataFile::factory()->for($projectB)->create(['original_name' => 'b-file.csv']);

        $response = $this->get(route('projects.data-files.index', $projectA));

        $response->assertOk();
        $response->assertSee('a-file.csv');
        $response->assertDontSee('b-file.csv');
    }

    /**
     * 3. SoftDeleted DataFile が一覧に表示されない
     */
    public function test_soft_deleted_data_files_are_not_listed(): void
    {
        $project = Project::factory()->create();

        DataFile::factory()->for($project)->create(['original_name' => 'visible.csv']);
        $deleted = DataFile::factory()->for($project)->create(['original_name' => 'deleted.csv']);
        $deleted->delete();

        $response = $this->get(route('projects.data-files.index', $project));

        $response->assertOk();
        $response->assertSee('visible.csv');
        $response->assertDontSee('deleted.csv');
    }

    /**
     * 4. 正常な CSV を upload できる
     */
    public function test_it_uploads_a_valid_csv_file(): void
    {
        Storage::fake('local');

        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $file = UploadedFile::fake()
            ->createWithContent('contacts.csv', "name,email\nAlice,alice@example.com\n")
            ->mimeType('text/csv');

        $response = $this->post(route('projects.data-files.store', $project), [
            'file' => $file,
        ]);

        $response->assertRedirect(route('projects.data-files.index', $project));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('data_files', [
            'project_id' => $project->project_id,
            'original_name' => 'contacts.csv',
            'mime_type' => 'text/csv',
        ]);

        $dataFile = DataFile::query()->where('project_id', $project->project_id)->firstOrFail();
        Storage::disk('local')->assertExists($dataFile->stored_path);
    }

    /**
     * 5. original_name が保存される
     */
    public function test_original_name_is_preserved_on_upload(): void
    {
        Storage::fake('local');

        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $file = UploadedFile::fake()
            ->createWithContent('customer-list.csv', "id,name\n1,Alice\n")
            ->mimeType('text/csv');

        $this->post(route('projects.data-files.store', $project), ['file' => $file]);

        $this->assertDatabaseHas('data_files', [
            'project_id' => $project->project_id,
            'original_name' => 'customer-list.csv',
        ]);
    }

    /**
     * 6. stored_path が application-generated path になる（original filename を直接使用しない）
     */
    public function test_stored_path_is_application_generated_and_not_the_original_file_name(): void
    {
        Storage::fake('local');

        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $file = UploadedFile::fake()
            ->createWithContent('sensitive-name.csv', "a,b\n1,2\n")
            ->mimeType('text/csv');

        $this->post(route('projects.data-files.store', $project), ['file' => $file]);

        $dataFile = DataFile::query()->where('project_id', $project->project_id)->firstOrFail();

        $this->assertStringStartsWith("projects/{$project->project_id}/data-files/", $dataFile->stored_path);
        $this->assertStringNotContainsString('sensitive-name', $dataFile->stored_path);
        Storage::disk('local')->assertExists($dataFile->stored_path);
    }

    /**
     * 7. 10MB を超える file を upload できない
     */
    public function test_it_rejects_files_over_10mb(): void
    {
        Storage::fake('local');

        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $file = UploadedFile::fake()->create('large.csv', 11 * 1024, 'text/csv');

        $response = $this->post(route('projects.data-files.store', $project), ['file' => $file]);

        $response->assertSessionHasErrors('file');
        $this->assertDatabaseMissing('data_files', ['project_id' => $project->project_id]);
        Storage::disk('local')->assertDirectoryEmpty("projects/{$project->project_id}/data-files");
    }

    /**
     * 8. .csv 以外の file を upload できない
     */
    public function test_it_rejects_non_csv_file_extensions(): void
    {
        Storage::fake('local');

        $project = Project::factory()->create(['status' => ProjectStatus::Active]);

        $invalidFiles = [
            'notes.txt' => 'text/plain',
            'data.xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];

        foreach ($invalidFiles as $filename => $mimeType) {
            $file = UploadedFile::fake()->create($filename, 10, $mimeType);

            $response = $this->post(route('projects.data-files.store', $project), ['file' => $file]);

            $response->assertSessionHasErrors('file');
        }

        $this->assertDatabaseMissing('data_files', ['project_id' => $project->project_id]);
        Storage::disk('local')->assertDirectoryEmpty("projects/{$project->project_id}/data-files");
    }

    /**
     * 9. 0 byte file を upload できない
     */
    public function test_it_rejects_zero_byte_files(): void
    {
        Storage::fake('local');

        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $file = UploadedFile::fake()->create('empty.csv', 0, 'text/csv');

        $response = $this->post(route('projects.data-files.store', $project), ['file' => $file]);

        $response->assertSessionHasErrors('file');
        $this->assertDatabaseMissing('data_files', ['project_id' => $project->project_id]);
        Storage::disk('local')->assertDirectoryEmpty("projects/{$project->project_id}/data-files");
    }

    /**
     * 10. header row がない CSV を upload できない
     */
    public function test_it_rejects_csv_without_a_header_row(): void
    {
        Storage::fake('local');

        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $file = UploadedFile::fake()
            ->createWithContent('no-header.csv', "\nAlice,alice@example.com\n")
            ->mimeType('text/csv');

        $response = $this->post(route('projects.data-files.store', $project), ['file' => $file]);

        $response->assertSessionHasErrors('file');
        $this->assertDatabaseMissing('data_files', ['project_id' => $project->project_id]);
        Storage::disk('local')->assertDirectoryEmpty("projects/{$project->project_id}/data-files");
    }

    /**
     * 11. header row が完全に空の CSV を upload できない
     */
    public function test_it_rejects_csv_with_a_completely_blank_header_row(): void
    {
        Storage::fake('local');

        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $file = UploadedFile::fake()
            ->createWithContent('blank-header.csv', ",,,\nAlice,alice@example.com\n")
            ->mimeType('text/csv');

        $response = $this->post(route('projects.data-files.store', $project), ['file' => $file]);

        $response->assertSessionHasErrors('file');
        $this->assertDatabaseMissing('data_files', ['project_id' => $project->project_id]);
        Storage::disk('local')->assertDirectoryEmpty("projects/{$project->project_id}/data-files");
    }

    /**
     * 12. archived Project には upload できない
     */
    public function test_it_rejects_upload_to_an_archived_project(): void
    {
        Storage::fake('local');

        $project = Project::factory()->create(['status' => ProjectStatus::Archived]);
        $file = UploadedFile::fake()
            ->createWithContent('data.csv', "a,b\n1,2\n")
            ->mimeType('text/csv');

        $response = $this->post(route('projects.data-files.store', $project), ['file' => $file]);

        $response->assertSessionHasErrors('file');
        $this->assertDatabaseMissing('data_files', ['project_id' => $project->project_id]);
        Storage::disk('local')->assertDirectoryEmpty("projects/{$project->project_id}/data-files");
    }

    /**
     * 13. DataFile list が created_at DESC（同時刻は data_file_id DESC）で表示される
     */
    public function test_data_files_are_listed_newest_first_with_stable_secondary_order(): void
    {
        $project = Project::factory()->create();

        DataFile::factory()->for($project)->create([
            'original_name' => 'older.csv',
            'created_at' => now()->subMinutes(2),
        ]);
        $newer = DataFile::factory()->for($project)->create([
            'original_name' => 'newer.csv',
            'created_at' => now()->subMinute(),
        ]);
        DataFile::factory()->for($project)->create([
            'original_name' => 'same-time-as-newer.csv',
            'created_at' => $newer->created_at,
        ]);

        $response = $this->get(route('projects.data-files.index', $project));

        $response->assertOk();
        $response->assertSeeInOrder(['same-time-as-newer.csv', 'newer.csv', 'older.csv']);
    }

    /**
     * 14. pagination が20件で動作する
     */
    public function test_it_paginates_data_files_at_twenty_per_page(): void
    {
        $project = Project::factory()->create();
        DataFile::factory()->for($project)->count(21)->create();

        $page1 = $this->get(route('projects.data-files.index', $project));
        $page1->assertOk();
        $paginator1 = $page1->viewData('dataFiles');
        $this->assertSame(20, $paginator1->count());
        $this->assertSame(21, $paginator1->total());
        $this->assertSame(2, $paginator1->lastPage());

        $page2 = $this->get(route('projects.data-files.index', $project).'?page=2');
        $page2->assertOk();
        $paginator2 = $page2->viewData('dataFiles');
        $this->assertSame(1, $paginator2->count());
    }
}
