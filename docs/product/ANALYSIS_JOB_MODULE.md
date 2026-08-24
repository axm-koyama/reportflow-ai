# AnalysisJob Module

## Purpose

AnalysisJob represents one AI analysis execution against a DataFile.

A single DataFile may be analyzed multiple times.

Each execution is represented by a separate AnalysisJob.

Examples:

```text
companies.csv
│
├── AnalysisJob #101
│   title: 売上傾向分析
│
├── AnalysisJob #102
│   title: 売上傾向分析
│
├── AnalysisJob #103
│   title: 地域別売上の再分析
│
└── AnalysisJob #104
    title: 顧客分類分析
```

AnalysisJob titles are user-defined and do not need to be unique.

AnalysisJob does not define a fixed analysis type.

Different companies and datasets may require completely different analysis purposes, so the Sprint 3 MVP does not introduce `analysis_type` or an analysis type master.

A reusable AnalysisTemplate may be introduced later if a concrete requirement emerges.

---

## MVP Scope

Sprint 3 provides the foundation for asynchronous AI analysis.

The MVP includes:

- Create an AnalysisJob for a DataFile
- Create an AnalysisJobDetail at the same time
- Accept a user-defined analysis title
- Accept a user-defined prompt
- Manage AnalysisJob business status
- Dispatch AI analysis through Laravel Queue
- Track pending / processing / completed / failed states
- Store the raw AI response
- Store normalized structured analysis result
- Store failure information
- Track analysis start and completion timestamps
- List AnalysisJobs belonging to a DataFile
- Display AnalysisJob status

The following are intentionally outside the initial Sprint 3 scope unless explicitly implemented later:

- HTML report generation
- PDF generation
- Excel report generation
- Analysis templates
- Multi-step AI agents
- Retry history
- Multiple LLM provider abstraction
- RAG
- Knowledge retrieval
- Cost dashboards
- Token usage reporting

---

## Domain Responsibility

### AnalysisJob

AnalysisJob represents one business-level AI analysis execution.

Its responsibilities are limited to:

- Target DataFile
- User-defined title
- Business execution status
- Logical deletion

AnalysisJob should remain lightweight so that list and status screens do not need to load large prompts or AI responses.

AnalysisJob must not contain:

- Queue payloads
- Laravel queue infrastructure fields
- Raw AI responses
- Large normalized results
- Report HTML
- PDF binary data
- Excel binary data

### AnalysisJobDetail

AnalysisJobDetail stores execution details and analysis results for exactly one AnalysisJob.

Its responsibilities include:

- Prompt
- Raw AI response
- Normalized structured result
- Error message
- Started timestamp
- Completed timestamp

AnalysisJobDetail is completely dependent on AnalysisJob.

It does not exist independently.

---

## Relationships

### DataFile

```text
DataFile
  has many
AnalysisJobs
```

A single DataFile may be analyzed many times.

### AnalysisJob

```text
AnalysisJob
  belongs to
DataFile
```

### AnalysisJobDetail

```text
AnalysisJob
  has one
AnalysisJobDetail

AnalysisJobDetail
  belongs to
AnalysisJob
```

Overall relationship:

```text
DataFile
   1
   │
   └────── N
          AnalysisJob
             1
             │
             └────── 1
                     AnalysisJobDetail
```

---

## Aggregate Boundary

DataFile is the business parent of AnalysisJob.

AnalysisJob owns AnalysisJobDetail.

```text
DataFile
   │
   └── AnalysisJob
          │
          └── AnalysisJobDetail
```

AnalysisJobDetail is not treated as an independent aggregate root.

The application should create AnalysisJob and AnalysisJobDetail in the same use case.

---

## AnalysisJob Status

AnalysisJob has a meaningful business lifecycle, so status is required.

Database values:

```text
0 = pending
1 = processing
2 = completed
3 = failed
4 = awaiting_mapping_confirmation  (Phase 3-C, Template jobs only)
```

PHP code should use a backed Enum.

Example:

```php
enum AnalysisJobStatus: int
{
    case Pending = 0;
    case Processing = 1;
    case Completed = 2;
    case Failed = 3;
    case AwaitingMappingConfirmation = 4;
}
```

Do not compare status using raw integers in business code.

> **Phase 3-C で更新**: `AwaitingMappingConfirmation`を追加した。
> `status`は`unsignedTinyInteger`でDB制約を持たないため、この追加に
> migrationは不要だった。詳細はdocs/product/MAPPING_CONTROL.mdを参照。

