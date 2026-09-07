# Analysis History & Status UX v1

## 1. Document Status

- Status: Designed
- Design date: 2026-09-07
- Repository reference: `2b83e49d2d324444a24e923f7f0ed5a0ba38d1f3`
- Base branch: `feature/analysis-job-module`
- Roadmap source: `docs/product/REPORTFLOW_BUSINESS_V1_ROADMAP.md`
- Intended implementation branch: `feature/analysis-history-v1`
- Purpose: make existing AnalysisJobs rediscoverable and their current business status understandable without changing analysis execution or adding AI calls.

This document defines an implementation contract. It does not claim that the feature is implemented, tested, or Product Validated.

## 2. Problem Statement

The application creates an AnalysisJob and redirects to its detail page, but the current committed routes and views do not provide a dedicated AnalysisJob history page.

A user who leaves the detail URL has no direct product path for finding that AnalysisJob again. This weakens several already-implemented capabilities:

- Pending and Processing work cannot be monitored from one place.
- AwaitingMappingConfirmation work is easy to lose.
- Completed results are difficult to rediscover.
- Failed work is not visible as a project-level operational state.
- Future recovery and report features have no history surface from which to begin.

`docs/product/ANALYSIS_JOB_MODULE.md` already describes listing AnalysisJobs as intended module scope. This design closes the implementation gap; it does not introduce a new analysis domain.

## 3. Goals

Analysis History v1 must:

1. Provide one project-scoped, paginated AnalysisJob history page.
2. Include AnalysisJobs across all non-deleted DataFiles in that Project.
3. Present a deterministic newest-first order.
4. Display enough identity and status information to find a prior analysis.
5. Link every row to the existing AnalysisJob detail page.
6. Give AwaitingMappingConfirmation rows a direct mapping-review action.
7. Add clear navigation from existing Project, DataFile, and AnalysisJob screens.
8. Reuse one canonical status-label and badge-style mapping.
9. Add no AI or queue activity.
10. Preserve current behavior for creating, processing, mapping, and viewing an analysis.

## 4. Non-goals

Analysis History v1 must not add:

- automatic queue retry;
- user-triggered retry or re-dispatch;
- attempt history;
- analysis deletion or restoration;
- report generation;
- PDF export;
- authentication or authorization;
- status mutation from the history page;
- bulk actions;
- sorting controls;
- search;
- status, template, date, or DataFile filters;
- polling or automatic page refresh;
- provider calls;
- new database tables or columns.

Filtering and automatic refresh may be considered after the basic history path is Product Validated. The existing AnalysisJob detail page remains the live-refresh surface for Pending and Processing jobs.

## 5. Confirmed Current Boundaries

At the repository reference above:

- `routes/web.php` has create, store, show, mapping edit, and mapping update routes, but no AnalysisJob index route.
- `AnalysisJobController` has no index action.
- `app/Queries/` has no AnalysisJob list query.
- `DataFile::analysisJobs()` exists.
- `AnalysisJob::dataFile()` exists.
- Project ownership is currently derived through `AnalysisJob → DataFile → Project`; `analysis_jobs` does not directly store `project_id`.
- `AnalysisJobStatus` contains Pending, Processing, Completed, Failed, and AwaitingMappingConfirmation.
- The current detail Blade defines status labels locally and generates a badge class from the enum case name.
- The shared layout has styles for pending, processing, completed, and failed, but no effective shared style for AwaitingMappingConfirmation.
- Project and DataFile pages do not link to AnalysisJob history.
- Authentication and owner isolation are not implemented and remain a separate deployment gate.

## 6. User Flow

### 6.1 Entry from Projects

Each Project row exposes:

- Data Files
- Analysis History
- Edit

Analysis History is readable for both Active and Archived Projects. Archiving prevents new work but must not hide prior history.

### 6.2 Entry from Data Files

The Data Files page header exposes an Analysis History action for the current Project.

The per-DataFile row keeps the existing Analyze action. Analysis History v1 does not add a separate per-file history page or a row-level history filter.

### 6.3 History page

The page shows all visible AnalysisJobs for the Project, newest first.

Every row provides View Details.

