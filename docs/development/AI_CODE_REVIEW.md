# AI Code Review Standard

## 1. Purpose

This document defines the standard procedure for read-only code reviews performed by Claude Code, Codex, or another AI reviewer.

The reviewer should be able to perform a consistent review from a short request containing only the Base ref, Specification, Scope, Mode, and Test execution permission. Feature-specific rules must not be embedded in this document. They must be derived from the supplied Specification and the repository instructions in effect for the requested Scope.

Review and remediation are separate activities. A review identifies and explains issues; it does not modify the implementation unless the user starts a separate fix task with explicit authorization.

## 2. Input Parameters

Every review request should define the following parameters. If a value is not supplied, the reviewer must report the omission and use a safe, explicitly stated assumption only when doing so cannot expand the review or mutation scope.

### 2.1 Base ref

The Git ref against which the implementation is reviewed, for example a branch name, tag, or commit SHA.

The reviewer must resolve the ref before relying on it. An invalid or ambiguous Base ref is an unverified item and may block conclusions about the diff.

### 2.2 Specification

The authoritative implementation contract, such as a Product Document, ADR, issue, or explicitly identified set of documents.

The reviewer must derive feature-specific behavior, non-goals, acceptance criteria, and safety boundaries from this input. When documents conflict, the reviewer must report the conflict and must not silently choose a materially different product behavior.

### 2.3 Scope

The files, directories, commits, feature area, or change set included in the review.

The reviewer may inspect directly related code outside the Scope when necessary to verify interactions, but must label findings outside the requested Scope separately and must not turn them into unrequested changes.

### 2.4 Mode

The permitted review mode. Supported values should be stated by the requester, with `read-only` as the safe default.

In `read-only` mode, no repository, working-tree, database, generated artifact, dependency, configuration, external service, or remote state may be changed.

### 2.5 Test execution permission

Whether commands that execute tests or other verification tools are permitted. This permission must be explicit and should identify any relevant command, environment, network, database, or cost restrictions.

Permission to review code is not permission to execute tests. Permission to execute tests is not permission to run formatters, update snapshots, install dependencies, access a real provider, perform Browser operations, commit, or push.

## 3. Review Preconditions

Before reviewing the implementation, the reviewer must:

1. Read applicable repository and workspace instructions, including `AGENTS.md` and any scoped instruction files.
2. Resolve and record the current branch and Base ref.
3. Run or inspect the equivalent of `git status --short`.
4. Run or inspect the equivalent of `git diff --stat <base>`.
5. Run `git diff --check` only when command execution is permitted by the review request. Otherwise, report it as not executed.
6. Record all pre-existing modified and untracked files relevant to protecting user work.
7. Confirm the requested Scope and identify any diff that falls outside it.
8. Preserve user changes. Never modify, delete, overwrite, stage, clean, restore, or reset pre-existing modified or untracked files.

If the working tree contains changes from multiple sources, the reviewer must not assume they all belong to the implementation being reviewed. Findings must be tied to the actual diff and evidence available.

## 4. Standard Review Areas

The reviewer must assess every applicable area below. An area that cannot be assessed must appear under Unverified items rather than being silently omitted.

### 4.1 Specification compliance

- Required behavior and acceptance criteria are implemented.
- Explicit non-goals and deferred scope remain excluded.
- Implementation order or lifecycle requirements are preserved where behaviorally relevant.
- Feature-specific invariants are taken from the supplied Specification.

### 4.2 Architecture and responsibility boundaries

- Code follows the repository's established architecture and applicable ADRs.
- Controllers, Queries, Actions, Models, infrastructure clients, and views retain their intended responsibilities.
- New abstractions are justified by an implemented use case.
- Data does not cross a boundary that the Specification prohibits.

### 4.3 AI prompt, schema, and application validator

- Prompt responsibilities match the Specification and do not grant extra authority to the model.
- Structured Output schema uses keywords supported by the configured provider and model.
- Request-specific allow-lists are represented correctly.
- Application validation independently enforces semantic constraints, exact shape, membership, length, identifiers, and feature invariants as required.
- Prompt text, schema enforcement, and application validation are reviewed as separate controls.

### 4.4 Security and authorization

- Authentication, authorization, ownership, and tenant boundaries are enforced where applicable.
- Untrusted user and AI output is validated and safely rendered.
- Mass assignment, injection, unsafe URL or command handling, credential leakage, and unintended external effects are considered.
- Read-only or advisory data is not given approval or execution semantics.

