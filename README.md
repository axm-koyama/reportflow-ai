# ReportFlow AI

ReportFlow AI is an enterprise AI backend platform built with Laravel.

The platform is designed to support business-oriented AI workflows such as:

- Data upload
- AI analysis
- Structured output
- HTML report generation
- PDF export

Future modules may include:

- Knowledge management
- RAG
- AI agents
- Prompt management
- Workflow automation
- AI chat

---

## Tech Stack

- Laravel 13
- PHP 8.5
- MySQL 8.4 LTS
- Redis
- Nginx
- Docker

---

## Project Structure

```text
app/
├── Actions
├── Queries
├── Validators
├── Enums
├── Models
└── Http
    ├── Controllers
    └── Requests
```

The application follows a lightweight business-oriented structure.

Responsibilities are separated by use case rather than introducing unnecessary service or repository layers.

---

## Architecture

ReportFlow AI follows a lightweight business-oriented architecture.

```text
HTTP
 |
 v
Controller
 | \
 |  \
 v   v
Action   Query
(write)  (read)
   \      /
    \    /
     v  v
     Model
       |
       v
    Database
```

### Controller

Controllers are responsible for HTTP and view orchestration.

Controllers should:

- Receive HTTP requests
- Use FormRequests
- Call Actions for write operations
- Call Queries for read operations
- Return responses, redirects, or views

Controllers should not contain business logic or complex Eloquent queries.

### Action

Actions represent application write use cases.

Examples:

```text
CreateProjectAction
UpdateProjectAction
UploadDataFileAction
```

Actions coordinate business rules, persistence, and other operations required to complete a use case.

### Query

Queries represent application read operations.

Examples:

```text
ListProjectsQuery
ListDataFilesQuery
```

Queries keep retrieval, ordering, filtering, and pagination logic out of Controllers.

### Validator

Validators handle format-specific or structural validation that does not belong in HTTP FormRequests.

Example:

```text
CsvFileValidator
```

Validators must remain focused on validation and must not perform persistence or storage operations.

### Model

Eloquent Models represent persisted application entities and their relationships.

Models should not become containers for Controller, presentation, or orchestration logic.

---

## Current Modules

### Project

The Project module manages report automation projects.

Current functionality:

- Project creation
- Project editing
- Project status management
- Project listing
- Soft deletion support
- Action / Query architecture

Project status currently supports:

```text
active
archived
```

An active Project may accept new DataFiles.

An archived Project cannot accept new DataFiles.

Detailed design:

```text
docs/product/PROJECT_MODULE.md
```

---

### DataFile

The DataFile module manages input files belonging to Projects.

Sprint 2 MVP functionality:

- CSV upload
- Maximum upload size of 10 MB
- HTTP upload validation
- Basic CSV structural validation
- Private file storage
- Application-generated storage file names
- DataFile metadata persistence
- Paginated DataFile listing
- Soft deletion support
- Upload error handling and storage cleanup

Uploaded files are stored privately.

Original user-provided file names are stored as metadata and are never used directly as physical storage paths.

The MVP supports CSV only.

AI analysis, report generation, Excel, JSON, and PDF processing are intentionally outside the DataFile module's current scope.

Detailed design:

```text
docs/product/DATAFILE_MODULE.md
```

---

## Current Application Flow

```text
Browser
   │
   ▼
Project
   │
   ▼
Data Files
   │
   ▼
CSV Upload
   │
   ▼
StoreDataFileRequest
   │
   ▼
CsvFileValidator
   │
   ▼
UploadDataFileAction
   │
   ├── Project business validation
   ├── Private file storage
   ├── DataFile creation
   └── Cleanup on persistence failure
   │
   ▼
DataFile
   │
   ▼
MySQL / Private Storage
```

Read operations follow the Query path:

```text
Browser
   │
   ▼
Controller
   │
   ▼
Query
   │
   ▼
Model
   │
   ▼
Database
```

---

## Design Principles

ReportFlow AI follows these principles:

- Keep Controllers thin
- Use Actions for write use cases
- Use Queries for read operations
- Use FormRequests for HTTP validation
- Separate format-specific validation from HTTP validation
- Keep business logic out of Blade views
- Keep presentation-only data out of persisted Model attributes
- Prefer Laravel conventions
- Avoid unnecessary Service and Repository layers
- Avoid speculative abstractions
- Introduce interfaces only when a real abstraction requirement exists
- Keep modules focused on their own responsibilities
- Add complexity only when a concrete requirement requires it

---

## Local Development

After pulling code or config changes, restart the queue worker
(`php artisan queue:restart`, then relaunch `php artisan queue:work` —
there is no process supervisor auto-restarting it in this project's
`docker compose` setup yet). A long-running worker process keeps
already-loaded classes and config in memory and will silently continue
executing the old code otherwise. See
`docs/product/ANALYSIS_JOB_MODULE.md` "Operational runbook: restart the
queue worker after every code/config deploy" for the incident that
surfaced this (Phase 4-C).

---

## Development Documentation

Architecture and development rules are documented under:

```text
docs/
├── adr/
├── architecture/
├── development/
└── product/
```

Important documents include:

```text
docs/architecture/APPLICATION_ARCHITECTURE.md
docs/development/CODING_STANDARDS.md
docs/development/DEVELOPMENT_FLOW.md
docs/development/BRANCH_STRATEGY.md
docs/development/RELEASE_PROCESS.md
docs/product/PROJECT_MODULE.md
docs/product/DATAFILE_MODULE.md
```

---

## Development Status

### v0.1.0

Project Module MVP.

Implemented:

- Project domain model
- Project status
- Project creation
- Project update
- Project listing
- Action / Query architecture
- Blade UI
- Feature tests

### Sprint 2

DataFile Module MVP.

Implemented:

- DataFile domain model
- Project → DataFile relationship
- CSV upload
- Upload validation
- CSV structural validation
- Private storage
- DataFile metadata persistence
- DataFile listing
- Pagination
- Blade UI
- Feature tests

AI analysis is intentionally deferred to a later module.

---

## Future Direction

The intended platform workflow is:

```text
Project
   │
   ▼
DataFile
   │
   ▼
AnalysisJob
   │
   ▼
AI Analysis
   │
   ▼
Report
   ├── Markdown
   ├── HTML
   └── PDF
```

Each module should remain focused on its own responsibility.

DataFile manages input files.

AnalysisJob will manage analysis execution.

Report will manage generated outputs.
