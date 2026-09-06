# Controlled Action Layer v1 Design

## 1. Document status

- Status: **Designed / not implemented**
- Proposed phase: Phase 4-D
- Target repository ref at design time: `feature/analysis-job-module`
- Design date: 2026-09-06
- Implementation, automated test execution, Browser E2E, and Production Observation are not established by this document.

This document is the implementation contract for the first Controlled Action Layer. It must not be read as evidence that the capability already exists.

## 2. Purpose

ReportFlow AI currently produces deterministic `EvaluationFact` rows, controlled `DiagnosisResult` rows, and deterministic `PriorityResult` rows. It also retains a legacy `analysis_job_details.result.recommendations[]` output containing free-form AI prose.

Controlled Action v1 introduces a separate, typed and evidence-linked proposal layer:

```text
EvaluationFact
  -> DiagnosisResult
  -> PriorityResult
  -> deterministic Action eligibility and catalog selection
  -> constrained AI wording
  -> application validation
  -> ActionProposal persistence
  -> read-only UI
```

The goal is to recommend a review or investigation that a human can understand. v1 never changes an external system and never treats a proposal as approval.

## 3. Non-goals

The following are outside v1:

- changing an advertising budget, bid, targeting, creative, landing page, email campaign, or any external system;
- approve/reject/edit/execute workflows;
- connectors to advertising or CRM platforms;
- rollback or compensation of external changes;
- model training, prediction, or zypl integration;
- replacing the legacy `recommendations[]` contract;
- numerical budget allocation or expected-uplift optimization;
- using Action output as input to Final Analyze, Evaluation, Diagnosis, or Priority.

Budget reallocation is intentionally deferred. Current Diagnosis v1 can identify only `measurement_consistency_risk` or `insufficient_explanatory_evidence`; neither proves that reallocating money will improve an outcome.

## 4. Confirmed current-state constraints

At the design base ref:

- Final Analyze has a required `recommendations[]` field containing free-form `title`, `description`, and nullable `high|medium|low` priority.
- Decision-enabled Final Analyze is instructed by prompt to return `recommendations: []`, but the shared schema and normalizer still accept a non-empty array.
- `DiagnosisResult` has a controlled `category_key`, evidence references, missing evidence, supporting facts, model, and prompt version.
- `PriorityResult` is deterministic and means confirmation/investigation priority, not permission to execute a business action.
- no Action model, catalog, approval state, execution state, or external side-effect path exists.

These constraints require a new contract rather than adding execution meaning to legacy recommendation prose.

## 5. Responsibility boundaries

| Layer | Question answered | Must not do |
|---|---|---|
| Evaluation | What measurable condition exists? | infer a cause or action |
| Diagnosis | Which allowed explanation category is supported? | assign execution authority |
| Priority | What should be investigated first? | select or execute an action |
| Action eligibility | Which catalog entries are permitted by current evidence? | create free-form action types |
| Action AI | How should an eligible proposal be summarized? | change catalog key, target, evidence, or priority |
| Action validator | Is the returned proposal within the supplied contract? | infer missing business facts |
| UI | What may a human review? | imply approval or execution |

`PriorityResult.priority_band` remains the proposal's investigation priority. v1 must not introduce another AI-generated `priority` field.

## 6. Legacy compatibility boundary

Legacy `recommendations[]` remains readable for existing results, Free Analysis, and non-Decision-enabled templates. Controlled Action uses a new table and UI section.

Rules:

1. Do not migrate legacy recommendations into `ActionProposal` rows.
2. Do not add IDs, approval state, or execution semantics to legacy recommendation JSON.
3. Decision-enabled new runs must deterministically persist no legacy recommendations. Because retrying the full Analyze call may add cost, v1 should normalize a valid-shaped non-empty Decision-enabled `recommendations[]` to `[]` and log a policy warning rather than throw solely for this violation.
4. Malformed output remains governed by existing normalization/error behavior.
5. The legacy UI heading must remain visually separate from `Controlled Actions`.

The stripping behavior in rule 3 must be implemented at the application boundary with explicit tests; prompt instructions alone are not the guarantee.

## 7. Action Catalog v1

Create `config/action_catalog.php`. Catalog keys are application-owned allow-list values and are never invented by AI.

### 7.1 `verify_measurement_consistency`