### 4.5 Database migration, foreign keys, constraints, and transactions

- Schema types, nullability, indexes, unique constraints, comments, timestamps, and deletion behavior match the contract.
- Foreign keys protect the intended relationships.
- Cross-row or cross-aggregate integrity not enforceable by a foreign key is validated elsewhere.
- Transaction boundaries do not include avoidable external I/O and do not create misleading partial state.
- Migration rollback behavior is safe and complete.

### 4.6 Queue, retry, idempotency, and concurrency

- Retry behavior does not create duplicates, repeat forbidden effects, or corrupt state.
- Idempotency claims are supported by implementation and database constraints.
- Concurrent workers, duplicate messages, stale jobs, and partial retries are considered.
- AI or external call counts under retry are explicit when they affect correctness or cost.

### 4.7 Failure isolation, soft-fail, and stale data

- Business ineligibility is distinguished from technical failure.
- Soft-fail behavior does not accidentally become a hard failure or conceal a required failure.
- Partial candidate failures are isolated as specified.
- Cleanup order and cascade behavior prevent stale data to the extent claimed.
- Claims that cannot hold during database failure are stated with their actual limits.

### 4.8 Backward compatibility

- Existing persisted data and public contracts remain readable when required.
- New and legacy concepts remain separate.
- Existing callers, UI behavior, migrations, casts, and serialized structures are not unintentionally changed.
- Compatibility behavior is enforced in code rather than assumed from prompt wording or comments.

### 4.9 Controller, Query, and UI

- Controllers remain thin and delegate reads and writes according to repository rules.
- Queries are read-only.
- UI wording matches the certainty and authority of the underlying result.
- Empty, unavailable, ineligible, and not-applicable states are not misleadingly collapsed.
- Escaping and control absence are verified from the rendered implementation or relevant test.

### 4.10 Test coverage and test quality

- Tests cover the Specification's acceptance criteria, failure paths, boundary values, call counts, persistence, and regressions.
- Assertions prove behavior rather than merely execute code.
- Mocks do not bypass the behavior the test claims to verify.
- Tests do not encode an incorrect implementation as the expected contract.
- Unit, feature, integration, real-provider, Browser, and Product Validation evidence are identified separately.

### 4.11 Logging, audit, and versioning

- Logs contain enough structured context to investigate failures without leaking prohibited data.
- Audit fields store the required model, prompt, contract, formula, or rule versions.
- Raw provider output is handled and rendered according to the Specification.
- Version values change when contract semantics change.

### 4.12 Cost and AI call count

- Eligibility prevents unnecessary AI calls.
- Calls per candidate, analysis, attempt, and retry match the contract.
- Error handling does not trigger expensive whole-pipeline retries unless specified.
- Caching or deduplication is not claimed when it does not exist.

## 5. Evidence Rules

The reviewer must follow these evidence rules:

1. Do not accept the implementer's work report as proof of implementation or correctness.
2. Inspect the actual implementation, Git diff, related code paths, migrations, configuration, and test code.
3. Do not confuse the existence of test code with successful test execution.
4. Do not treat a test command reported by another party as executed evidence without an independently available result.
5. Separate docblock and documentation claims from observable implementation behavior.
6. Trace important claims to concrete files, classes, methods, and lines.
7. Label statements as one of:
   - **Confirmed fact**: directly established by inspected code, diff, command output, or test output.
   - **Inference**: a reasoned conclusion from confirmed facts that has not been directly exercised.
   - **Unverified**: insufficient evidence or unavailable execution prevents confirmation.
8. Never report an inference as a confirmed runtime result.
9. A passing test proves only the behavior and environment actually exercised by that test.

## 6. Severity Definitions

### Critical

A defect that can directly cause catastrophic or irreversible impact, such as unauthorized execution, severe cross-tenant disclosure, credential compromise, destructive data loss, or a production-wide integrity failure. It normally blocks merge and Product Validation until fixed.

### High

A material violation of a core Specification or safety boundary that can produce incorrect business behavior, bypass authorization, execute prohibited effects, corrupt or materially stale data, fail a primary workflow, or invalidate the feature's central guarantee under realistic conditions. It blocks Product Validation readiness unless explicitly accepted by the responsible owner.

### Medium

A real correctness, reliability, compatibility, observability, or test gap with bounded impact. The primary workflow may work, but an edge case, retry path, partial failure, validation rule, UI state, or maintainability boundary behaves incorrectly or lacks adequate protection. It should normally be fixed before release and may require fixing before Product Validation depending on the scenario exercised.

