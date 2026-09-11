# HTML Report Module v1

## 1. Document Status

- Status: Designed
- Design date: 2026-09-08
- Base branch: `feature/analysis-job-module`
- Base repository reference: `b6aa2723aa2ce9bf0dc300e9136562ee5720e38b`
- Design branch: `design/html-report-module-v1`
- Roadmap phase: Phase 3 — HTML Report Module v1
- Implementation status: Not implemented
- Test status: Not implemented or executed for this module
- Product Validation status: Not executed
- Production Observation status: No evidence
- Next implementation branch: `feature/html-report-module-v1`

This document defines an implementation contract. It does not claim that the Report model, routes, rendering, tests, or browser validation already exist.

## 2. Purpose

HTML Report Module v1 turns one persisted, completed AnalysisJob attempt into a stable business artifact.

The existing `analysis-jobs.show` page is a live operational result screen. It is not a report artifact: it reads current relational state and its presentation can change when application code or downstream records change.

v1 introduces a separate persisted Report whose content:

- is produced only from data already persisted for one completed AnalysisJob;
- requires no new AI call;
- requires no Queue job;
- does not rerun Mapping, Planning, Analyze, Evaluation, Diagnosis, Priority, or Controlled Action;
- remains unchanged after creation;
- can later become the source for PDF Export v1.

## 3. Evidence and Current Boundaries

Repository inspection at the base reference confirms:

- `AnalysisJobDetail::result` stores normalized analysis output.
- EvaluationFact, DiagnosisResult, PriorityResult, and ActionProposal are separately persisted.
- `AnalysisJobController::show()` currently assembles those live relations for the detail page.
- the current routes contain no Report endpoint;
- the current models and migrations contain no Report aggregate or report snapshot;
- `composer.json` contains no HTML-to-PDF library;
- the Business v1 Roadmap requires a persisted HTML report before PDF export.

This design does not use the existing detail page as proof that a Report module exists.

## 4. Approved v1 Decisions

### 4.1 One immutable Report per AnalysisJob attempt

Each completed AnalysisJob may create at most one Report.

- A Recovery child is a different AnalysisJob attempt and may create its own Report.
- A Report is never moved from a failed source attempt to a Recovery child.
- v1 has no regenerate, overwrite, edit, delete, or version-history operation.
- Repeated generation requests return the already-created Report.

This preserves the historical meaning of both the AnalysisJob attempt and its report.

### 4.2 Persist snapshot JSON and rendered HTML

The Report stores both:

1. `snapshot_json`: the structured, display-ready source used to render the report;
2. `rendered_html`: the HTML generated from that exact snapshot.

Reasons:

- the snapshot remains auditable and usable by the future PDF exporter;
- persisted HTML keeps the report stable even if the live AnalysisJob detail page changes;
- rendering never needs to reload current Evaluation/Diagnosis/Priority/Action rows;
- the content hash can detect accidental mutation or corruption.

The persisted HTML is generated only by an application-owned Blade template. Raw AI HTML is never accepted or stored as report HTML.

### 4.3 Snapshot, not live relations, is authoritative after creation

After a Report has been created:

- `GET` display reads the Report row only;
- it must not rebuild sections from current AnalysisJob relations;
- it must not call eligibility Actions;
- it must not silently reflect later database edits.

The source AnalysisJob identity remains available for navigation and audit, but its current downstream records do not replace the stored snapshot.

### 4.4 No AI and no asynchronous generation

Report generation is synchronous and deterministic.

The generation path must not call or dispatch:

- Mapping AI;
- Planning AI;
- Analyze AI;
- Diagnosis AI;
- Action AI;
- `ExecuteAnalysisJob`;
- any new report Queue job.

A normal generation request performs database reads, deterministic transformation, Blade rendering, and one database insert.

### 4.5 Generation is limited to a completed, valid source

Creation is allowed only when all are true:

- the AnalysisJob belongs to the Project in the route;
- the Project is Active;
- the AnalysisJob status is Completed;
- the AnalysisJob is not soft-deleted;
- its DataFile is not soft-deleted;
- an AnalysisJobDetail exists;
- `AnalysisJobDetail::result` is an array with the required normalized v1 shape.

Existing Reports remain viewable when the Project is later Archived. Archive prevents creation of a new derived artifact but does not erase an existing one.

### 4.6 Project scoping is not authorization

v1 uses the existing Project → DataFile → AnalysisJob relationship boundary and rejects cross-Project combinations with 404.

This is not user authorization. Authentication, ownership, policies, and protected shared access remain the Business v1 deployment gate.