Expected lifecycle:

```text
pending
   ↓
processing
   ↓
completed
```

Template-based AnalysisJob lifecycle when the AI-proposed Column Mapping
cannot resolve a required field (Phase 3-C, see
docs/product/MAPPING_CONTROL.md):

```text
pending
   ↓
processing
   ↓
awaiting_mapping_confirmation
   ↓ (user confirms/overrides the mapping)
pending
   ↓
processing
   ↓
completed
```

This is not a failure flow — the AnalysisJob never becomes Failed on
account of a missing required field alone.

Failure flow:

```text
pending
   ↓
processing
   ↓
failed
```

A job may also fail before normal AI execution begins if an unrecoverable processing error occurs after creation.

The exact retry policy belongs to the queue execution design rather than the status enum itself.

---

## Title

AnalysisJob has a user-defined title.

Examples:

```text
売上傾向分析
今月の売上低下原因を分析
地域別売上比較
解約リスク顧客抽出
採用候補企業の優先順位付け
```

Rules:

- Title is required
- Title is free text
- Title does not need to be unique
- The same DataFile may have multiple AnalysisJobs with the same title
- Title is intended primarily for user-facing identification
- Title is separate from the AI prompt

The Sprint 3 MVP does not introduce `analysis_type`.

---

## Prompt

The prompt belongs to AnalysisJobDetail.

Example:

```text
CSVに含まれる店舗別・商品別・月別売上データを分析し、
前月比で大きく低下している項目を特定し、
売上低下への寄与が大きい順に説明してください。
```

Prompt is the actual instruction used for AI analysis.

Title and prompt serve different purposes:

```text
title
= User-facing analysis name

prompt
= AI execution instruction
```

---

## Queue Architecture

Laravel standard Queue infrastructure is used.

ReportFlow AI must not create a custom queue table for Sprint 3.

Laravel-managed tables:

```text
jobs
failed_jobs
```

Typical Laravel `jobs` fields include:

```text
id
queue
payload
attempts
reserved_at
available_at
created_at
```

These tables represent infrastructure-level queue execution.

They must not replace AnalysisJob business state.

Responsibility separation:

```text
jobs / failed_jobs
= Queue infrastructure

analysis_jobs
= Business execution state

analysis_job_details
= AI execution detail and result
```

A Laravel queue failure and an AnalysisJob business failure are related but not identical concerns.

When a queued analysis ultimately fails, the application should record the business failure in:

```text
analysis_jobs.status = failed
analysis_job_details.error_message = ...
```

Laravel may also record the failed queue execution in `failed_jobs`.

### Operational runbook: restart the queue worker after every code/config deploy

**A long-running `php artisan queue:work` process does not pick up code
or config changes made after it started.** PHP does not re-evaluate an
already-declared class or an already-loaded config value within the same
process — `ExecuteAnalysisJobAction` and everything it depends on
(including new files such as `PrioritizeAnalysisJobAction` and new config
such as `config/priority_rules.php`) is loaded once, the first time the
worker handles a job, and kept in memory for the rest of that process's
life.

This was discovered directly during Phase 4-C's own Browser/E2E
validation: a `queue:work` process that had been running since before the
Priority Layer was deployed silently produced **zero** `PriorityResult`
rows — no exception, no error log — because it was still executing the
pre-Phase-4-C compiled version of `ExecuteAnalysisJobAction`. The fix was
to stop that process and start a fresh one.

**Rule**: after deploying any code or config change, restart every queue
worker process before relying on it to process new jobs:

```bash
php artisan queue:restart
```

`queue:restart` signals every worker to exit after finishing its current
job; something must actually bring a new worker process back up
afterward (a process supervisor such as `supervisord`, or a manual
restart of `php artisan queue:work`) — `queue:restart` alone does not
relaunch one. In this project's local `docker compose` environment (no
supervisor configured as of Phase 4-C), that means explicitly killing and
restarting the `queue:work` process inside the `app` container after
every deploy, not just running `queue:restart` and assuming it recovers
on its own.

---

## Asynchronous Flow

Expected execution flow:

```text
Browser
   │
   ▼
Create AnalysisJob
   │
   ├── analysis_jobs.status = pending
   └── create AnalysisJobDetail with prompt
   │
   ▼
Dispatch Laravel Queue Job
   │
   ▼
Queue Worker
   │
   ▼
analysis_jobs.status = processing
analysis_job_details.started_at = now
   │
   ▼
AI Analysis
   │
   ▼
Save raw_response
   │
   ▼
Normalize result
   │
   ▼
Save result JSON
   │
   ▼
analysis_jobs.status = completed
analysis_job_details.completed_at = now
```

Failure flow:

```text
Queue Worker
   │
   ▼
processing
   │
   ▼
Execution Failure
   │
   ├── analysis_jobs.status = failed
   ├── analysis_job_details.error_message = ...
   └── analysis_job_details.completed_at = now
```

---

## Creation Timing

AnalysisJob and AnalysisJobDetail are created together before queue dispatch.

Reason:

- The user-defined title must be persisted immediately
- The prompt must be persisted before asynchronous execution
- The analysis request must remain traceable even if queue dispatch fails
- The queue payload should reference an existing AnalysisJob rather than contain all business data

Expected flow:

```text
CreateAnalysisJobAction
   │
   ├── Create AnalysisJob
   ├── Create AnalysisJobDetail
   └── Dispatch Queue Job
```

AnalysisJobDetail should not be created only when the queue worker starts.

---

## Migration Design

### analysis_jobs

Columns:

```text
analysis_job_id
data_file_id
title
status
created_at
updated_at
deleted_at
```

Recommended migration:

```php
Schema::create('analysis_jobs', function (Blueprint $table) {
    $table->comment('AI analysis jobs for uploaded data files.');

    $table->id('analysis_job_id');

    $table->foreignId('data_file_id')
        ->constrained('data_files', 'data_file_id')
        ->restrictOnDelete()
        ->comment('Target data file ID');

    $table->string('title', 255)
        ->comment('User-defined analysis title');

    $table->unsignedTinyInteger('status')
        ->default(0)
        ->comment('0: pending, 1: processing, 2: completed, 3: failed');

    $table->timestamps();
    $table->softDeletes();
});
```

No `analysis_type` column is included.

No queue infrastructure fields are included.

---

## AnalysisJobDetail Migration Design

### analysis_job_details

Columns:

```text
analysis_job_id
prompt
raw_response
result
error_message
started_at
completed_at
created_at
updated_at
```

`analysis_job_id` is both:

- Primary key
- Foreign key

This enforces a strict one-to-one relationship.

Recommended migration:

```php
Schema::create('analysis_job_details', function (Blueprint $table) {
    $table->comment('Execution details and AI results for analysis jobs.');

    $table->foreignId('analysis_job_id')
        ->primary()
        ->constrained('analysis_jobs', 'analysis_job_id')
        ->cascadeOnDelete()
        ->comment('Analysis job ID');

    $table->longText('prompt')
        ->comment('Prompt sent for AI analysis');

    $table->longText('raw_response')
        ->nullable()
        ->comment('Raw AI response for audit and reprocessing');

    $table->json('result')
        ->nullable()
        ->comment('Normalized structured analysis result');

    $table->text('error_message')
        ->nullable()
        ->comment('Failure message');

    $table->timestamp('started_at')
        ->nullable()
        ->comment('Analysis start time');

    $table->timestamp('completed_at')
        ->nullable()
        ->comment('Analysis completion time');

    $table->timestamps();
});
```

AnalysisJobDetail does not use SoftDeletes in the initial design.

It is fully dependent on AnalysisJob.

---

## Delete Rules

Deletion behavior is intentionally different between relationships.

### DataFile → AnalysisJob

```text
DataFile
   │
   │ restrictOnDelete
   ▼
AnalysisJob
```

If AnalysisJobs exist, the DataFile cannot be force-deleted until those AnalysisJobs are explicitly handled.

Reason:

AnalysisJob is a business execution record and should not be silently removed when the DataFile is physically deleted.

### AnalysisJob → AnalysisJobDetail

```text
AnalysisJob
   │
   │ cascadeOnDelete
   ▼
AnalysisJobDetail
```

If an AnalysisJob is physically deleted, its detail is also deleted automatically.

Reason:

AnalysisJobDetail has no independent business meaning without AnalysisJob.

### Soft Delete

Normal business deletion should use SoftDeletes on AnalysisJob.

```text
AnalysisJob SoftDelete
    ↓
analysis_jobs.deleted_at updated
    ↓
AnalysisJobDetail remains
```