- Label: Verify measurement consistency
- Allowed diagnosis: `measurement_consistency_risk`
- Intended outcome: verify whether tracking/measurement is consistent before business optimization.
- Required references: EvaluationFact, DiagnosisResult, PriorityResult.
- AI may produce: concise rationale and a list of checks chosen from the deterministic check allow-list.
- Check allow-list v1: `verify_event_definition`, `verify_tag_firing`, `verify_time_window`, `verify_source_completeness`.

### 7.2 `collect_explanatory_evidence`

- Label: Collect explanatory evidence
- Allowed diagnosis: `insufficient_explanatory_evidence`
- Intended outcome: collect missing information before any optimization recommendation.
- Required references: EvaluationFact, DiagnosisResult, PriorityResult.
- AI may produce: concise rationale and missing-evidence items selected only from the Diagnosis evidence package/result.
- It must not convert a missing-evidence item into a factual causal claim.

No generic `other` action is permitted. Adding a catalog key requires config, deterministic eligibility, validator support, tests, and a schema/prompt version change.

## 8. Eligibility

Add a pure deterministic action, tentatively `DetermineActionEligibilityAction`.

An EvaluationFact is eligible only when all conditions hold:

- a `PriorityResult` exists for the same `evaluation_fact_id`;
- a `DiagnosisResult` exists for the same `evaluation_fact_id`;
- the Diagnosis category maps to exactly one enabled Action Catalog entry;
- all required identifiers and evidence fields needed by that catalog entry exist;
- the AnalysisJob is Decision-enabled by the same config-membership rule already used by the pipeline.

Eligibility returns either:

```text
eligible: true
catalog_key: <one allow-listed key>
```

or:

```text
eligible: false
reason: missing_priority | missing_diagnosis | unsupported_diagnosis |
        missing_required_evidence | not_decision_enabled
```

An ineligible item is skipped with a structured log. It is not an exception and must not trigger an AI call.

## 9. Evidence package

Add a pure builder, tentatively `BuildActionEvidencePackageAction`. The package is created by Laravel and is the only business context sent to Action AI.

Required package fields:

```json
{
  "analysis_job_id": 123,
  "action_catalog_key": "verify_measurement_consistency",
  "trigger_fact": {
    "evaluation_fact_id": 10,
    "metric_key": "conversion_rate",
    "entity_key": "Social",
    "evaluation_level": "high",
    "direction": "below",
    "evidence_refs": []
  },
  "diagnosis": {
    "diagnosis_result_id": 20,
    "category_key": "measurement_consistency_risk",
    "rationale_summary": "...",
    "evidence_refs": [],
    "missing_evidence": []
  },
  "priority": {
    "priority_result_id": 30,
    "priority_band": "medium",
    "priority_score": 0.175,
    "formula_version": "priority_v1.1"
  },
  "allowed_checks": ["verify_event_definition", "verify_tag_firing"],
  "contract_version": "action_contract_v1"
}
```

The builder must copy identifiers and deterministic values from persisted models. AI must not receive the raw CSV, DataFile path, arbitrary prompt text, legacy recommendations, or unrelated facts.

## 10. Action AI output contract

Add a dedicated `AiAnalysisClient::proposeAction()` method and a separate strict Structured Output schema. Do not reuse Final Analyze or Diagnosis schema.

One eligible fact produces at most one proposal call and one proposal:

```json
{
  "catalog_key": "verify_measurement_consistency",
  "title": "Verify conversion measurement consistency for Social",
  "rationale_summary": "The observed evidence makes measurement consistency worth checking before optimization.",
  "selected_checks": ["verify_event_definition", "verify_tag_firing"],
  "evidence_refs": ["evaluation_fact:10", "diagnosis_result:20", "priority_result:30"],
  "missing_evidence": []
}
```

Schema constraints:

- `additionalProperties: false` at every object level;
- all properties required, using empty arrays rather than omitted fields;
- `catalog_key` is a single-value enum supplied by Laravel for that request;
- `selected_checks` items use a request-specific enum and have unique items;
- `evidence_refs` items use a request-specific enum generated from the package;
- text fields have explicit practical length limits if supported by the provider; the application validator enforces the limits regardless;
- there is no action type, target ID, priority, confidence, approval, execution, URL, command, or arbitrary parameter supplied by AI.

System Instruction must state that this is an advisory review proposal, not a confirmed cause or authorized execution.

## 11. Application validation

Add `NormalizeActionProposalAction` or an equivalently named application validator. It independently validates:

- exact output shape;
- catalog key equals the deterministic eligible key;
- every selected check belongs to the supplied allow-list;
- every evidence reference belongs to the supplied reference list;
- referenced IDs match the current AnalysisJob and EvaluationFact chain;
- title and rationale are non-empty and within configured lengths;
- missing evidence is a subset of supplied Diagnosis missing evidence;
- no unsupported field is present;
- at least one evidence reference exists;
- catalog-specific invariants hold.

It guarantees structure, allow-list membership, referential integrity, and contract executability. It does not prove that AI wording is causally or commercially correct.

Invalid output is a per-candidate Action failure: log it, persist no row for that candidate, and continue with other eligible candidates. It must not fail the AnalysisJob.

## 12. Persistence

Create an `action_proposals` table and `ActionProposal` model.

Proposed columns:

```text
action_proposal_id       primary key
analysis_job_id          foreign key -> analysis_jobs, cascade delete
evaluation_fact_id       foreign key -> evaluation_facts, cascade delete, unique
diagnosis_result_id      foreign key -> diagnosis_results, cascade delete
priority_result_id       foreign key -> priority_results, cascade delete
catalog_key              string(100)
title                    string(255)
rationale_summary        text
selected_checks_json     json
evidence_refs_json       json
missing_evidence_json    json
raw_response             longText nullable
model                    string(100)
prompt_version           string(50)
contract_version         string(50)
proposed_at              timestamp
timestamps
```

The unique `evaluation_fact_id` enforces at most one current proposal per fact. No approval or execution columns are added in v1 because no such lifecycle exists yet.

## 13. Orchestration and lifecycle

Add `RunActionProposalForAnalysisJobAction` after Diagnosis and before `markCompleted()`:

```text
Final Analyze
  -> Controlled Diagnosis
  -> Controlled Action Proposal
  -> markCompleted
```

The orchestrator reloads eligible EvaluationFact rows with their DiagnosisResult and PriorityResult relations. It must not use in-memory AI output from Final Analyze.

Idempotency policy:

1. delete existing ActionProposal rows for the AnalysisJob before proposal generation;
2. determine eligibility independently for each fact;
3. call Action AI only for eligible facts;
4. persist each validated proposal;
5. a per-fact provider/normalization/persistence failure is logged and skipped;
6. an orchestration-level failure is caught by `ExecuteAnalysisJobAction`, logged, and soft-fails;
7. stale rows must not survive a new attempt that cannot regenerate them.

Because delete-first plus per-candidate persistence can leave a valid partial set, the UI must represent proposals as best-effort output, not a complete action plan. A later phase may require an all-or-nothing batch contract.

Queue retry of the entire AnalysisJob may call Action AI again because Action proposals are generated late in each attempt and are not write-once. This cost/non-determinism must be documented and measured; v1 does not introduce cross-attempt call caching.

## 14. Failure isolation

Controlled Action is additive and must soft-fail. An Action failure must not:

- mark an otherwise successful AnalysisJob as Failed;
- change EvaluationFact, DiagnosisResult, or PriorityResult rows;
- re-enable legacy Decision-enabled recommendations;
- trigger external effects;
- prevent `markCompleted()`.

Business ineligibility is a normal skip. Provider errors, malformed output, and database errors are technical failures but remain isolated to the Action layer.

## 15. UI v1

Add a `Controlled Actions` section to the AnalysisJob detail page.

Each proposal displays:

- proposal title;
- catalog label;
- target metric/entity;
- investigation priority band and score, clearly labelled as confirmation priority;
- rationale;
- evidence references;
- selected checks or missing evidence;
- a fixed badge: `Advisory only — not executed`.

No approve, reject, edit, execute, retry, or external link control is added. If no proposal exists, distinguish:

- no eligible evidence;
- Action layer technical failure, when that state can be established safely;
- feature not applicable to this analysis.

Do not present missing Action proposals as proof that no action is needed.

## 16. Security and safety invariants

- The Action AI receives only the constructed evidence package.
- AI cannot introduce a catalog key, check key, evidence reference, or target.
- No output is executed as PHP, SQL, shell, URL, HTTP request, job dispatch, or connector command.
- User-supplied and AI-supplied strings are escaped by Blade.
- Persisted raw response is audit data and is never rendered directly.
- No external credentials or connector configuration belong in this phase.
- A future executable Action requires a new design covering authorization, approval, idempotency, limits, audit, rollback/compensation, and partial failure.