## 5. Domain Model

Add `App\Models\Report`.

Relationship:

- AnalysisJob `hasOne(Report::class)`
- Report `belongsTo(AnalysisJob::class)`

Do not add a direct `project_id` or `data_file_id` to Reports in v1. Project and DataFile identity are derived through the immutable source AnalysisJob lineage. Duplicating those foreign keys would introduce consistency states without adding a current query requirement.

## 6. Database Schema

Create `reports` with a new migration.

| Column | Type | Contract |
| --- | --- | --- |
| `report_id` | bigint unsigned PK | Report identity |
| `analysis_job_id` | bigint unsigned FK, unique | Exactly one Report per AnalysisJob |
| `title` | varchar(255) | Title copied into the snapshot boundary |
| `snapshot_json` | JSON | Complete display-ready Report snapshot |
| `rendered_html` | LONGTEXT | Persisted body rendered from the snapshot |
| `schema_version` | varchar(50) | Snapshot contract version, initially `report_schema_v1.0` |
| `renderer_version` | varchar(50) | HTML renderer version, initially `report_renderer_v1.0` |
| `content_hash` | char(64) | Lowercase SHA-256 of the exact persisted `rendered_html` bytes |
| `generated_at` | timestamp | Artifact generation time |
| timestamps | timestamps | Persistence metadata |

Constraints:

- named FK from `analysis_job_id` to `analysis_jobs.analysis_job_id`;
- `ON DELETE CASCADE`, because v1 treats Report as a derived child of its AnalysisJob;
- named unique constraint on `analysis_job_id`;
- no nullable content/version/hash columns.

The migration `down()` drops the table only. Do not edit historical migrations.

## 7. Snapshot Contract

`BuildReportSnapshotAction` returns a fixed associative structure. It does not return Eloquent models.

Top-level shape:

```text
schema_version
source
analysis
evaluation
diagnosis
priority
controlled_actions
generated_at
```

### 7.1 source

Contains only display-safe provenance:

- AnalysisJob ID;
- AnalysisJob title;
- template key;
- display mode: Free Analysis, configured template name, or unknown-key fallback;
- DataFile original name;
- AnalysisJob completed time;
- Recovery source AnalysisJob ID, when present.

Do not include:

- prompt;
- raw provider response;
- filesystem/storage path;
- column mapping confidence;
- API/model credentials;
- error message from another attempt.

### 7.2 analysis

Copy the normalized persisted result fields:

- `summary`;
- `highlights`;
- `metrics`;
- `tables`;
- `insights`;
- `recommendations`.

The builder validates types and normalizes missing optional arrays to `[]`. It must not invent text for missing AI content.

Legacy recommendations remain included when they are present in the persisted normalized result. Decision-enabled jobs whose normalizer stored `recommendations: []` remain empty.

### 7.3 evaluation

Store display-ready rows derived deterministically from EvaluationFact:

- stable reference ID;
- entity type and key;
- metric key and display label;
- metric value;
- display baseline value;
- absolute difference;
- direction;
- evaluation level;
- rule version;
- computed time.

Internally stored rate values remain 0–1 in the snapshot. Percentage and percentage-point formatting belongs to the Report renderer, matching the existing Evaluation contract.

Do not expose `z_score` in the business report v1.

### 7.4 diagnosis

For each diagnosis-eligible EvaluationFact, store one of:

- `available`, with category label, rationale, evidence references, missing evidence, model and prompt version; or
- `unavailable`, when the fact was eligible but no DiagnosisResult was persisted.

Facts that were not eligible do not create diagnosis rows.

Do not expose `self_reported_confidence`, `raw_response`, or supporting internal payloads.

Eligibility must be computed during snapshot creation using the existing `DetermineDiagnosisEligibilityAction`; do not duplicate its rules.

### 7.5 priority

For each priority-eligible EvaluationFact, store one of:

- `available`, with priority band, impact score, peer/control gap display inputs, formula version, and computed time; or
- `unavailable`, when eligible but no PriorityResult was persisted.

Not-eligible facts remain distinguishable from eligible-but-unavailable facts.

Do not expose raw `priority_score`. The Japanese label remains `確認優先度`, not execution priority.

Priority eligibility is the same existing condition as Diagnosis eligibility.

### 7.6 controlled_actions

Store:

- applicability;
- eligible candidate count;
- proposals.

Each proposal includes only:

- catalog key and configured label;
- title;
- target entity/metric;
- rationale;
- priority band and approved display inputs;
- selected checks;
- evidence references;
- missing evidence;
- model/prompt/contract versions;
- proposed time;
- the fixed advisory label `Advisory only — not executed`.

