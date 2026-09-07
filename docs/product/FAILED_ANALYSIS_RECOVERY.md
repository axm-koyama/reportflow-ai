# Failed Analysis Recovery v1

## 1. Document Status

- Status: Designed
- Design date: 2026-09-07
- Repository reference: `a857694630718101b2d8f1f49d81bd0a0e402557`
- Base branch: `feature/analysis-job-module`
- Roadmap source: `docs/product/REPORTFLOW_BUSINESS_V1_ROADMAP.md`
- Intended implementation branch: `feature/failed-analysis-recovery-v1`
- Purpose: let a user deliberately recover a terminal Failed AnalysisJob while preserving failure history, Mapping evidence, queue idempotency, and cost visibility.

This document is an implementation contract. It does not claim that recovery is implemented, tested, Product Validated, or production-observed.

## 2. Problem Statement

Laravel Queue already retries one queued `ExecuteAnalysisJob` automatically up to three attempts. After those attempts are exhausted, `ExecuteAnalysisJob::failed()` changes the business status to Failed.

The application currently displays the error but offers no deliberate user recovery path. Reusing the same Failed row by resetting it to Pending would destroy or blur:

- the original terminal status;
- the failure message and completion time;
- which execution produced later results;
- the distinction between automatic infrastructure retry and a new user-authorized attempt;
- cost attribution across attempts.

Recovery v1 therefore creates a new AnalysisJob and leaves the failed source AnalysisJob immutable.

## 3. Confirmed Current Behavior

At the repository reference above:

- `ExecuteAnalysisJob::$tries = 3` with backoff of 5 and 10 seconds.
- Intermediate technical failures are rethrown to Laravel Queue.
- `ExecuteAnalysisJob::failed()` marks Pending or Processing business state as Failed after retries are exhausted.
- `ExecuteAnalysisJobAction` no-ops for Completed, Failed, and AwaitingMappingConfirmation.
- Pending and Processing are runnable states.
- `ExecuteAnalysisJob` implements `ShouldBeUnique`, keyed by AnalysisJob ID.
- Mapping AI output in `column_mapping` is write-once for one AnalysisJob.
- When `effective_column_mapping` exists, Mapping AI is skipped.
- When only `column_mapping` exists, it is revalidated and reused without another Mapping AI call.
- Planning and final Analyze are rerun on every execution attempt.
- Evaluation, Priority, Diagnosis, and Controlled Action records belong to one AnalysisJob and are not transferable evidence for a different execution.
- Mapping confirmation resume uses a transaction, row lock, strict status check, Pending transition, and dispatch after commit.
- No user recovery route, recovery lineage column, recovery Action, or recovery UI exists.
- Authentication and user ownership are not implemented; Project scoping is not a substitute for authorization.

## 4. Decision Summary

### 4.1 Chosen model: immutable failed attempt plus new child attempt

A recovery creates a new AnalysisJob:

```text
Failed AnalysisJob #101
    └── user recovery creates Pending AnalysisJob #102
```

The failed source remains unchanged.

If #102 later fails, the user may recover #102 to create #103:

```text
#101 Failed
    └── #102 Failed
            └── #103 Pending
```

Each failed attempt may have at most one direct recovery child.

### 4.2 Rejected model: reset Failed to Pending

Do not transition `Failed → Pending` on the same row.

Reasons:

- destroys terminal-attempt identity;
- overwrites timestamps and error context;
- mixes old partial downstream data with a new attempt;
- makes duplicate recovery and cost attribution harder to reason about;
- prevents a reliable attempt chain.

### 4.3 Terminology

Use “Recovery” for a user-authorized new AnalysisJob.

Reserve “retry” for Laravel Queue retry of the same AnalysisJob. UI copy must not call the user action a queue retry.

## 5. Scope

Recovery v1 includes:

- one nullable self-reference identifying the failed source attempt;
- one recovery Action with a database transaction and row lock;
- one POST endpoint;
- Project and DataFile scope validation;
- active-Project requirement;
- creation of a fresh Pending AnalysisJob and AnalysisJobDetail;
- selective Mapping-state copying;
- dispatch after commit;
- duplicate-submit idempotency;
- lineage display in history and detail views;
- explicit AI-cost warning;
- automated tests;
- Docker/MySQL and Browser Product Validation.

## 6. Non-goals

Recovery v1 does not include:

- mutating the source Failed row;
- Laravel `queue:retry` or deletion of a `failed_jobs` row;
- automatic recovery;
- recovery of Pending, Processing, Completed, or AwaitingMappingConfirmation jobs;
- editing title, prompt, template, Mapping, or DataFile during recovery;
- copying normalized result or raw AI response;
- copying EvaluationFact, DiagnosisResult, PriorityResult, or ActionProposal;
- detailed token or currency estimation;
- a global recovery-attempt limit;
- cancel/pause;
- authentication or authorization;
- report or PDF generation;
- outbox infrastructure;
- cleanup of historical failed queue records.

## 7. Data Model

Add a nullable self-reference to `analysis_jobs`:

```text
recovered_from_analysis_job_id BIGINT UNSIGNED NULL
```

Required constraints:

- foreign key to `analysis_jobs.analysis_job_id`;
- `RESTRICT ON DELETE`;
- unique constraint on `recovered_from_analysis_job_id`.

The unique constraint means one source attempt can produce at most one direct recovery child. This is the database-level final defense against concurrent duplicate creation.

Suggested migration name:

```text
database/migrations/YYYY_MM_DD_HHMMSS_add_recovery_lineage_to_analysis_jobs_table.php
```

Migration rules:

- add the nullable column;
- add the self-referencing foreign key;
- add the unique index with an explicit, bounded name;
- `down()` drops foreign key, unique index, then column;
- do not modify old migration files;
- verify generated SQL and actual MySQL schema.

### 7.1 Model relationships

Add to `AnalysisJob`:

```php
public function recoveredFrom(): BelongsTo
public function recoveryAttempt(): HasOne
```

Meanings:

- `recoveredFrom`: the immediate failed source of this attempt;
- `recoveryAttempt`: the one direct child created from this attempt.

Add `recovered_from_analysis_job_id` to fillable data and model documentation.

Do not create a denormalized root-attempt ID in v1. The immediate chain is sufficient.

## 8. Recovery Eligibility

A source AnalysisJob is recoverable only when all conditions hold:

1. The source status is exactly `AnalysisJobStatus::Failed`.
2. The source belongs to the route-bound Project through its non-deleted DataFile.
3. The Project is Active.
4. The DataFile is not soft-deleted.
5. The source has its required AnalysisJobDetail.
6. No direct recovery child already exists.

If a direct recovery child already exists, the request is idempotently treated as already handled and returns that child. It must not create or dispatch another child.

Pending, Processing, AwaitingMappingConfirmation, and Completed are not recoverable.

AwaitingMappingConfirmation is a user-decision wait state, not a technical failure. Its only continuation remains Mapping confirmation.

## 9. Recovery State Copy Contract

### 9.1 New AnalysisJob

Copy exactly:

- `data_file_id`;
- `title`;
- `template_key`.

Set:

- `status = Pending`;
- `recovered_from_analysis_job_id = source.analysis_job_id`;
- new timestamps.

Do not append “retry” or a sequence number to the stored user title. Lineage is separate structured data.

### 9.2 New AnalysisJobDetail

Copy exactly:

- `prompt`;
- `column_mapping`;
- `manual_column_mapping`;
- `effective_column_mapping`.

Initialize as null:

- `raw_response`;
- `result`;
- `error_message`;
- `started_at`;
- `completed_at`.

### 9.3 Mapping reuse behavior

For a Template job:

| Source state | New-attempt behavior |
| --- | --- |
| `effective_column_mapping` exists | Reuse it as confirmed Fact; Mapping AI is not called |
| only `column_mapping` exists | Reuse and deterministically revalidate it; Mapping AI is not called |
| neither exists | Run normal Mapping AI path |
| manual mapping exists with effective mapping | Copy both; effective mapping remains the execution Fact |

For Free Analysis, all Mapping columns remain null.

This copying is safe only because the recovery uses the same DataFile. Recovery v1 does not allow selecting or replacing the file.

### 9.4 Data not reused

Do not copy:

- raw AI response;
- normalized result;
- EvaluationFact;
- DiagnosisResult;
- PriorityResult;
- ActionProposal;
- error message;
- started/completed timestamps.

These belong to the failed attempt and may describe incomplete work.

## 10. Expected Re-execution and Cost

Recovery is a new execution. The UI must state that it may make new AI calls and incur additional cost.

Expected stage behavior:

- Data profiling: rerun deterministically.
- Metric aggregation: rerun deterministically.
- Mapping AI: skipped only when copied Mapping state permits reuse.
- Derived-metric Planning AI: rerun for applicable Template jobs.
- Final Analyze AI: rerun.
- Deterministic Evaluation: recomputed for the new job.
- Deterministic Priority: recomputed for the new job.
- Diagnosis AI: rerun for eligible facts.
- Controlled Action AI: rerun for eligible candidates.

The exact number and price of calls are not guaranteed by this feature. Do not show a fabricated numeric estimate.

## 11. Recovery Action

Create:

```text
app/Actions/AnalysisJob/RecoverFailedAnalysisJobAction.php
```

Suggested return object:

```php
array{analysis_job: AnalysisJob, created: bool}
```

Required algorithm:

1. Start a database transaction.
2. Reload the source AnalysisJob with `analysisJobDetail` and `dataFile.project`.
3. Acquire `lockForUpdate()` on the source AnalysisJob row.
4. Verify source Project/DataFile scope and active Project.
5. Check for an existing direct recovery child.
6. If a child exists, return it with `created = false`; do not dispatch.
7. Require source status Failed.
8. Require AnalysisJobDetail; missing detail is an invariant violation.
9. Create the new Pending AnalysisJob with recovery lineage.
10. Create its AnalysisJobDetail using the copy/reset contract.
11. Register `ExecuteAnalysisJob::dispatch(newId)->afterCommit()`.
12. Return the new job with `created = true`.

The source row must never be updated.

The transaction lock is the primary application defense against double submit. The unique database constraint is the final creation defense. `ShouldBeUnique` is an additional queue defense for the new AnalysisJob ID.

If a database unique violation still occurs in a race, resolve and return the existing child rather than surfacing a 500, provided it is the expected recovery-lineage constraint violation. Do not swallow unrelated database errors.

### 11.1 Dispatch-after-commit limitation

If the database commit succeeds but dispatch itself fails, a Pending recovery child may remain without a queued message.

A repeat submission will find the existing child and, by design, will not dispatch again because duplicate submit must be side-effect-free. Recovery v1 records this as an operational gap rather than guessing whether a Pending job has a queue message.

Do not add an unsafe generic re-dispatch button in this feature. A future pending-job reconciliation/outbox design should address this consistently for both initial creation and recovery.

## 12. HTTP Contract

Add:

```text
POST /projects/{project}/analysis-jobs/{analysisJob}/recover
projects.analysis-jobs.recover
```

Use a POST form with CSRF protection.

Controller responsibility:

- verify the AnalysisJob belongs to the route-bound Project;
- reject recovery for an Archived Project;
- invoke `RecoverFailedAnalysisJobAction`;
- redirect to the child AnalysisJob detail page;
- use different flash messages for created versus already handled.

Suggested messages:

Created:

```text
A new recovery attempt was created. This attempt may make new AI calls and incur additional cost.
```

Already handled:

```text
A recovery attempt already exists. Opening the existing attempt.
```

Do not accept title, prompt, template key, DataFile ID, Mapping, status, or source ID from request input. All copied values come from the locked source record.

## 13. UI Contract

### 13.1 Failed source detail

When a Failed AnalysisJob has no recovery child, show:

- explanation that recovery creates a new attempt;
- statement that the failed attempt remains unchanged;
- warning that new AI calls and cost may occur;
- POST form labeled `Create Recovery Attempt`;
- browser confirmation text before submission.

Suggested confirmation:

```text
Create a new recovery attempt? This may make new AI calls and incur additional cost.
```

When a recovery child already exists, do not show the form. Show `View Recovery Attempt`.

### 13.2 Recovery child detail

Show:

```text
Recovered from AnalysisJob #{source ID}
```

Link to the source detail page.

Do not claim that the source and child produced identical AI output.

### 13.3 Analysis History

For a Failed row without a child:

- show `Create Recovery Attempt` as a CSRF-protected POST form;
- include an accessible cost warning or confirmation;
- keep `View Details`.

For a source with an existing child:

- show `View Recovery Attempt`;
- do not show another recovery form.

For a recovery child:

- show `Recovered from #ID` linked to the source.

No recovery control appears for Pending, Processing, AwaitingMappingConfirmation, or Completed.

### 13.4 Query loading

Update `ListAnalysisJobsQuery` to eager-load only:

- `dataFile`;
- `recoveredFrom`;
- `recoveryAttempt`.

Do not load AnalysisJobDetail or downstream result records for history.

Update the detail controller load to include immediate recovery relationships without recursively loading chains.

## 14. Duplicate and Stale Request Behavior