## 17. Versioning

Initial values:

- `prompt_version = action_prompt_v1`
- `contract_version = action_contract_v1`
- Action Catalog entries are versioned through code/config history in v1.

Change the prompt version when Action AI instructions change semantically. Change the contract version when schema, normalization rules, evidence package, or catalog semantics change.

## 18. Automated acceptance criteria

### Unit tests

- eligibility accepts each supported diagnosis/catalog mapping;
- eligibility rejects missing Priority, missing Diagnosis, unsupported diagnosis, missing evidence, and non-Decision-enabled jobs;
- evidence package copies only allowed persisted facts and emits stable reference IDs;
- normalizer rejects unknown catalog/check/reference, mismatched IDs, extra properties, invalid lengths, and unsupported missing evidence;
- normalizer accepts canonical output for both v1 catalog entries;
- Decision-enabled Final Analyze non-empty legacy recommendations are deterministically converted to `[]` and logged;
- Free Analysis and non-Decision-enabled legacy recommendation behavior remains unchanged.

### Feature tests

- eligible fact creates exactly one ActionProposal linked to Evaluation, Diagnosis, and Priority;
- ineligible fact makes zero Action AI calls and creates no proposal;
- multiple eligible facts are isolated per candidate;
- rerun deletes stale proposals and does not duplicate rows;
- Action failure still allows AnalysisJob completion;
- Evaluation/Diagnosis/Priority results are unchanged by Action failure;
- Action AI call count equals eligible candidate count;
- free-form and templates without evaluation config make zero Action AI calls;
- no external side-effect service is invoked or introduced;
- UI renders advisory wording and never an execution control.

Tests must assert call counts, persisted foreign keys, versions, exact skip behavior, and absence of legacy recommendation regression. Test-code existence and test execution results must be reported separately.

## 19. Product validation cases

After automated tests pass, perform and retain raw artifacts for:

1. Measurement consistency candidate: zero conversions with sufficient traffic produces `verify_measurement_consistency`.
2. Insufficient explanation candidate: produces `collect_explanatory_evidence`, not a causal or optimization claim.
3. Ineligible fact: no proposal and zero Action AI calls.
4. Mixed candidates: one malformed Action response does not remove another valid proposal or fail the AnalysisJob.
5. Decision-enabled Analyze policy violation: a controlled test response with non-empty legacy recommendations persists `[]`.
6. Regression: Free Analysis and non-Decision-enabled template preserve their existing result/UI behavior.

Retain input fixture, model and prompt/contract versions, raw request package, raw response, normalized result, DB rows, call count, expected result, and screenshot. Browser narrative without these artifacts is not sufficient raw E2E evidence.

## 20. Deferred budget recommendation design

A future `review_budget_allocation` or `propose_budget_reallocation` catalog entry requires evidence not currently guaranteed by Diagnosis v1:

- current spend/budget and time window;
- feasible source and destination entities;
- comparable measurement definitions;
- minimum sample and data-quality checks;
- business constraints and maximum change limits;
- expected-impact method and uncertainty;
- explicit human approval;
- outcome measurement and rollback/compensation if execution is later added.

Until that contract exists, Controlled Action must not state an amount or instruct a budget change. It may only recommend collecting evidence or verifying measurement consistency through the two v1 catalog entries.

## 21. Implementation sequence

1. Add Decision-enabled legacy recommendation deterministic stripping and regression tests.
2. Add Action Catalog, eligibility, evidence package, and unit tests.
3. Add dedicated AI method/schema and application normalizer tests.
4. Add migration/model/relations and persistence tests.
5. Add orchestration after Diagnosis with soft-fail, stale cleanup, and call-count tests.
6. Add read-only UI.
7. Run targeted tests, full suite, static/format checks required by the repository.
8. Execute Product Validation and retain raw artifacts.
9. Review results before considering budget recommendation Phase 2.

## 22. Implementation handoff rules

Before coding, Codex must inspect the current branch and report:

- whether current code differs from the base described here;
- concrete files/classes/migrations/tests to add or change;
- any provider Structured Output limitation affecting request-specific enums or length constraints;
- any unresolved conflict between this design and committed ADR/product documents;
- exact test commands planned.

Implementation begins only after that plan is reviewed. If implementation requires expanding v1 to approval, execution, budget allocation, prediction, or external connectors, stop and request a design decision instead of silently extending scope.