When the status is AwaitingMappingConfirmation, the row also provides Review Mapping. This action points to the existing mapping edit route and does not mutate state by itself.

Pending and Processing rows explain that their detail pages refresh automatically. The history page itself does not poll.

### 6.4 Return navigation

The AnalysisJob detail page exposes:

- Back to Analysis History
- Data Files

The mapping flow continues to return to the AnalysisJob detail page according to its existing contract.

## 7. Route Contract

Add:

```php
Route::get('/projects/{project}/analysis-jobs', [AnalysisJobController::class, 'index'])
    ->name('projects.analysis-jobs.index');
```

Canonical route:

```text
GET /projects/{project}/analysis-jobs
projects.analysis-jobs.index
```

No DataFile route parameter is used because this page is the Project-wide operational history.

The existing routes remain unchanged:

- `projects.analysis-jobs.show`
- `projects.analysis-jobs.mapping.edit`
- `projects.analysis-jobs.mapping.update`
- `projects.data-files.analysis-jobs.create`
- `projects.data-files.analysis-jobs.store`

## 8. Query Contract

Create:

```text
app/Queries/AnalysisJob/ListAnalysisJobsQuery.php
```

Suggested signature:

```php
public function execute(Project $project): LengthAwarePaginator
```

Required behavior:

- Query `AnalysisJob` through its `dataFile` relationship.
- Restrict results to `data_files.project_id = $project->project_id`.
- Eager-load `dataFile`.
- Exclude soft-deleted AnalysisJobs through the default model scope.
- Exclude jobs whose DataFile is soft-deleted through the relationship scope.
- Order by `analysis_jobs.created_at DESC`.
- Add `analysis_jobs.analysis_job_id DESC` as a deterministic tie-breaker.
- Paginate at 20 rows per page.
- Do not load `AnalysisJobDetail`, raw responses, normalized result JSON, EvaluationFacts, DiagnosisResults, PriorityResults, or ActionProposals.

The query must avoid N+1 access to `dataFile.original_name`.

A direct join is permitted only if it preserves model hydration, soft-delete behavior, and eager-loading expectations. The preferred implementation is an Eloquent query using `whereHas('dataFile', ...)` plus `with('dataFile')`.

## 9. Controller Contract

Add an `index` method to `AnalysisJobController`.

Suggested responsibility:

```php
public function index(
    Project $project,
    ListAnalysisJobsQuery $query,
): View
```

The method must:

- call the query with the route-bound Project;
- return `analysis-jobs.index`;
- provide `project` and `analysisJobs`;
- contain no status transition, queue dispatch, AI call, retry, report, or recovery logic.

No FormRequest is needed because v1 accepts no filters or mutations.

## 10. Canonical Status Presentation

The history page and existing detail page must not maintain separate status maps.

Extend `AnalysisJobStatus` with presentation methods or introduce one small dedicated presenter. The preferred minimal implementation is:

```php
public function label(): string
public function badgeClass(): string
```

Required mapping:

| Enum case | Label | Badge class |
| --- | --- | --- |
| Pending | Pending | `badge-pending` |
| Processing | Processing | `badge-processing` |
| AwaitingMappingConfirmation | Mapping confirmation required | `badge-awaiting-mapping-confirmation` |
| Completed | Completed | `badge-completed` |
| Failed | Failed | `badge-failed` |

Add shared CSS for `badge-awaiting-mapping-confirmation`. It must be visually distinguishable from Failed because user confirmation is not a technical failure.

Refactor `analysis-jobs/show.blade.php` to use the same canonical mapping. This is part of the feature because leaving the current local map would preserve duplicated and inconsistent presentation behavior.

Do not compare status with raw integers.

## 11. View Contract

Create:

```text
resources/views/analysis-jobs/index.blade.php
```

Page title:

```text
Analysis History - {Project name}
```

Header actions:

- Data Files
- Back to Projects

The page identifies the current Project and displays one paginated table.

Required columns:

| Column | Source / behavior |
| --- | --- |
| Analysis | `analysis_jobs.title` |
| Data File | eager-loaded `dataFile.original_name` |
| Mode | configured template name, template-key fallback, or `Free Analysis` |
| Status | canonical enum label and badge class |
| Created At | `created_at`, formatted consistently with current UI |
| Updated At | `updated_at`, formatted consistently with current UI |
| Actions | View Details; plus Review Mapping only when applicable |

