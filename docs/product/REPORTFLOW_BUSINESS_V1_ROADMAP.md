# ReportFlow AI — Business v1 Roadmap

## 1. Document Status

- Status: Designed
- Investigation date: 2026-09-07
- Repository reference: `969eaed69ab711875c612fbb54db4a7a0fd849ca`
- Base branch: `feature/analysis-job-module`
- Purpose: define the shortest evidence-based path from the current engineering prototype to a coherent ReportFlow AI v1 product.
- Evidence boundary: this document records repository inspection and product decisions. It does not claim that roadmap items are implemented, tested, deployed, or production-observed.

## 2. Product Direction

ReportFlow AI v1 should complete one reliable user journey:

1. Create a project.
2. Upload a CSV data file.
3. Start an analysis.
4. Resolve mapping uncertainty when human confirmation is required.
5. Find and monitor the analysis later.
6. Review the normalized analysis, deterministic evaluation, controlled diagnosis, deterministic priority, and advisory controlled actions.
7. Generate a stable HTML report from persisted results without another AI call.
8. Export that report as PDF.
9. Recover safely from a failed analysis without silently creating duplicate work or unexpected AI cost.

The current priority is to complete this product loop. New intelligence layers, prediction, budget allocation, and external execution are intentionally deferred.

## 3. Investigation Scope

The investigation covered the current repository tree and relevant routes, controllers, models, migrations, queries, views, product and development documents, queue orchestration, tests, environment examples, and Docker configuration at the repository reference above.

Primary inspected areas included:

- `routes/web.php`
- `app/Http/Controllers/`
- `app/Actions/AnalysisJob/`
- `app/Jobs/ExecuteAnalysisJob.php`
- `app/Models/`
- `app/Queries/`
- `database/migrations/`
- `resources/views/`
- `docs/product/`
- `docs/development/RELEASE_PROCESS.md`
- `README.md`
- `.env.example`
- `compose.yaml`
- `composer.json`
- `tests/`

Local uncommitted `docs/development/CODING_STANDARDS.md` and untracked `AGENTS.md` were not changed or treated as historical evidence.

## 4. Current Product Assessment

The analysis engine is substantially more mature than the surrounding product workflow. Mapping control, derived metrics, deterministic evaluation, controlled diagnosis, deterministic priority, and controlled advisory actions have code and automated-test coverage. The user-facing lifecycle around those capabilities remains incomplete.

The largest immediate problem is not another AI reasoning capability. It is that a completed or failed AnalysisJob is difficult to rediscover and manage after the initial redirect to its detail page.

The repository currently has no dedicated Report domain, persisted report snapshot, report route, PDF export path, authenticated ownership boundary, or user-facing failed-job recovery flow.

The existing AnalysisJob result page is an HTML view, but it must not be treated as an implemented HTML Report module. It is a live result screen and is not a separately persisted, versioned, regenerable report artifact.

## 5. Capability Maturity Matrix

| Capability | Designed | Implemented | Automated tests present | Product validated | Production observed | Current conclusion |
| --- | --- | --- | --- | --- | --- | --- |
| Project management | Yes | Yes | Yes | Not revalidated in this investigation | No evidence found | Core foundation exists |
| CSV upload and DataFile listing | Yes | Yes | Yes | Not revalidated in this investigation | No evidence found | Core foundation exists |
| Analysis creation and queued execution | Yes | Yes | Yes | Partially | No evidence found | Internal workflow exists |
| Mapping confirmation / resume | Yes | Yes | Yes | Prior project evidence exists | No evidence found | Mature internal boundary |
| Derived metrics | Yes | Yes | Yes | Prior project evidence exists | No evidence found | Mature internal boundary |
| Deterministic evaluation | Yes | Yes | Yes | Prior project evidence exists | No evidence found | Mature internal boundary |
| Controlled diagnosis | Yes | Yes | Yes | Prior project evidence exists | No evidence found | Mature internal boundary |
| Deterministic priority | Yes | Yes | Yes | Prior project evidence exists | No evidence found | Mature internal boundary |
| Controlled Action Layer v1 | Yes | Yes | Yes | Component-level provider and browser validation completed | No evidence found | Advisory layer exists |
| Analysis history / rediscovery | Partly documented | No dedicated list flow found | No dedicated list tests found | No | No | Immediate product gap |
| User-triggered failed analysis recovery | No complete contract found | No route or UI found | No dedicated UI recovery tests found | No | No | Product gap |
| Persisted HTML Report module | Future direction documented | No | No | No | No | Product gap |
| PDF export | Future direction documented | No | No | No | No | Product gap |
| Authentication and user ownership | Explicitly out of scope in older module docs | No ownership boundary found | No | No | No | Deployment blocker |
| Authorization policies | No complete design found | No | No | No | No | Deployment blocker |
| CI workflow | Release process described | No workflow found under `.github/workflows` | No | No | No | Operational gap |
| Managed queue worker | Manual worker operation documented | No dedicated Compose worker/supervisor service found | N/A | No | No | Operational gap |
| Deployment and production telemetry | No complete implementation found | No | No | No | No | Not production-ready |