Do not include raw responses or operation controls.

When no proposal exists, preserve the current three-way distinction:

1. not applicable;
2. applicable but zero eligible candidates;
3. eligible evidence existed but best-effort proposal is unavailable.

Use `GetControlledActionViewDataQuery` during snapshot creation rather than recreating this classification.

## 8. Ordering Rules

Snapshot list ordering must be deterministic.

- Evaluation rows: `evaluation_fact_id ASC`.
- Diagnosis and Priority rows: follow their parent Evaluation order.
- Controlled Actions: `action_proposal_id ASC`.
- Analysis arrays and table row order: preserve the normalized persisted result order.

No database-default ordering may define persisted report content.

## 9. Application Services

### 9.1 BuildReportSnapshotAction

Responsibilities:

- accept a fully loaded completed AnalysisJob;
- validate the source invariants;
- call existing deterministic eligibility/query collaborators;
- produce the fixed display-safe array;
- perform no database writes;
- make no AI or network calls.

### 9.2 RenderHtmlReportAction

Responsibilities:

- accept the snapshot array only;
- render `reports.partials.document` or an equivalent dedicated Blade template;
- return a string;
- never query the database;
- never accept arbitrary template names;
- rely on normal Blade escaped output for all source strings.

Do not use `{!! !!}` for snapshot-sourced strings. Only the already-rendered HTML is inserted into the outer Report display shell.

### 9.3 GenerateHtmlReportForAnalysisJobAction

Responsibilities:

1. execute inside a database transaction;
2. re-fetch and `lockForUpdate()` the source AnalysisJob with all required relations;
3. enforce Project, status, archive, soft-delete, detail, and result invariants;
4. return the existing Report with `created=false` when one already exists;
5. build the snapshot;
6. render HTML;
7. calculate SHA-256;
8. insert the Report;
9. return `['report' => Report, 'created' => bool]`.

The database unique constraint is the final race backstop. Catch only the specifically identified Reports/AnalysisJob unique violation for MySQL and SQLite; rethrow unrelated QueryException instances.

The transaction prevents reading a moving source during generation. It does not claim to make historical downstream rows immutable outside this operation.

## 10. Controller and Route Contract

Use a dedicated `ReportController`; do not add Report assembly to `AnalysisJobController`.

Routes:

```text
POST /projects/{project}/analysis-jobs/{analysisJob}/report
     projects.analysis-jobs.reports.store

GET  /projects/{project}/reports/{report}
     projects.reports.show
```

### store

- validate the AnalysisJob belongs to the Project;
- invoke `GenerateHtmlReportForAnalysisJobAction`;
- redirect to the Report show route;
- flash `Report generated.` or `Opening the existing report.`;
- accept no report body, snapshot, title, AnalysisJob status, or source-data fields from request payload.

### show

- load Report → AnalysisJob → DataFile;
- enforce Project scoping with 404;
- render the persisted `rendered_html`;
- do not rebuild the snapshot;
- do not load current downstream result relations.

## 11. UI Contract

### 11.1 AnalysisJob detail

For a Completed AnalysisJob:

- if no Report exists and Project is Active: show `Generate HTML Report` POST form;
- if a Report exists: show `View HTML Report` link;
- if Project is Archived and no Report exists: show a non-actionable explanation;
- show no Report action for Pending, Processing, AwaitingMappingConfirmation, or Failed.

Load only the `report` relation needed to make this choice.

### 11.2 Report page

The Report page contains:

- Report title;
- source metadata;
- generated timestamp;
- persisted analysis sections;
- downstream sections using the absence semantics in §7;
- link back to the source AnalysisJob;
- clear Controlled Action advisory language.

It contains no:

- auto refresh;
- Mapping confirmation;
- Recovery form;
- Retry;
- Approve/Reject/Edit/Execute controls;
- external execution link;
- PDF button before PDF Export v1 exists;
- raw response;
- prompt;
- raw priority score;
- self-reported diagnosis confidence.

## 12. Required Result Validation

Before persistence, reject malformed or missing source results with a domain/invariant exception and create no Report.

Minimum rules:

- result must be an array;
- `summary` must be a non-empty string;
- list sections must be arrays;
- each metric/table/insight/recommendation item must match the normalized contract used by the current UI;
- table row widths must match their column counts;
- values must be scalar or null where rendered as cells;
- no arbitrary HTML field is supported.

Do not silently cast malformed structures into plausible business output.

## 13. Idempotency and Concurrency

The write-once guarantee has three layers:

1. transaction-scoped existing Report lookup;
2. source AnalysisJob `lockForUpdate()`;
3. database unique constraint on `reports.analysis_job_id`.

Required outcomes:

- first valid POST: one Report, `created=true`;
- sequential duplicate POST: same Report, `created=false`;
- simulated unique race: return the competing Report, `created=false`;
- no Queue dispatch and no network call in every path.

True simultaneous MySQL request behavior remains a Product Validation item; SQLite race simulation alone is not evidence of live InnoDB lock contention.

## 14. Failure and Partial-Data Semantics

| Condition | Behavior |
| --- | --- |
| Job not Completed | Reject; no Report |
| Missing detail/result | Invariant failure; no Report |
| Malformed normalized result | Invariant failure; no Report |
| Evaluation not applicable | Report core analysis; evaluation marked not applicable |
| Evaluation applicable but no facts | Preserve an explicit unavailable/empty-safe state; do not claim “no issue” |
| Diagnosis/Priority eligible but row absent | Explicit unavailable state |
| Controlled Action inapplicable | Explicit not-applicable state |
| Eligible Action candidate but proposal absent | Explicit best-effort unavailable state |
| Existing Report | Return it unchanged |
| Rendering or insert failure | Roll back; no partial Report row |

Absence of a downstream record must never be translated into “no problem,” “no action needed,” or “analysis successful” unless the persisted data explicitly supports that statement.

## 15. Security and Content Safety

- Every source string is escaped by Blade during dedicated document rendering.
- No raw AI response is rendered.
- No user-supplied HTML is accepted.
- The outer page may render the trusted persisted `rendered_html`; this is safe only because that field is application-generated and not mass-assigned from an HTTP request.
- Add tests containing `<script>`, HTML attributes, Japanese text, and long text to prove escaped output.
- Cross-Project route combinations return 404.
- This v1 boundary does not make the application safe for shared/public deployment without authentication and ownership authorization.

## 16. Automated Test Requirements

### 16.1 Model and migration

- casts and relationships;
- one Report per AnalysisJob;
- cascade behavior;
- required columns and version/hash persistence.

### 16.2 Snapshot builder unit tests

- complete report;
- Free Analysis;
- configured and unknown template display names;
- legacy recommendations preserved;
- Decision-enabled empty recommendations;
- Evaluation rate values remain internal 0–1;
- diagnosis/priority not-eligible vs unavailable vs available;
- all three no-proposal Controlled Action states;
- deterministic ordering;
- raw response, prompt, confidence, and raw priority score excluded;
- malformed result rejected.

### 16.3 Renderer unit tests

- expected sections and labels;
- Japanese and long-table content;
- HTML/script-like source values escaped;
- no forms, buttons, operational links, or raw fields;
- same snapshot produces the same HTML bytes;
- stored hash equals rendered HTML SHA-256.

### 16.4 Generation action feature tests

- valid Completed source creates one Report;
- non-Completed statuses rejected;
- Archived Project creation rejected;
- soft-deleted DataFile/source rejected;
- cross-Project rejected;
- missing detail/result rejected;
- duplicate request returns same row;
- simulated unique race;
- unrelated database exception rethrown;
- source and downstream records remain unchanged;
- Queue fake asserts nothing dispatched;
- HTTP fake asserts nothing sent.

### 16.5 Controller/UI feature tests

- POST ignores/rejects injected source/report fields;
- successful redirect and flash messages;
- source scoping;
- Report show reads persisted HTML after source records are subsequently changed;
- Completed detail shows Generate or View correctly;
- other statuses have no Report control;
- archived existing Report remains viewable;
- Report page contains no PDF/export control in this phase.

Run targeted tests, relevant AnalysisJob/History/Recovery/Controlled Action regressions, and the full suite.

## 17. Product Validation Plan

After implementation review, validate in Docker/PHP/MySQL and Browser.

Required scenarios:

1. complete Decision-enabled AnalysisJob;
2. Free Analysis with legacy recommendations;
3. Evaluation-not-applicable template;
4. eligible Diagnosis/Priority/Action records partially absent;
5. all three Controlled Action no-proposal states;
6. duplicate generate submission;
7. cross-Project rejection;
8. archived Project with an existing Report;
9. source mutation after report creation proves the persisted Report is unchanged;
10. Japanese text, long table, and HTML-like source content escaping.

Record:

- branch and commit;
- PHP/Laravel/MySQL versions;
- migration pending/applied state and `SHOW CREATE TABLE`;
- test commands and driver disclosure;
- Browser scenarios;
- Report IDs and source AnalysisJob IDs;
- content hashes before/after source mutation;
- OpenAI call count (expected 0);
- Queue row count and worker state;
- screenshots where tooling can actually persist them;
- explicit unverified items.

Do not use production/shared data. Do not start a Queue worker for Report generation.

## 18. File Plan

Expected new files:

```text
app/Actions/Report/BuildReportSnapshotAction.php
app/Actions/Report/GenerateHtmlReportForAnalysisJobAction.php
app/Actions/Report/RenderHtmlReportAction.php
app/Http/Controllers/ReportController.php
app/Models/Report.php
database/factories/ReportFactory.php
database/migrations/*_create_reports_table.php
resources/views/reports/show.blade.php
resources/views/reports/partials/document.blade.php
tests/Feature/Report/GenerateHtmlReportForAnalysisJobActionTest.php
tests/Feature/Report/HtmlReportControllerTest.php
tests/Feature/Report/ReportModelTest.php
tests/Unit/Actions/Report/BuildReportSnapshotActionTest.php
tests/Unit/Actions/Report/RenderHtmlReportActionTest.php
```

Expected existing-file changes:

```text
app/Models/AnalysisJob.php
app/Http/Controllers/AnalysisJobController.php
routes/web.php
resources/views/analysis-jobs/show.blade.php
relevant existing AnalysisJob controller tests
```

The implementer must inspect actual filenames before editing and may adjust test grouping without weakening the contracts.

## 19. Explicit Non-goals

HTML Report Module v1 does not include:

- PDF export;
- report regeneration or multiple report versions;
- report editing;
- report deletion;
- report sharing;
- public URLs;
- authentication or authorization;
- report email delivery;
- report scheduling;
- AI-written report narrative beyond already-persisted normalized content;
- new AI calls;
- forecasting;
- budget allocation;
- external connectors or action execution;
- a generic user-selectable Blade/template engine.

## 20. Implementation Sequence

1. Add migration, Report model, factory, and relationships.
2. Implement strict snapshot builder.
3. Implement dedicated escaped renderer.
4. Implement transactional write-once generator.
5. Add Report controller and routes.
6. Add Analysis detail generation/view controls.
7. Add Report view.
8. Add targeted tests.
9. Run relevant regressions and full tests.
10. Run Pint only on changed PHP files and `git diff --check`.
11. Stop before real Browser/Product Validation.
12. Claude Code reviews against this document and `docs/development/AI_CODE_REVIEW.md`.
13. Codex fixes findings.
14. Run Docker/MySQL and Browser Product Validation with retained artifacts.
15. Commit Report implementation separately from validation artifacts.

## 21. Implementation Stop Conditions

Stop and report before expanding scope if:

- the normalized result contract in current code conflicts with §12;
- producing the snapshot would require another AI call;
- a missing downstream state cannot be distinguished using current persisted data and existing deterministic collaborators;
- safe rendering would require accepting raw HTML from stored analysis content;
- implementing Report requires changing Evaluation/Diagnosis/Priority/Action business logic;
- authentication or public sharing becomes necessary for the requested v1 flow;
- a protected pre-existing working-tree change overlaps the implementation.

## 22. Minimal Codex Implementation Request

```text
Implement HTML Report Module v1 on a new feature branch from the latest
feature/analysis-job-module.

Authoritative specification:
- docs/product/HTML_REPORT_MODULE.md

Review rules:
- docs/development/AI_CODE_REVIEW.md

Before editing:
- inspect git status;
- read AGENTS.md if present as working instructions;
- do not modify, stage, delete, or restore the pre-existing
  docs/development/CODING_STANDARDS.md change or untracked AGENTS.md;
- verify all referenced paths exist.

Implement only the approved v1 scope. Add automated tests and run targeted
regressions, the full test suite, changed-PHP Pint, and git diff --check.
Do not run real OpenAI API validation or Browser Product Validation.
Do not stage, commit, push, or merge. Report changed files, design mapping,
test results, unverified items, and exact git status.
```

## 23. Review Focus

Claude Code review should prioritize:

- Report is a persisted artifact, not a live detail-page alias;
- snapshot and HTML are write-once;
- duplicate/race handling is constraint-specific;
- generation makes zero AI/network calls and dispatches nothing;
- all source text is escaped before persisted HTML is trusted;
- Report display never rehydrates current downstream relations;
- absence states are not converted into unsupported positive claims;
- raw responses, prompt, self-reported confidence, and raw priority score do not leak;
- cross-Project scoping is enforced;
- no PDF capability is claimed before Phase 4.