Mode resolution:

1. If `template_key === null`, display `Free Analysis`.
2. If the configured template exists, display its configured `name`.
3. If the configuration key no longer exists, display the stored `template_key`; do not fail rendering and do not relabel it as Free Analysis.

Action rules:

- View Details is always present.
- Review Mapping appears only for AwaitingMappingConfirmation.
- No action may dispatch a queue job or mutate the AnalysisJob.
- Failed rows show View Details only. No Retry control is allowed in v1.

### 11.1 Empty state

When the Project has no visible AnalysisJobs:

```text
No analyses have been created for this project yet.
```

The page provides a link to Data Files so the user can choose a file and start an analysis. It must not show an Analyze link without a specific DataFile.

### 11.2 Pagination

Render Laravel pagination links below the table.

The query defines the page size. The Blade template must not sort or slice the collection.

### 11.3 Pending and Processing guidance

The page may show a short non-blocking hint:

```text
Open an analysis to view live status updates.
```

Do not add a meta refresh, JavaScript polling, or background request in v1.

## 12. Navigation Changes

### `resources/views/projects/index.blade.php`

Add an Analysis History link for every Project, including Archived Projects.

### `resources/views/data-files/index.blade.php`

Add an Analysis History link in the page header. Preserve Back to Projects.

### `resources/views/analysis-jobs/show.blade.php`

Replace the single return action with links to:

- Analysis History
- Data Files

Do not remove the existing status-specific content or refresh behavior.

### Other views

No navigation change is required in the create or mapping forms unless an implementation detail makes the current return path incorrect. Any additional change must be reported and justified.

## 13. Scoping and Security Boundary

The list query must be scoped through the route-bound Project. A job belonging to another Project must never appear.

This is domain scoping, not authentication or authorization.

Analysis History v1 must not claim multi-user isolation. Before shared/public deployment, Project ownership and authorization policies remain mandatory under the Business v1 roadmap.

Do not add placeholder ownership checks that imply security without an authenticated owner model.

## 14. Performance Boundary

The history page must:

- execute a bounded paginated query;
- eager-load DataFile;
- avoid loading AnalysisJobDetail and downstream result records;
- avoid per-row database queries;
- avoid reading CSV files;
- perform zero AI calls;
- dispatch zero jobs.

No database migration or new index is required for this v1 implementation. If profiling later shows that Project-scoped history is slow, add a separately justified index after examining the actual SQL and query plan.

## 15. Test Contract

### 15.1 Query tests

Add tests for `ListAnalysisJobsQuery` covering:

- only jobs belonging to the requested Project;
- jobs across multiple DataFiles in the same Project;
- newest-first ordering;
- `analysis_job_id DESC` tie-break ordering when timestamps match;
- pagination at 20 rows;
- soft-deleted AnalysisJobs excluded;
- jobs under soft-deleted DataFiles excluded;
- DataFile eager loading.

### 15.2 Feature/controller tests

Add tests covering:

- the Project history route returns 200;
- Project name and empty-state text are visible;
- jobs from the Project are visible;
- jobs from another Project are absent;
- title, DataFile, mode, status, and timestamps are rendered;
- free analysis mode is rendered;
- configured template name is rendered;
- unknown stored template key falls back safely;
- all five status labels are rendered correctly;
- View Details is present for every status;
- Review Mapping appears only for AwaitingMappingConfirmation;
- no Retry action, queue mutation form, or dispatch control exists;
- pagination links are rendered when more than 20 jobs exist;
- Archived Project history remains readable;
- Project and DataFile pages link to Analysis History;
- AnalysisJob detail links back to Analysis History and Data Files.

### 15.3 Status presentation tests

Add focused tests for the canonical `AnalysisJobStatus` label and badge-class mapping.

### 15.4 Zero-side-effect tests

For the GET history request:

- use `Queue::fake()`;
- assert no queue job is pushed;
- assert database record counts and statuses remain unchanged.

An HTTP fake is not required because the code path must have no provider dependency. If an HTTP fake is added, it must not replace architectural verification that the query/controller has no AI client dependency.