Foreign key delete rules apply only to physical deletion.

---

## Raw Response

`raw_response` stores the AI-generated response before application normalization.

Purpose:

- Audit
- Debugging
- Reprocessing
- Future normalization changes
- Recovery from result schema changes

`raw_response` should not be treated as the primary source for every HTML/PDF/Excel rendering request.

The normal report-generation path should use normalized `result`.

---

## Normalized Result

`result` stores application-oriented structured JSON.

Example shape:

```json
{
  "summary": "2026年7月の売上は前月比12.4%減少しました。",
  "highlights": [
    "関東エリアの売上が18.2%減少",
    "商品Aの販売数量が25.1%減少"
  ],
  "metrics": [
    {
      "label": "総売上",
      "value": 12500000,
      "unit": "JPY"
    },
    {
      "label": "前月比",
      "value": -12.4,
      "unit": "%"
    }
  ],
  "tables": [
    {
      "title": "地域別売上",
      "columns": ["地域", "売上", "前月比"],
      "rows": [
        ["関東", 5000000, -18.2],
        ["関西", 4200000, -5.3]
      ]
    }
  ],
  "insights": [
    {
      "title": "関東エリアの売上低下",
      "description": "主要商品の販売数量減少が売上低下の主因です。"
    }
  ]
}
```

The exact schema may evolve during Sprint 3 implementation.

The important rule is:

```text
raw_response
= Original AI output

result
= Application-normalized structured data
```

---

## Result Normalization

Normalization should be treated as a separate application responsibility.

Possible Action:

```text
NormalizeAnalysisResultAction
```

Conceptual interface:

```text
raw_response
      ↓
NormalizeAnalysisResultAction
      ↓
result JSON
```

The normalizer should not generate HTML, PDF, or Excel.

Those belong to the future Report module.

Do not introduce excessive result-normalization abstractions before the actual AI response format is implemented.

---

## Structured AI Response

When supported by the AI provider, the application should prefer requesting a structured response matching an expected schema.

Preferred flow:

```text
AI
   ↓
Structured response
   ↓
raw_response
   ↓
Validate / Normalize
   ↓
result
```

This is preferable to repeatedly sending free-form AI text back through AI solely to convert it into JSON.

The application should still preserve the raw response before normalization.

---

## Report Generation Boundary

AnalysisJob is responsible for analysis execution.

Report generation is a separate future module.

Expected future flow:

```text
AnalysisJobDetail.result
          │
          ▼
       Report
       ├── HTML
       ├── PDF
       └── Excel
```

HTML, PDF, and Excel output should normally be generated from normalized `result`.

They should not require AI re-execution.

If report layout changes:

```text
Existing result
      ↓
New Report Template
      ↓
Regenerate HTML / PDF / Excel
```

If the result schema changes in the future:

```text
Existing raw_response
      ↓
New normalization logic
      ↓
New result
```

This allows reprocessing without calling the AI again.

---

## Result Schema Versioning

A future result schema version concept may be useful.

Example:

```text
result_schema_version
```

However, Sprint 3 should not add this field until a real schema evolution requirement exists.

The raw response is retained to preserve the ability to normalize again later.

---

## Actions

Expected Sprint 3 write operations may include:

```text
CreateAnalysisJobAction
ExecuteAnalysisJobAction
NormalizeAnalysisResultAction
```