“Product validated” in this table does not imply full real-provider end-to-end validation of the entire Mapping → Planning → Analyze → Diagnosis → Action pipeline.

## 6. Two Release Gates

### 6.1 Gate A — Local Demo Ready

A local or portfolio demonstration may proceed when the complete user journey is coherent using local infrastructure and controlled data:

- analyses are rediscoverable;
- statuses and mapping-wait states are visible;
- failed jobs have a deliberate recovery path;
- completed results can become a stable HTML report;
- that report can be exported to PDF;
- the core journey has browser validation and retained artifacts.

Authentication is not required for a strictly local, single-user demonstration that is not exposed to other users.

### 6.2 Gate B — Shared or Public Deployment Ready

No shared or public deployment should occur until all of the following are implemented and verified:

- authentication;
- explicit Project ownership by User or an equivalent tenant boundary;
- authorization policies for Project, DataFile, AnalysisJob, mapping confirmation, Report, and export;
- protected file access;
- queue worker supervision and deployment runbook;
- CI checks;
- secret and environment management;
- production-safe logging and sensitive-data review.

Authentication is therefore a hard deployment gate even if local product-loop work is implemented first.

## 7. Ordered Delivery Plan

### Phase 1 — Analysis History & Status UX v1

Status: next implementation target.

Goal: make AnalysisJobs rediscoverable and their lifecycle visible without changing AI behavior.

Recommended scope:

- Add a project-scoped AnalysisJob index.
- Show job title or analysis type, source DataFile, template/free-analysis mode, status, creation/update time, and a link to the existing detail page.
- Provide deterministic status labels for Pending, Processing, AwaitingMappingConfirmation, Completed, and Failed.
- Make AwaitingMappingConfirmation visibly actionable through the existing mapping route.
- Add navigation from the Project and DataFile flows.
- Keep queries in a dedicated query object and keep status/business decisions out of Blade and controllers.
- Add feature tests for project scoping, ordering, each status presentation, empty state, and navigation.
- Do not add AI calls, report generation, retry behavior, deletion, or authorization assumptions in this phase.

Why first:

- It closes the most visible break in the current user journey.
- It makes waiting and failure states observable.
- It has no provider-cost impact.
- It provides the operational surface needed by recovery and report features.

Suggested design document for the implementation branch:

- `docs/product/ANALYSIS_HISTORY.md`

### Phase 2 — Failed Analysis Recovery v1

Goal: provide a deliberate user recovery action without conflating it with automatic queue retry.

The design must decide and document:

- whether recovery creates a new AnalysisJob attempt or reuses the failed row;
- which persisted mapping and effective mapping may be reused;
- which AI stages are rerun;
- how duplicate clicks and stale queue messages become no-ops;
- how the UI warns that another attempt may incur AI cost;
- how prior failure context and attempt history remain inspectable.

Default recommendation: prefer a new attempt or explicit attempt record over silently resetting a terminal Failed row. Preserve provenance and make cost-causing work explicit.

Do not implement a retry button until this state and idempotency contract is approved.

### Phase 3 — HTML Report Module v1

Goal: turn persisted analysis outputs into a stable business artifact.

Recommended boundaries:

- Introduce an explicit Report model or equivalent persisted report snapshot.
- Generate the report deterministically from persisted normalized results and controlled downstream records.
- Do not call AI during report rendering or re-rendering.
- Store report/schema version and source AnalysisJob identity.
- Keep the live AnalysisJob detail view separate from the report artifact.
- Define behavior when optional Evaluation, Diagnosis, Priority, or ActionProposal records are absent.
- Add browser validation for complete, partial, and empty-safe report states.

### Phase 4 — PDF Export v1

Goal: export the persisted HTML report consistently.

Requirements:

- Export from the persisted report representation, not by rerunning analysis.
- Define filename, page layout, date/time formatting, and missing-section behavior.
- Verify Japanese text and long tables.
- Add integration tests where practical and retain browser/PDF validation artifacts.
- Prevent access to another owner’s report before shared deployment.

### Phase 5 — Authentication, Ownership, and Authorization

Goal: satisfy the shared/public deployment gate.

Minimum design:

- Associate Projects with a User or explicit workspace/tenant.
- Derive DataFile, AnalysisJob, and Report access through that ownership chain.
- Add policies or equivalent authorization checks to every read and mutation route.
- Protect uploaded and exported files.
- Define behavior for existing records during migration.
- Add cross-user isolation tests.

### Phase 6 — Operational Hardening

Goal: make the product reproducible and safely operable.

Scope:

- Add CI for the authoritative test suite and formatting checks.
- Add a managed queue-worker service or supervisor configuration.
- Define worker restart/deployment behavior to prevent stale code.
- Align README and `.env.example` with the supported local environment.
- Add health checks and a minimal runbook.
- Record provider model, prompt, schema, and contract versions needed for incident analysis.
- Define retention and redaction rules for raw AI responses and uploaded business data.

### Phase 7 — Demo Pack and Business v1 Release

Goal: demonstrate the entire product claim with retained evidence.

The validation pack should cover:

- new project and CSV upload;
- free analysis;
- template analysis with mapping confirmation;
- history/status rediscovery;
- deterministic downstream sections;
- controlled advisory action output;
- failed-job recovery;
- stable HTML report;
- PDF export;
- authorization isolation when the deployment gate applies;
- no-action, partial-data, and technical-soft-failure states.

After validation, update README claims, release documentation, and version/tag together.

## 8. Business v1 Definition of Done

Business v1 is complete only when:

- A user can complete and later rediscover the full Project → DataFile → AnalysisJob journey.
- Mapping confirmation is recoverable and visible.
- Completed analyses expose normalized analysis, evaluation, diagnosis, priority, and controlled advisory actions without implying external execution.
- Failed analyses have an explicit, tested, cost-aware recovery contract.
- A stable HTML Report artifact can be generated without another AI call.
- The report can be exported to PDF.
- The complete journey has automated tests and retained Product Validation evidence.
- Shared/public deployments enforce authentication, ownership, authorization, and protected artifact access.
- Queue processing is supervised and CI protects the supported test and formatting contracts.
- Documentation claims match the implemented and validated product.

## 9. Explicitly Deferred

The following are not part of Business v1:

- automatic action execution;
- Approve, Reject, Edit, Execute, or Retry controls inside Controlled Actions;
- advertising, CRM, or other external connectors;
- controlled budget allocation;
- forecasting or predictive recommendations;
- zypl-derived sleep-user prediction or similar predictive models;
- autonomous agents;
- user-editable prompts;
- RAG or vector search;
- multi-tenant billing;
- broad cost dashboards.

These may be reconsidered only after the core product loop has usage evidence and stable input/output contracts.

## 10. Documentation Drift to Correct During Delivery

The current README contains capability claims and module-status text that no longer match the repository consistently. In particular, it mentions HTML report generation and PDF export while the inspected repository has no dedicated Report or export implementation, and its current-module section understates the implemented analysis pipeline.

Correct the README when the corresponding roadmap phase is completed. Do not update a capability claim in advance of implementation and validation.

Additional cleanup candidates:

- replace the generic application name in `.env.example`;
- document the supported SQLite-versus-MySQL development paths;
- reconcile the release-process milestones with the actual module order;
- document queue-worker startup and restart requirements.

## 11. Principal Risks and Controls

| Risk | Control |
| --- | --- |
| Continuing to add AI layers while the product journey remains incomplete | Require completion of Phases 1–4 before predictive or execution features |
| Treating the current result page as a report artifact | Introduce a separate persisted Report contract |
| Public exposure without owner isolation | Enforce Gate B before shared/public deployment |
| Manual recovery causing duplicate provider cost | Approve retry/idempotency design before adding a retry control |
| Stale queue workers running old code | Add supervised workers and deployment restart rules |
| Documentation overstating capability | Update claims only with implementation and validation evidence |
| Sensitive uploads or raw model data leaking | Define protected storage, authorization, retention, and redaction |
| Component validation being mistaken for full-pipeline validation | Label validation scope and retain per-stage evidence |

## 12. Next Design Task

Create a new branch from the approved roadmap branch or its merged target:

- Branch: `design/analysis-history-v1`
- Document: `docs/product/ANALYSIS_HISTORY.md`

That design should specify routes, query boundaries, ordering and pagination, status presentation, empty states, navigation, project scoping, tests, and explicit non-goals. After design approval, Codex may implement it on `feature/analysis-history-v1`, followed by a Claude Code review using `docs/development/AI_CODE_REVIEW.md`.

## 13. Explicit Non-claims

This roadmap does not claim:

- the whole real-provider analysis pipeline has been validated end to end;
- any current feature is production-observed;
- HTML Report or PDF export is already implemented;
- the current application is safe for shared/public access;
- internal queue retry is equivalent to user-controlled recovery;
- prediction or budget allocation is currently justified by product evidence.
