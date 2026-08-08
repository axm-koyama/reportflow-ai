# ADR-0002: Domain Modeling Principles

## Status

Accepted

## Context

ReportFlow AI is designed as an enterprise AI backend platform.

To keep the domain model maintainable and extensible, the following design principles are adopted.

## Decisions

### 1. Project is the Aggregate Root

All business resources belong to a Project.

Examples:

- DataFile
- AnalysisJob
- Report

### 2. Each entity owns its own lifecycle

Project status:

- active
- archived

AnalysisJob status:

- queued
- processing
- completed
- failed

Report status should be managed independently.

### 3. Business status and deletion are separated

Business lifecycle is represented by `status`.

Logical deletion is represented by Laravel SoftDeletes (`deleted_at`).

### 4. Enum for all statuses

All business statuses should be implemented using PHP Enum.

### 5. Avoid over-engineering

Do not introduce abstractions unless there is a concrete business requirement.

Business entities use explicit primary keys.

Framework-owned tables (such as `users`) keep Laravel defaults for compatibility.

## Business Layer

Separate business operations into Commands and Queries.

### Actions

Actions are responsible for write operations.

Examples:

- CreateProjectAction
- UpdateProjectAction
- ArchiveProjectAction
- DeleteProjectAction
- UploadCsvAction
- AnalyzeDataAction
- GenerateReportAction

Actions may:

- Create data
- Update data
- Delete data
- Dispatch jobs
- Execute transactions
- Call external APIs
- Coordinate multiple models

Actions should not:

- Return HTTP responses
- Access Request objects
- Render Views

### Queries

Queries are responsible for read operations.

Examples:

- ListProjectsQuery
- SearchProjectsQuery
- DashboardQuery
- ReportSummaryQuery

Queries should:

- Read data only
- Never modify data
- Encapsulate complex query logic
- Return business data

### Controller Responsibility

Controllers should remain thin.

Controllers should delegate:

- write operations to Actions
- read operations to Queries

### Keep Business Logic Out of Controllers
Controllers should only:

- Receive HTTP Requests
- Delegate to Actions or Queries
- Return Responses

Controllers should not contain business logic.