| Situation | Result |
| --- | --- |
| First valid POST for Failed source | Create one Pending child and dispatch once after commit |
| Concurrent duplicate POST | One child only; later request returns existing child; no second dispatch |
| Repeated POST after child exists | Redirect to existing child; no create and no dispatch |
| POST for Pending/Processing/Completed/Awaiting | Reject or redirect without create/dispatch |
| Queue message for original Failed source | Existing ExecuteAnalysisJobAction no-op |
| Duplicate queue message for new child while Pending/Processing | Existing ShouldBeUnique is the queue defense; normal action guards remain |
| Recovery child later Failed | Recover that child, not its ancestor |

Do not retry the old `failed_jobs` payload. It targets the failed source ID and will no-op because Failed is non-runnable.

## 15. Failure and Validation Behavior

Expected user-invalid states must not become server errors:

- wrong Project/source combination: 404;
- Archived Project: validation error or safe redirect;
- non-Failed source: validation error or safe redirect;
- existing recovery: redirect to child;
- deleted DataFile: 404 or safe rejection;
- missing AnalysisJobDetail: invariant exception, no child.

Technical database or dispatch errors must not be mislabeled as successful recovery.

The original `error_message` remains visible on the source detail page. The child begins without an error.

## 16. Security Boundary

Recovery copies only server-side persisted fields. The request carries no business payload.

Project scoping must be checked before creation and again inside the locked transaction using the reloaded source.

This prevents route-model mismatch but is not user authorization. Recovery must not be publicly deployed until authentication, ownership, and policies are implemented under the Business v1 roadmap.

## 17. Migration and MySQL Validation

Before applying the migration:

- inspect `php artisan migrate:status`;
- inspect `php artisan migrate --pretend`;
- verify local Docker MySQL target.

After applying:

- verify nullable self-reference column;
- verify explicit unique index;
- verify self foreign key and RESTRICT behavior;
- verify existing rows contain null;
- verify rollback SQL or `down()` structure without destructively rolling back the shared local development database.

Do not use `migrate:fresh`, `db:wipe`, or destructive reset commands.

## 18. Automated Test Contract

### 18.1 Migration/model tests

Test:

- `recoveredFrom` relationship;
- `recoveryAttempt` relationship;
- one direct child per source constraint;
- chained recovery across separate failed attempts;
- existing AnalysisJobs remain compatible with null lineage.

### 18.2 Action tests

Test:

- valid Failed source creates one Pending child;
- source remains byte-for-byte unchanged for status, error, timestamps, raw response, and result;
- copied DataFile, title, template key, prompt;
- copied Mapping state;
- child execution fields reset to null;
- no downstream Evaluation/Diagnosis/Priority/Action rows copied;
- exactly one dispatch for first recovery;
- dispatch occurs after commit;
- repeated call returns same child and does not dispatch again;
- simulated concurrent/unique race resolves existing child;
- non-Failed statuses rejected with zero child and zero dispatch;
- Archived Project rejected;
- wrong Project rejected;
- soft-deleted DataFile rejected;
- missing detail fails without child.

### 18.3 Controller tests

Test:

- valid POST redirects to new child detail;
- CSRF form route uses POST;
- wrong Project returns 404;
- Archived Project cannot recover;
- non-Failed statuses cannot recover;
- duplicate POST redirects to existing child;
- no request payload can override copied fields.

### 18.4 UI tests

Test row-scoped and detail-scoped behavior:

- Failed without child shows recovery form and cost warning;
- Failed with child shows View Recovery Attempt and no form;
- only Failed status shows recovery control;
- child shows source link;
- history query eager-loads immediate lineage relationships;
- no N+1 for lineage display;
- no raw error or AI response is added to history;
- existing Mapping and View Details actions remain correct.

### 18.5 Pipeline reuse tests

Using fakes/mocks, verify:

- copied effective Mapping skips Mapping AI;
- copied column Mapping without effective Mapping skips Mapping AI and revalidates;
- no copied Mapping follows normal Mapping AI path;
- Planning/Analyze remain eligible to rerun;
- original failed source is never executed or updated.

### 18.6 Regression suite

Run:

- new recovery tests;
- AnalysisJob model/action/controller/history tests;
- Mapping controller and ExecuteAnalysisJob tests;
- Controlled Action pipeline regressions affected by lineage/model loading;
- full `php artisan test`;
- Pint on changed PHP files;
- `git diff --check`.

Test-code existence and execution results must be reported separately.

