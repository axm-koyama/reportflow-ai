> Code is written once but read many times.
>
> Always optimize for readability, consistency, and long-term maintainability.

# ReportFlow AI Coding Standards

## Purpose

This document defines the coding standards for ReportFlow AI.

The goal is to keep the codebase:

- Readable
- Maintainable
- Consistent
- AI-friendly
- Easy to review

Unless there is a clear reason, all implementations should follow these standards.

---

# PHP

- PHP 8.5+
- Always use strict typing.

```php
<?php

declare(strict_types=1);
```

- Always specify parameter types.
- Always specify return types.
- Prefer readonly where appropriate.
- Prefer constructor property promotion.

---

# Laravel

- Laravel 13
- Follow Laravel conventions unless the project defines otherwise.
- Prefer framework features over custom implementations.
- Keep Controllers thin.
- Keep Models simple.

---

# Directory Structure

```
Controller
    ↓
Service (or Action)
    ↓
Repository (only when necessary)
    ↓
Model
```

Avoid introducing unnecessary layers.

Do not introduce Repository or Service unless there is a concrete business need.

---

# Eloquent Model Rules

## General

- Use PHPDoc for model properties.
- Use `protected $fillable`.
- Do not use `#[Fillable]`.
- Use `protected function casts(): array`.
- The return type annotation of `casts()` should be `array<string, mixed>`.
- Use PHP Enum for all business statuses.
- Use `SoftDeletes` for business entities unless there is a clear reason not to.
- Use explicit `$primaryKey` when not using Laravel's default `id`.
- Prefer nullable types over magic values.
- Keep business logic out of Models.

Example:

```php
/**
 * @return array<string, mixed>
 */
protected function casts(): array
{
    return [
        'status' => ProjectStatus::class,
    ];
}
```

## Relationships

- Always declare relationship return types.
- Keep relationship methods focused on relationships only.
- Do not place business logic inside relationship methods.

## Enum

Always use Enum for business status.

Good

```php
ProjectStatus::Active
```

Bad

```php
'active'
```

Never compare status using string literals.

Good

```php
if ($project->status === ProjectStatus::Active) {
}
```

Bad

```php
if ($project->status === 'active') {
}
```

---

# Migration Rules

- Every table should have timestamps unless there is a clear reason not to.
- Business entities should support SoftDeletes.
- Foreign keys should use constrained() where possible.
- Use comments for business tables and important columns.
- Use Enum values as strings in the database.
- Avoid nullable unless the field is truly optional.

---

# Controller Rules

Controllers should only:

- Receive Request
- Validate Request
- Call Service (or Action)
- Return Response

Do not place business logic inside Controllers.

---

# Service Rules

- One Service (or Action) should have one responsibility.
- Services should not know HTTP details.
- Services should not return Response objects.
- Services should return business results only.

---

# Validation Rules

Always use FormRequest.

Do not validate directly inside Controllers.

---

# Enum Rules

Every business status should have its own Enum.

Examples

- ProjectStatus
- AnalysisStatus
- ReportStatus
- DataFileStatus

---

# Naming

## Model

Singular

```
Project
Report
AnalysisJob
```

## Table

Plural

```
projects
reports
analysis_jobs
```

## Primary Key

- Keep Laravel authentication tables (`users`) using the default `id`.
- Business entities should use explicit primary keys.

Examples:

- project_id
- report_id
- analysis_job_id
- data_file_id

Framework-owned models (e.g. User) should remain as close as possible to Laravel defaults.

Business models should follow the project's coding standards.

---

# Database

- Never store business status as integer.
- Store Enum values as string.
- Use foreign keys.
- Avoid duplicated data.

---

# Error Handling

- Never swallow exceptions.
- Throw domain-specific exceptions when appropriate.
- Log unexpected exceptions.

---

# PHPDoc

- All Models should define `@property` annotations.
- All generic return types should use precise PHPDoc.
- Use `array<string, mixed>` for `casts()`.
- Use `list<string>` for indexed string arrays.
- Prefer specific generic types over `array`.

---

# Testing

- Every business feature should have tests.
- Prefer Feature Tests for business behavior.
- Unit Tests for isolated business logic.

---

# Git

Use Conventional Commits.

Examples

```
feat:
fix:
refactor:
docs:
test:
chore:
```

Keep commits small.

One commit should represent one logical change.

---

# AI Coding Rules

Claude Code should:

- Read CLAUDE.md first.
- Follow ADR documents.
- Follow Coding Standards.
- Never modify architecture without approval.
- Never introduce unnecessary abstraction.
- Keep implementations simple.
- Do not commit or push automatically.

---

# Development Principles

- Readability over cleverness.
- Simplicity over abstraction.
- Business first.
- Framework second.
- Consistency over personal preference.
- Optimize for long-term maintenance.

---

# Review Checklist

Before submitting code:

- Architecture follows ADR.
- Coding Standards are followed.
- No duplicated business logic.
- No magic strings.
- Enum used where appropriate.
- Proper PHPDoc.
- Proper type declarations.
- No unnecessary abstraction.
- Tests pass.