### 15.5 Regression suite

At minimum run:

- new Analysis History tests;
- existing Project controller tests;
- existing DataFile controller tests;
- existing AnalysisJob controller and mapping controller tests;
- full `php artisan test`;
- Pint on changed PHP files;
- `git diff --check`.

Test existence and test execution results must be reported separately.

## 16. Product Validation Contract

After automated tests pass, validate in Docker/MySQL and Browser without using the OpenAI API.

Required scenarios:

1. Empty Project:
   - history page loads;
   - empty state is accurate;
   - Data Files path is available.
2. Multiple DataFiles and AnalysisJobs:
   - all same-Project jobs appear;
   - another Project’s jobs do not appear;
   - order is deterministic.
3. All statuses:
   - five labels and badge styles are distinguishable;
   - AwaitingMappingConfirmation is not presented as Failed.
4. Mapping action:
   - only the waiting row exposes Review Mapping;
   - the link reaches the existing mapping screen.
5. Navigation:
   - Projects → Analysis History;
   - Data Files → Analysis History;
   - Analysis detail → Analysis History and Data Files.
6. Pagination:
   - more than 20 jobs produces a second page;
   - no row duplication occurs across page boundaries for the fixed dataset.
7. Side effects:
   - no AI request;
   - no queue dispatch;
   - no AnalysisJob status mutation.

Retain a compact validation manifest and screenshots only if the existing Product Validation convention requires them. Validation data must be synthetic.

## 17. Expected File Scope

Expected new files:

- `app/Queries/AnalysisJob/ListAnalysisJobsQuery.php`
- `resources/views/analysis-jobs/index.blade.php`
- relevant new test files under `tests/Feature/AnalysisJob/`

Expected modified files:

- `routes/web.php`
- `app/Http/Controllers/AnalysisJobController.php`
- `app/Enums/AnalysisJobStatus.php` or one dedicated presenter
- `resources/views/layouts/app.blade.php`
- `resources/views/projects/index.blade.php`
- `resources/views/data-files/index.blade.php`
- `resources/views/analysis-jobs/show.blade.php`
- relevant existing tests

Unexpected changes require an explanation before commit.

Do not modify or stage unrelated local files, including the existing local changes to:

- `docs/development/CODING_STANDARDS.md`
- `AGENTS.md`

## 18. Acceptance Criteria

The implementation is acceptable when:

- `GET /projects/{project}/analysis-jobs` lists only that Project’s visible jobs.
- Results are paginated 20 per page and ordered by created time then ID, both descending.
- The query does not load heavy AnalysisJobDetail or downstream result data.
- The page renders title, DataFile, mode, canonical status, timestamps, and correct actions.
- All five statuses use one canonical label/style definition shared with the detail page.
- AwaitingMappingConfirmation links directly to mapping review and is not presented as failure.
- Failed jobs do not expose Retry.
- Existing Project, DataFile, create, mapping, and detail flows continue to work.
- The feature performs zero AI calls and zero queue dispatches.
- No migration is added.
- Targeted and full automated tests pass.
- Docker/MySQL and Browser Product Validation pass.
- File scope, test execution, formatter execution, commit, and push are reported explicitly.

## 19. Implementation and Review Workflow

1. Merge this approved design into `feature/analysis-job-module`.
2. Create `feature/analysis-history-v1`.
3. Have Codex implement only this design and run automated tests.
4. Have Claude Code review the implementation in read-only mode using `docs/development/AI_CODE_REVIEW.md`.
5. Have Codex address accepted findings and rerun regression tests.
6. Run Docker/MySQL and Browser Product Validation with zero OpenAI calls.
7. Commit and push only the approved feature scope.
8. Merge back into `feature/analysis-job-module`.

## 20. Explicit Non-claims

This design does not claim:

- Analysis History is already implemented;
- the application has authentication or cross-user authorization;
- a Project-scoped route is a substitute for user ownership;
- history polling is required for v1;
- a failed AnalysisJob is safe to retry;
- internal Laravel Queue retry is the same as a user recovery feature;
- Report or PDF capabilities exist;
- any AI or queue work is needed to render history;
- production usage or performance has been observed.
