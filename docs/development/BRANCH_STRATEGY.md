# Branch Strategy

## Purpose

This document defines the Git branch strategy for ReportFlow AI.

The goal is to keep development history:

- Clear
- Reviewable
- Traceable
- Releasable

ReportFlow AI follows a lightweight GitHub Flow.

---

## Main Branch

`main` is the stable branch of ReportFlow AI.

Rules:

- `main` should always remain in a releasable state.
- Do not develop features directly on `main`.
- Changes should be merged into `main` through Pull Requests.
- Release tags must be created from `main`.
- Do not force-push to `main`.

---

## Feature Branches

Each feature or business module should be developed in its own feature branch.

Naming convention:

```text
feature/<feature-name>
```

Examples:

```text
feature/project-module
feature/datafile-module
feature/analysis-module
feature/report-module
```

A feature branch should contain one logical feature or module.

Avoid mixing unrelated changes into the same branch.

---

## Fix Branches

Bug fixes should use a dedicated fix branch.

Naming convention:

```text
fix/<issue-name>
```

Examples:

```text
fix/project-validation
fix/datafile-upload-error
fix/report-generation-error
```

---

## Documentation Branches

Documentation-only changes may use:

```text
docs/<topic>
```

Examples:

```text
docs/update-architecture
docs/update-release-process
```

Small documentation changes related directly to an active feature may remain in that feature branch.

---

## Branch Flow

The standard development flow is:

```text
main
 │
 └── feature/*
       │
       ├── Development
       ├── Tests
       ├── Browser Verification
       ├── Documentation Update
       │
       ▼
   Pull Request
       │
       ├── Review
       └── Final Verification
       │
       ▼
      main
```

---

## Creating a Feature Branch

Before starting a new feature, update `main`.

```bash
git checkout main
git pull origin main
```

Create a feature branch:

```bash
git checkout -b feature/<feature-name>
```

Example:

```bash
git checkout -b feature/datafile-module
```

---

## Commit Rules

Use Conventional Commits.

Examples:

```text
feat: add DataFile model
fix: correct project validation
refactor: simplify report query
docs: update architecture documentation
test: add DataFile upload tests
chore: update development configuration
```

Commits should be:

- Small
- Focused
- Easy to review
- Logically complete

One commit should represent one logical change.

Do not commit unrelated changes together.

---

## Push Feature Branch

The first push of a new branch should set its upstream branch.

```bash
git push -u origin feature/<feature-name>
```

After that:

```bash
git push
```

---

## Pull Request

All feature branches should be merged through GitHub Pull Requests.

Example:

```text
feature/datafile-module
        ↓
    Pull Request
        ↓
       main
```

Use `.github/pull_request_template.md` when creating a Pull Request.

Before creating or merging a Pull Request, confirm:

- Tests pass.
- Browser verification is complete when UI is affected.
- Coding Standards are followed.
- No unnecessary abstraction was introduced.
- No debug code remains.
- ADR documents are updated when architecture decisions change.
- README and product documentation are updated when necessary.
- Database migrations have been verified when applicable.

---

## Merge Strategy

Preferred merge method:

```text
Create a merge commit
```

This keeps the feature branch history visible and makes each feature or sprint easy to identify in Git history.

Avoid merging feature branches directly into `main` from the local environment unless there is a specific operational reason.

The normal flow is:

```text
Feature Branch
      ↓
Pull Request
      ↓
Review
      ↓
GitHub Merge
      ↓
main
```

---

## After Merge

After the Pull Request has been merged, synchronize the local `main` branch.

```bash
git checkout main
git pull origin main
```

Confirm:

```bash
git status
```

The local `main` branch should be clean and synchronized with `origin/main`.

---

## Branch Cleanup

After a feature branch has been merged and is no longer needed, it may be deleted.

Delete the remote branch through GitHub or:

```bash
git push origin --delete feature/<feature-name>
```

Delete the local branch:

```bash
git branch -d feature/<feature-name>
```

Example:

```bash
git branch -d feature/project-module
```

Do not delete an unmerged branch unless its work is intentionally being discarded.

---

## Release Branches

ReportFlow AI does not use long-lived release branches during the current development phase.

Releases are created directly from the stable `main` branch.

If the release process becomes more complex in the future, this strategy may be reviewed through an ADR.

---

## Hotfixes

For urgent production fixes, create a fix branch from `main`.

```bash
git checkout main
git pull origin main
git checkout -b fix/<issue-name>
```

After verification:

```text
fix/*
  ↓
Pull Request
  ↓
main
  ↓
Patch Release
```

Example:

```text
v0.3.0
   ↓
fix/report-generation-error
   ↓
v0.3.1
```

---

## Principles

- `main` must remain releasable.
- Do not develop features directly on `main`.
- One branch should represent one logical feature or fix.
- Keep branches short-lived.
- Use Pull Requests for integration.
- Keep Git history understandable.
- Do not mix unrelated changes.
- Release only from `main`.
- Prefer simple Git workflows over unnecessary branching complexity.