> **Post-Sprint-3 additions**: `ExecuteAnalysisJobAction`'s pipeline has since
> grown beyond this original list. Phase 1 added `DataProfilingAction` and
> `MetricAggregationAction` (docs/product/METRIC_AGGREGATION.md). Phase 2
> added `PlanDerivedMetricsAction` and `CalculateDerivedMetricsAction`
> (docs/product/DERIVED_METRICS.md), making a total of two AI calls per
> AnalysisJob execution attempt instead of one. Phase 3-A added
> `ResolveAnalysisTemplateAction` / `MapAnalysisTemplateColumnsAction` /
> `ValidateColumnMappingAction` and a nullable `analysis_jobs.template_key`
> column (docs/product/ANALYSIS_TEMPLATE_MODULE.md) — a Template-based
> AnalysisJob makes a third AI call (Column Mapping) before Planning; a
> free-form AnalysisJob (`template_key` null) is unaffected and still makes
> exactly two. Phase 3-C added `ResolveEffectiveColumnMappingAction` /
> `FilterRecommendedDerivedMetricsAction` / `BuildAnalysisTemplateColumnCandidatesAction`,
> a `awaiting_mapping_confirmation` AnalysisJob status, and a Mapping
> confirmation resume path — a Template-based AnalysisJob still makes
> exactly 3 AI calls total across its entire lifetime regardless of
> whether Mapping confirmation was needed, since Column Mapping is never
> called again once resolved (docs/product/MAPPING_CONTROL.md). See
> ExecuteAnalysisJobAction's class docblock for the current, authoritative
> pipeline order.
>
> **Phase 4-A addition**: `ExecuteAnalysisJobAction` now also calls
> `EvaluateAnalysisJobAction` (the Deterministic Evaluation Engine) right
> after `CalculateDerivedMetricsAction` and before
> `BuildAnalysisContextAction`, reusing the same in-memory
> `aggregated_metrics` already computed in this attempt (no extra CSV
> read/aggregation). This adds **zero** AI calls — the AI call count above
> is unaffected — and is wrapped in a soft-fail `try/catch`: a technical
> exception inside Evaluation is logged and the pipeline continues to
> Analyze/`markCompleted()` rather than failing the AnalysisJob. See
> docs/product/EVALUATION_ENGINE.md.
>
> **Phase 4-B addition**: `ExecuteAnalysisJobAction` now also calls
> `RunDiagnosisForAnalysisJobAction` (Controlled Diagnosis) right after
> `AiAnalysisClient::analyze()`/`NormalizeAnalysisResultAction` and before
> `markCompleted()`. This adds **0 to N** AI calls, where N is the number
> of Diagnosis-eligible EvaluationFacts this attempt produced (0 for Free
> Analysis, for a Template without a `config/evaluation_metrics.php`
> entry, or whenever every EvaluationFact is favorable/low/
> insufficient_data) — never a fixed +1. Each eligible EvaluationFact's
> Diagnosis attempt is independently soft-failed (per-entity, never
> propagated to Laravel Queue), so one technical failure never prevents
> another eligible fact from being diagnosed, and never fails the
> AnalysisJob. `BuildAnalysisContextAction`'s System Instruction also
> gains an additional, purely additive rule block (Rules 23-28) whenever
> this AnalysisJob is "Decision-enabled" (`template_key` has a
> `config/evaluation_metrics.php` entry) — restricting Final Analyze to
> descriptive analysis only (no causal diagnosis, no priority, no
> action recommendation). See docs/product/DIAGNOSIS_ENGINE.md.
>
> **Phase 4-C addition**: `ExecuteAnalysisJobAction` now also calls
> `PrioritizeAnalysisJobAction` (the Deterministic Priority Layer) right
> after `EvaluateAnalysisJobAction` and before `BuildAnalysisContextAction`
> — reading only the `EvaluationFact` rows Evaluation just persisted, never
> `aggregated_metrics`/`derived_metrics`/`DiagnosisResult` (which does not
> exist yet at this point in the pipeline). This adds **zero** AI calls —
> Priority is entirely Laravel-deterministic, mirroring Evaluation's own
> "AI = 一切参加しない" principle — and is wrapped in the same soft-fail
> `try/catch` shape as `EvaluateAnalysisJobAction`, including clearing any
> stale `PriorityResult` rows on a technical failure. Priority is never fed
> into `BuildAnalysisContextAction`/`analyze()` or into
> `RunDiagnosisForAnalysisJobAction`'s Evidence Package — it is surfaced
> only through its own minimal UI column. See
> docs/product/PRIORITY_ENGINE.md.

### CreateAnalysisJobAction

Responsibilities:

- Validate creation business rules
- Create AnalysisJob with `pending`
- Create AnalysisJobDetail with prompt
- Dispatch the Laravel Queue Job

AnalysisJob and AnalysisJobDetail should be created in one application use case.

Database transaction should be considered because two related database records are created together.

Queue dispatch timing should ensure the worker does not process a job before the database transaction is committed.

Laravel-supported after-commit dispatch behavior should be considered during implementation.

### ExecuteAnalysisJobAction

Responsibilities:

- Transition status to `processing`
- Set `started_at`
- Read the target DataFile
- Execute AI analysis
- Store `raw_response`
- Normalize the result
- Store normalized `result`
- Transition status to `completed`
- Set `completed_at`

On failure:

- Set status to `failed`
- Store `error_message`
- Set `completed_at`
- Re-throw when appropriate so Laravel Queue failure handling remains functional

### NormalizeAnalysisResultAction

Responsibilities:

- Accept the raw AI result
- Validate the expected structure
- Produce application-oriented result data

It must not:

- Generate reports
- Perform queue management
- Update unrelated business entities
- Call the AI again unless a future explicit requirement requires it

---

## Laravel Queue Job

The Laravel Queue Job is infrastructure that invokes the analysis application use case.

Possible job name:

```text
ExecuteAnalysisJob
```

Its responsibility should remain thin.

Expected behavior:

```text
Queue Job
   ↓
Load AnalysisJob
   ↓
ExecuteAnalysisJobAction
```

The Queue Job should not contain large amounts of AI, parsing, persistence, or business logic.

That logic belongs to Actions and dedicated validation/AI components.

---

## Failed Queue Jobs

Laravel `failed_jobs` is used for infrastructure failure tracking.

Business failure information is stored separately.

```text
Laravel failed_jobs
= Queue failure record

analysis_jobs.status
= Business status

analysis_job_details.error_message
= User/application-facing failure detail
```

Do not treat `failed_jobs` as the user-facing AnalysisJob history.

---

## Queries

Expected Sprint 3 read operation:

```text
ListAnalysisJobsQuery
```

Responsibilities:

- Retrieve AnalysisJobs belonging to one DataFile
- Exclude soft-deleted AnalysisJobs
- Order newest first
- Apply stable secondary ordering
- Apply pagination
- Keep read logic out of Controller

Possible future queries:

```text
FindAnalysisJobQuery
ListProjectAnalysisJobsQuery
```

Do not implement them until required.

---

## Controller Responsibility

The Controller should remain thin.

Expected responsibilities:

- Receive requests
- Use FormRequest validation
- Call Actions
- Call Queries
- Return Blade views or redirects

The Controller must not:

- Dispatch raw AI API calls directly
- Contain queue execution logic
- Parse AI responses
- Normalize AI results
- Perform complex Eloquent queries
- Manage report generation

---

## Validation Responsibility

### CreateAnalysisJobRequest

Expected HTTP validation:

- `title` is required
- `title` is a string
- `title` maximum length is 255
- `prompt` is a string
- `prompt` maximum length is 5000

> **Phase 3-A で更新**: `template_key` / `prompt` の実際のvalidation ruleは
> Analysis Template導入後、以下の通り。詳細は
> docs/product/ANALYSIS_TEMPLATE_MODULE.md §8を参照。

- `template_key` is nullable — omitting it (free-form analysis) is valid
- `template_key`, when present, must be a key that exists in `config('analysis_templates')` — an unknown key is rejected here (`Rule::in`), before an AnalysisJob is ever created
- `prompt` is required when `template_key` is absent (`required_without:template_key`) — this preserves free-form analysis's original "prompt is always required" behavior exactly
- `prompt` is optional when `template_key` is present — it is the user's optional additional request alongside the Template's fixed instruction; an omitted/blank prompt is stored as `''`, never `null`

The FormRequest validates HTTP input.

Business rules belong to the Action.

---

## Business Rules

- Every AnalysisJob belongs to one DataFile.
- One DataFile may have many AnalysisJobs.
- AnalysisJob title is required.
- AnalysisJob title is user-defined.
- AnalysisJob title is not unique.
- Sprint 3 does not use `analysis_type`.
- Every AnalysisJob has exactly one AnalysisJobDetail.
- AnalysisJob and AnalysisJobDetail are created together.
- A newly created AnalysisJob starts as `pending`.
- Queue processing transitions it to `processing`.
- Successful execution transitions it to `completed`.
- Failed execution transitions it to `failed`.
- Raw AI response must be preserved before or together with normalization where practical.
- Normalized result is the application-facing data source for future reports.
- Report generation must not be implemented inside AnalysisJob.
- Laravel Queue infrastructure must not be duplicated in custom queue tables.

---

## Error Handling

Creation failure:

```text
AnalysisJob creation failure
      ↓
No partial AnalysisJob / Detail pair should remain
```

Queue dispatch failure should leave enough persisted information to diagnose or retry the business request.

Execution failure:

```text
processing
   ↓
Exception
   ↓
status = failed
error_message = ...
completed_at = now
   ↓
Re-throw when needed
   ↓
Laravel Queue failure handling
```