## 19. Product Validation Contract

Use synthetic data and local Docker/MySQL only.

Required scenarios:

1. Failed Template job with effective Mapping:
   - recovery child created;
   - lineage visible;
   - Mapping copied;
   - original unchanged.
2. Failed Template job with only column Mapping:
   - copied and reusable.
3. Failed job without Mapping:
   - child starts without Mapping.
4. Duplicate recovery submission:
   - one child;
   - one dispatch;
   - second request opens existing child.
5. Non-Failed statuses:
   - no recovery control;
   - POST cannot create child.
6. Archived Project:
   - history remains readable;
   - recovery is rejected.
7. Recovery chain:
   - failed child can create its own child;
   - each direct parent has only one child.
8. Browser:
   - warning and confirmation visible;
   - correct source/child links;
   - no control on wrong statuses.
9. MySQL:
   - self FK and unique constraint verified.
10. Side effects:
   - original failed record and downstream rows unchanged.

A real OpenAI call is not required merely to validate creation and lineage. To validate Mapping reuse without cost, use controlled fakes and automated pipeline tests. Do not run a full provider pipeline unless separately authorized with a call limit.

## 20. Expected File Scope

Expected new files:

- recovery-lineage migration;
- `app/Actions/AnalysisJob/RecoverFailedAnalysisJobAction.php`;
- focused recovery tests;
- optional Product Validation artifacts after implementation review.

Expected modified files:

- `app/Models/AnalysisJob.php`;
- `database/factories/AnalysisJobFactory.php`;
- `app/Http/Controllers/AnalysisJobController.php`;
- `app/Queries/AnalysisJob/ListAnalysisJobsQuery.php`;
- `routes/web.php`;
- `resources/views/analysis-jobs/index.blade.php`;
- `resources/views/analysis-jobs/show.blade.php`;
- relevant existing tests;
- `docs/product/ANALYSIS_JOB_MODULE.md` only where recovery lifecycle must be documented.

Unexpected files require explanation before commit.

Do not modify or stage unrelated local files:

- `docs/development/CODING_STANDARDS.md`;
- `AGENTS.md`.

## 21. Acceptance Criteria

Recovery v1 is acceptable when:

- a user can create one new Pending attempt from a Failed AnalysisJob;
- the Failed source remains unchanged;
- the child records immediate recovery lineage;
- one source has at most one direct child;
- duplicate submissions create and dispatch only once;
- recovery cannot be triggered for any non-Failed status;
- Project/DataFile scope and active-Project rules are enforced;
- prompt, template, DataFile, and Mapping state are copied server-side;
- result, response, error, timestamps, and downstream records are not copied;
- copied Mapping prevents avoidable Mapping AI calls;
- Planning and subsequent applicable stages are not incorrectly treated as reusable;
- source and child links are visible;
- users see that recovery may create new AI calls and cost;
- old `failed_jobs` entries are not retried or deleted;
- MySQL constraints match the design;
- targeted and full tests pass;
- Browser Product Validation passes;
- no public-authorization claim is made.

## 22. Known Gaps

- A commit-success/dispatch-failure window can leave a Pending child without a queue message.
- No durable outbox or Pending-job reconciler exists.
- No global cap prevents a long chain of separately failed recovery attempts.
- Exact provider cost is not stored or displayed.
- DataFile content immutability is assumed by Mapping reuse; this feature does not introduce a content hash.
- Authentication and cross-user authorization remain absent.
- Production behavior is unobserved.

These are explicit non-claims, not reasons to silently broaden Recovery v1.

## 23. Implementation and Review Workflow

1. Merge this approved design into `feature/analysis-job-module`.
2. Create `feature/failed-analysis-recovery-v1`.
3. Codex implements the design and runs automated tests.
4. Claude Code performs read-only review using `docs/development/AI_CODE_REVIEW.md`.
5. Codex addresses accepted findings and reruns tests.
6. Validate migration and UI in Docker/MySQL and Browser.
7. Commit implementation and validation artifacts separately.
8. Push and merge into `feature/analysis-job-module`.

## 24. Explicit Non-claims

This design does not claim:

- Failed AnalysisJobs are currently recoverable;
- recovery is free or deterministic in AI wording;
- copied Mapping makes later AI stages reusable;
- user recovery is equivalent to Laravel Queue retry;
- the old `failed_jobs` payload should be retried;
- Project route scoping provides authentication;
- dispatch-after-commit is failure-proof;
- production behavior has been observed.
