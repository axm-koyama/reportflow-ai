# Release Process

## Purpose

This document defines the release process for ReportFlow AI.

The goal is to ensure that every release is:

- Tested
- Traceable
- Reproducible
- Documented
- Releasable from `main`

---

## Release Principle

Every completed milestone should leave the `main` branch in a releasable state.

The standard release flow is:

```text
Feature Branch
      ↓
Pull Request
      ↓
Review
      ↓
Merge to main
      ↓
Final Verification
      ↓
Version Tag
      ↓
GitHub Release
```

---

## Versioning

ReportFlow AI uses Semantic Versioning.

Format:

```text
MAJOR.MINOR.PATCH
```

Git tags use the `v` prefix:

```text
v0.1.0
v0.2.0
v0.2.1
v1.0.0
```

### MAJOR

Increment the MAJOR version for significant breaking changes.

Example:

```text
v1.0.0 → v2.0.0
```

### MINOR

Increment the MINOR version when adding a new feature or major business capability.

Example:

```text
v0.1.0 → v0.2.0
```

### PATCH

Increment the PATCH version for bug fixes or small backward-compatible corrections.

Example:

```text
v0.2.0 → v0.2.1
```

During initial development, ReportFlow AI may remain under `v0.x.x`.

---

## Current Release Roadmap

The current planned release progression is:

```text
v0.1.0  Project Module
v0.2.0  DataFile / Data Upload
v0.3.0  AI Analysis
v0.4.0  HTML Report
v0.5.0  PDF Export
v1.0.0  ReportFlow AI MVP
```

This roadmap is a guideline and may change as the product evolves.

---

## Pre-Release Checklist

Before creating a release, confirm the following.

### Code

- [ ] Feature implementation is complete.
- [ ] Coding Standards are followed.
- [ ] No unnecessary abstraction was introduced.
- [ ] No debug code remains.
- [ ] No temporary development code remains.

### Tests

- [ ] All automated tests pass.
- [ ] Feature Tests pass.
- [ ] Browser verification is complete when UI is affected.
- [ ] Important validation behavior has been verified.

Run:

```bash
docker compose exec app php artisan test
```

### Database

When database changes exist:

- [ ] Migration has been executed successfully.
- [ ] Database structure has been verified.
- [ ] Existing data impact has been considered.
- [ ] Rollback behavior has been reviewed where applicable.

Example:

```bash
docker compose exec app php artisan migrate
```

### Documentation

- [ ] README is updated when necessary.
- [ ] Product documentation is updated when necessary.
- [ ] ADR is updated when an architectural decision changes.
- [ ] Coding Standards are updated when a new permanent rule is introduced.
- [ ] Development documentation is updated when the workflow changes.

### Git

- [ ] Feature branch is pushed.
- [ ] Pull Request is created.
- [ ] Pull Request has been reviewed.
- [ ] All intended changes are included.
- [ ] Unrelated changes are not included.

---

## Merge to Main

Feature branches should normally be merged through GitHub Pull Requests.

Preferred merge method:

```text
Create a merge commit
```

After the Pull Request is merged, synchronize the local `main` branch.

```bash
git checkout main
git pull origin main
```

Confirm:

```bash
git status
```

The working tree should be clean.

---

## Final Verification

Before creating a release tag, perform a final verification from `main`.

Run:

```bash
docker compose exec app php artisan test
```

When necessary, also verify the application in the browser.

Example:

```text
http://localhost:18080
```

Do not create a release tag if the final verification fails.

---

## Create Release Tag

Release tags must be created from `main`.

Confirm the current branch:

```bash
git branch
```

Create an annotated tag:

```bash
git tag -a <version> -m "<release-name>"
```

Example:

```bash
git tag -a v0.1.0 -m "Project Module MVP"
```

Push the tag:

```bash
git push origin v0.1.0
```

---

## Verify Tag

Confirm the tag locally:

```bash
git tag
```

Confirm the tagged commit:

```bash
git show v0.1.0
```

The tag must point to the intended release commit on `main`.

---

## GitHub Release

After pushing the tag, create a GitHub Release using that tag.

Example release title:

```text
v0.1.0 - Project Module MVP
```

Release notes should contain:

- Summary
- Added features
- Technical changes
- Database changes
- Testing
- Known limitations

---

## Release Notes Template

```markdown
# vX.Y.Z - Release Name

## Summary

Brief description of this release.

## Added

- Feature A
- Feature B

## Changed

- Change A
- Change B

## Database

- Migration details
- Schema changes

## Testing

- Automated tests passed
- Feature Tests passed
- Browser verification completed

## Known Limitations

- Limitation A
- Limitation B
```

---

## v0.1.0 Example

```markdown
# v0.1.0 - Project Module MVP

## Summary

Initial business module release for ReportFlow AI.

This release establishes the application architecture and the first Project module.

## Added

- Project list
- Project creation
- Project editing
- Project status management
- Action Pattern for write operations
- Query Pattern for read operations
- Blade UI
- Feature Tests

## Technical Changes

- Thin Controller architecture
- PHP Enum for business status
- SoftDeletes for Project
- FormRequest validation
- Explicit business primary key

## Database

- Added `projects` table

## Testing

- Automated tests passed
- Feature Tests passed
- Browser verification completed
- Project creation and update verified against MySQL

## Known Limitations

- Authentication is not implemented yet
- Authorization / Policy is not implemented yet
- Project deletion and restoration are not implemented yet
```

---

## Patch Release

Bug fixes should normally be released as PATCH versions.

Example:

```text
v0.2.0
   ↓
Bug discovered
   ↓
fix/report-generation-error
   ↓
Pull Request
   ↓
main
   ↓
v0.2.1
```

The patch release follows the same verification and release process as other releases.

---

## Rollback

If a release introduces a serious issue:

1. Identify the affected release and commit.
2. Determine whether application code, database changes, or both are affected.
3. Revert the problematic merge commit when appropriate.
4. Handle database rollback carefully.
5. Run automated tests.
6. Perform browser verification when applicable.
7. Merge the fix through a Pull Request.
8. Create a new PATCH release.

Do not rewrite published Git history.

Do not move or overwrite an existing published release tag.

For example, if `v0.2.0` contains a problem, fix it and release:

```text
v0.2.1
```

Do not replace the existing `v0.2.0` tag.

---

## Release History

Release history is tracked using Git tags and GitHub Releases.

Examples:

```text
v0.1.0  Project Module MVP
v0.2.0  DataFile / Data Upload
v0.3.0  AI Analysis
```

Each release should clearly describe what changed and what was verified.

---

## Principles

- Every release must come from `main`.
- `main` must remain releasable.
- Every release must have a version tag.
- Published tags must not be rewritten.
- Tests must pass before release.
- Database changes must be verified before release.
- Architecture changes must be documented.
- Release notes should be concise but useful.
- Prefer small, understandable releases over large unpredictable releases.