Do not silently swallow analysis execution exceptions.

Detailed exception-to-user-message policy should remain simple for the MVP.

---

## Timestamps

### started_at

Set when asynchronous analysis execution begins.

### completed_at

Set when the analysis execution reaches a terminal state:

```text
completed
failed
```

Do not set `completed_at` while the job is pending or processing.

---

## Soft Delete

AnalysisJob uses Laravel SoftDeletes.

Normal queries exclude soft-deleted AnalysisJobs.

AnalysisJobDetail does not use SoftDeletes in the initial design.

When an AnalysisJob is only soft-deleted, the detail remains.

Physical delete uses the configured foreign key cascade.

---

## Security Considerations

For the MVP:

- AnalysisJob must only target an existing DataFile
- Queue payload should reference IDs rather than unnecessarily embedding large business data
- Prompt and AI response may contain sensitive business data and must not be exposed publicly
- Raw AI response must be treated as private application data
- Report generation must not directly expose unescaped raw responses
- Error messages shown to users should avoid leaking unnecessary infrastructure secrets
- Queue failures should remain traceable

Additional authorization and tenant isolation will be addressed when those modules are introduced.

---

## Testing

Sprint 3 should eventually include Feature and Queue-related tests covering important behavior.

Important scenarios:

- AnalysisJob and AnalysisJobDetail are created together
- Initial status is pending
- Title is free text and duplicates are allowed
- AnalysisJob belongs to the correct DataFile
- Detail has the same `analysis_job_id`
- Queue Job is dispatched
- Processing changes status to processing
- Successful execution stores raw response
- Successful execution stores normalized result
- Successful execution sets completed status
- Failure sets failed status
- Failure records error message
- Failure sets completed_at
- Soft-deleted AnalysisJobs are excluded from normal lists
- DataFile force deletion is restricted when AnalysisJobs exist
- AnalysisJob physical deletion cascades to AnalysisJobDetail

Queue tests should use Laravel's queue testing utilities where appropriate without mocking away all business behavior.

---

## Out of Scope

The following are not included in the initial Sprint 3 design:

- Analysis type master
- AnalysisTemplate
- Multiple prompts per AnalysisJob
- Multi-step agent execution
- Analysis retry history table
- Analysis run history
- AI provider abstraction
- RAG
- Knowledge base
- HTML report generation
- PDF report generation
- Excel report generation
- Report template management
- Cost dashboard
- Token usage dashboard
- Result schema version field
- Company management
- Tenant management
- Authentication changes
- Authorization / Policy changes

---

## Future Direction

Expected platform flow:

```text
Project
   │
   ▼
DataFile
   │
   ├── AnalysisJob #1
   │      │
   │      └── AnalysisJobDetail
   │
   ├── AnalysisJob #2
   │      │
   │      └── AnalysisJobDetail
   │
   └── AnalysisJob #N
          │
          └── AnalysisJobDetail
```

Execution:

```text
AnalysisJob
   │
   ▼
Laravel Queue
   │
   ▼
AI Analysis
   │
   ├── raw_response
   │
   └── result
          │
          ▼
       Report
       ├── HTML
       ├── PDF
       └── Excel
```

Future AnalysisTemplate support may be added without removing free-form analysis.

Possible future relationship:

```text
AnalysisTemplate
       │ optional
       ▼
AnalysisJob
```

A free-form AnalysisJob should remain possible even if templates are introduced later.

---

## Design Principles

- Use Laravel standard Queue infrastructure.
- Keep queue infrastructure separate from business status.
- Treat one AnalysisJob as one AI analysis execution.
- Allow one DataFile to have many AnalysisJobs.
- Allow duplicate AnalysisJob titles.
- Do not introduce `analysis_type` without a real classification requirement.
- Keep AnalysisJob lightweight.
- Keep large execution data in AnalysisJobDetail.
- Enforce AnalysisJob : AnalysisJobDetail as 1:1.
- Create AnalysisJob and AnalysisJobDetail together.
- Preserve raw AI output for audit and reprocessing.
- Use normalized result as the application-facing analysis result.
- Do not generate HTML/PDF/Excel directly from raw AI output during normal operation.
- Keep report generation separate from analysis execution.
- Prefer structured AI responses where supported.
- Avoid speculative abstractions.
- Introduce interfaces only when multiple implementations create a concrete need.
- Add complexity only when a real requirement requires it.