### Low

A minor issue with limited operational impact, such as imprecise wording, localized maintainability friction, incomplete non-critical documentation, or a small test-quality weakness. It does not invalidate the core feature or safety boundary but should be recorded rather than silently ignored.

Severity reflects impact and likelihood in the reviewed context, not implementation effort. Lack of evidence is reported as Unverified, not automatically assigned a high severity.

## 7. Finding Requirements

Every Finding must include:

- **Severity**: Critical, High, Medium, or Low.
- **Location**: file, class, method, and the narrowest useful line or line range.
- **Problem**: the concrete defect or violated invariant.
- **Reproduction condition**: the state, input, timing, retry, failure, or request that exposes it.
- **Impact**: the user, data, security, cost, or operational consequence.
- **Specification reference**: the relevant section, rule, acceptance criterion, ADR, or repository instruction.
- **Recommended fix**: a scoped remediation that preserves stated boundaries.
- **Why existing tests missed it**: the missing scenario, weak assertion, mock boundary, or absent execution evidence. If existing tests do cover it but fail, state that instead.
- **Evidence classification**: Confirmed fact or Inference, with the supporting evidence identified.

Do not create a Finding solely for personal style preference. If there are no actionable Findings, state that explicitly; do not omit the section.

## 8. Required Review Output

The final review report must use these sections in this order:

### 8.1 Findings

List actionable defects in descending severity, then by likely impact. Each item must satisfy all Finding Requirements.

### 8.2 Design deviations

List differences from the supplied Specification that are intentional, ambiguous, non-defective, or require a product decision. Do not hide a defect here merely because it also differs from the design.

### 8.3 Missing tests

Identify missing scenarios and weak assertions. Distinguish tests that do not exist from tests that exist but were not executed.

### 8.4 Confirmed good boundaries

Record important safety, architecture, compatibility, and scope boundaries verified from code or executed evidence. Avoid generic praise; name the concrete boundary and evidence.

### 8.5 Unverified items

List all conclusions that could not be verified, including unavailable environments, prohibited commands, real-provider behavior, Browser behavior, migrations not exercised on the target database, and missing external evidence.

### 8.6 Product Validation readiness

Choose exactly one result:

- **Ready**: no unresolved Finding or unverified prerequisite blocks the planned Product Validation scenarios.
- **Ready after fixes**: identified fixes are required, but the implementation can become ready without a design change or major rework.
- **Not ready**: a Critical/High boundary failure, unresolved design decision, missing essential implementation, or unavailable prerequisite makes Product Validation unsafe or meaningless.

Explain the decision using the Findings and Unverified items. Passing automated tests alone does not automatically mean Ready.

## 9. Safety Rules

1. When Mode is `read-only`, do not create, edit, delete, rename, move, generate, stage, restore, or otherwise change any file or repository state.
2. Do not run a formatter unless the user explicitly permits formatting. A formatter is a write operation even when invoked for verification if it can modify files.
3. Do not execute tests unless Test execution permission explicitly allows them.
4. Do not commit or push unless each action is explicitly authorized.
5. Do not install or update dependencies, regenerate lock files, migrate a database, start services, contact a paid provider, or perform Browser actions without specific permission.
6. Do not combine review and fix work in the same phase. Finish and report the review first.
7. Do not modify out-of-scope problems. Report them separately with their location and potential impact.
8. Read-only inspection of directly related files is allowed when necessary to establish evidence, subject to repository instructions and the requested Scope.
9. If a command unexpectedly changes files, stop, disclose the change, and request direction before proceeding.

## 10. Final Confirmation

Every review report must end with an explicit operational summary:

- **File changes**: list files changed by the reviewer, or state `None`.
- **Tests executed**: list exact commands and results, or state `None` and why.
- **Formatter executed**: list the command and whether it changed files, or state `None`.
- **Commit**: state the commit SHA, or `Not performed`.
- **Push**: state the remote/ref, or `Not performed`.

This confirmation describes actions taken by the reviewer during the review. It must not repeat an implementer's unverified claims as the reviewer's own actions.

## 11. Minimal Review Request Template

```text
Base ref: <branch, tag, or commit>
Specification: <document path or authoritative reference>
Scope: <files, directories, commits, or feature>
Mode: read-only
Test execution permission: not permitted | permitted: <exact limits>
```

When this template is used, this standard supplies the review procedure, evidence rules, severity definitions, required output, and safety constraints. Feature-specific rules continue to come only from the Specification and applicable repository instructions.
