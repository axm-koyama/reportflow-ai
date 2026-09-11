<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Models\AnalysisJob;
use App\Models\DataFile;
use App\Models\Project;
use App\Queries\AnalysisJob\ListAnalysisJobsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListAnalysisJobsQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_only_visible_jobs_across_the_requested_projects_data_files(): void
    {
        $project = Project::factory()->create();
        $otherProject = Project::factory()->create();
        $firstFile = DataFile::factory()->for($project)->create();
        $secondFile = DataFile::factory()->for($project)->create();
        $otherFile = DataFile::factory()->for($otherProject)->create();

        $first = AnalysisJob::factory()->for($firstFile)->create();
        $second = AnalysisJob::factory()->for($secondFile)->create();
        AnalysisJob::factory()->for($otherFile)->create();
        $deletedJob = AnalysisJob::factory()->for($firstFile)->create();
        $deletedJob->delete();
        $deletedFile = DataFile::factory()->for($project)->create();
        AnalysisJob::factory()->for($deletedFile)->create();
        $deletedFile->delete();

        $result = app(ListAnalysisJobsQuery::class)->execute($project);

        $this->assertEqualsCanonicalizing(
            [$first->analysis_job_id, $second->analysis_job_id],
            $result->getCollection()->pluck('analysis_job_id')->all(),
        );
        $this->assertTrue($result->getCollection()->every(
            fn (AnalysisJob $job): bool => $job->relationLoaded('dataFile'),
        ));
        $this->assertTrue($result->getCollection()->every(
            fn (AnalysisJob $job): bool => $job->relationLoaded('recoveredFrom')
                && $job->relationLoaded('recoveryAttempt'),
        ));
        $this->assertTrue($result->getCollection()->every(
            fn (AnalysisJob $job): bool => ! $job->relationLoaded('analysisJobDetail')
                && ! $job->relationLoaded('evaluationFacts')
                && ! $job->relationLoaded('actionProposals'),
        ));
    }

    public function test_it_orders_by_created_at_then_id_descending(): void
    {
        $project = Project::factory()->create();
        $dataFile = DataFile::factory()->for($project)->create();
        $older = AnalysisJob::factory()->for($dataFile)->create(['created_at' => Carbon::parse('2026-09-01 10:00:00')]);
        $sameTimeLowerId = AnalysisJob::factory()->for($dataFile)->create(['created_at' => Carbon::parse('2026-09-02 10:00:00')]);
        $sameTimeHigherId = AnalysisJob::factory()->for($dataFile)->create(['created_at' => Carbon::parse('2026-09-02 10:00:00')]);

        $ids = app(ListAnalysisJobsQuery::class)
            ->execute($project)
            ->getCollection()
            ->pluck('analysis_job_id')
            ->all();

        $this->assertSame([
            $sameTimeHigherId->analysis_job_id,
            $sameTimeLowerId->analysis_job_id,
            $older->analysis_job_id,
        ], $ids);
    }

    public function test_lineage_display_access_does_not_issue_per_row_queries(): void
    {
        $project = Project::factory()->create();
        $dataFile = DataFile::factory()->for($project)->create();

        for ($index = 0; $index < 3; $index++) {
            $source = AnalysisJob::factory()->for($dataFile)->create();
            AnalysisJob::factory()->for($dataFile)->create([
                'recovered_from_analysis_job_id' => $source->analysis_job_id,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $jobs = app(ListAnalysisJobsQuery::class)->execute($project)->getCollection();
        $queryCountAfterLoad = count(DB::getQueryLog());

        foreach ($jobs as $job) {
            $job->dataFile?->original_name;
            $job->recoveredFrom?->analysis_job_id;
            $job->recoveryAttempt?->analysis_job_id;
        }

        $this->assertSame($queryCountAfterLoad, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_it_paginates_without_duplicates_or_gaps_and_preserves_order_across_pages(): void
    {
        $project = Project::factory()->create();
        $dataFile = DataFile::factory()->for($project)->create();
        $jobs = collect();

        for ($index = 0; $index < 23; $index++) {
            $jobs->push(AnalysisJob::factory()->for($dataFile)->create([
                'created_at' => Carbon::parse('2026-09-07 12:00:00')->subMinutes(intdiv($index, 2)),
            ]));
        }

        $expectedIds = $jobs
            ->sort(function (AnalysisJob $left, AnalysisJob $right): int {
                $createdComparison = $right->created_at <=> $left->created_at;

                return $createdComparison !== 0
                    ? $createdComparison
                    : $right->analysis_job_id <=> $left->analysis_job_id;
            })
            ->pluck('analysis_job_id')
            ->values()
            ->all();

        $originalQuery = request()->query->all();

        try {
            request()->query->set('page', 1);
            $firstPage = app(ListAnalysisJobsQuery::class)->execute($project);
            request()->query->set('page', 2);
            $secondPage = app(ListAnalysisJobsQuery::class)->execute($project);
        } finally {
            request()->query->replace($originalQuery);
        }

        $firstPageIds = $firstPage->getCollection()->pluck('analysis_job_id')->all();
        $secondPageIds = $secondPage->getCollection()->pluck('analysis_job_id')->all();
        $allPageIds = [...$firstPageIds, ...$secondPageIds];

        $this->assertSame(20, $firstPage->perPage());
        $this->assertCount(20, $firstPageIds);
        $this->assertCount(3, $secondPageIds);
        $this->assertSame([], array_values(array_intersect($firstPageIds, $secondPageIds)));
        $this->assertEqualsCanonicalizing($expectedIds, $allPageIds);
        $this->assertSame($expectedIds, $allPageIds);
        $this->assertSame(23, $firstPage->total());
        $this->assertSame(2, $firstPage->lastPage());
    }
}
