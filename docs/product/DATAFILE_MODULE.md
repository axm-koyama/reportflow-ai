# DataFile Module

## Purpose

DataFile represents an input file uploaded to a Project for later analysis.

DataFile is designed as a general-purpose input file entity.

The MVP supports CSV files only, but the domain model must not be coupled to CSV-specific concepts so that additional file types can be supported in the future.

Possible future file types include:

- CSV
- Excel
- JSON
- PDF

Additional formats must not be accepted until explicit support has been implemented.

---

## MVP Scope

Sprint 2 provides the following functionality:

- Upload a CSV file to a Project
- Validate the uploaded file
- Perform basic CSV structural validation
- Store the uploaded file using Laravel Filesystem
- Create a DataFile record
- List DataFiles belonging to a Project
- Display basic file metadata
- Paginate DataFile listings
- Handle upload and validation errors
- Clean up stored files when persistence fails

AI analysis is not part of Sprint 2.

---

## Domain Responsibility

DataFile represents the uploaded input file itself.

Its responsibilities are limited to:

- File ownership
- File storage information
- Basic file metadata
- Logical deletion

DataFile must not contain:

- AI analysis logic
- Analysis execution state
- Report generation logic
- Business-specific CSV schema definitions
- Presentation-only formatting logic

These responsibilities belong to other layers or modules.

---

## Fields

The initial `data_files` table contains:

```text
data_file_id
project_id
original_name
stored_path
mime_type
size
created_at
updated_at
deleted_at
```

### data_file_id

Primary key.

ReportFlow AI uses explicit business primary key names.

```text
data_file_id
```

### project_id

Foreign key referencing the owning Project.

Every DataFile must belong to exactly one Project.

### original_name

Original file name provided during upload.

Used for display and metadata purposes only.

The original file name must not be used directly as the physical storage path.

### stored_path

Application-generated private storage path.

The application controls the physical storage location independently from the original file name.

### mime_type

Detected MIME type of the uploaded file.

MIME type is stored as metadata.

CSV files may be detected as values such as:

```text
text/csv
text/plain
```

depending on the runtime environment and file contents.

MIME type alone must not be treated as sufficient proof that the file is a valid CSV.

### size

File size in bytes.

The MVP maximum upload size is:

```text
10 MB
```

### created_at / updated_at

Laravel timestamps.

### deleted_at

Used for logical deletion through Laravel SoftDeletes.

---

## Status

DataFile does not have a business status in the MVP.

The upload process is synchronous:

```text
Upload
  ↓
Validation
  ↓
CSV Basic Validation
  ↓
Store File
  ↓
Create DataFile
```

A DataFile record is created only after the file has passed the required validation and has been successfully stored.

Therefore, statuses such as:

```text
uploaded
ready
failed
```

are not required at this stage.

A status may be introduced later if DataFile gains a meaningful asynchronous lifecycle.

Do not add status fields only for anticipated future requirements.

---

## Relationships

### Project

```text
Project
  has many
DataFiles
```

### DataFile

```text
DataFile
  belongs to
Project
```

A DataFile may later have many AnalysisJobs.

Future relationship:

```text
Project
   1
   │
   └────── N
           DataFile
              1
              │
              └────── N
                      AnalysisJob
```

The AnalysisJob relationship is architectural context only and is not part of Sprint 2 implementation.

---

## Aggregate Boundary

Project is the business parent of DataFile.

A DataFile cannot exist without a Project.

```text
Project
   │
   └── DataFile
```

DataFile belongs to the Project context but remains responsible only for file-related information.

AI processing must not be added to the DataFile model.

---

## Migration Design

The `data_files` table stores metadata for uploaded input files that belong to a Project.

The table is designed for the current CSV upload MVP while remaining neutral enough to support additional file formats in the future.

### Table

```text
data_files
```

### Columns

```text
data_file_id
project_id
original_name
stored_path
mime_type
size
created_at
updated_at
deleted_at
```

### data_file_id

```php
$table->id('data_file_id');
```

### project_id

```php
$table->foreignId('project_id')
    ->constrained('projects', 'project_id')
    ->restrictOnDelete();
```

A DataFile cannot exist without a Project.

`restrictOnDelete()` is used instead of cascading physical deletion.

Project normally uses SoftDeletes, so normal Project deletion does not physically delete the database row.

If a Project is force-deleted in the future, the database should prevent deletion while related DataFiles still exist.

This avoids accidental physical deletion of file metadata.

### original_name

```php
$table->string('original_name', 255);
```

The original file name is stored for display and metadata purposes only.

It must not be used directly as the physical storage path.

### stored_path

```php
$table->string('stored_path', 512);
```

A length of 512 allows storage directory structures to evolve without coupling the schema to the current path format.

Current structure:

```text
projects/{project_id}/data-files/{uuid}.csv
```

The application controls the storage path independently from the original file name.

### mime_type

```php
$table->string('mime_type', 255);
```

The detected MIME type is stored as metadata.

CSV files may be reported differently depending on the environment, so MIME type alone must not be treated as sufficient validation.

### size

```php
$table->unsignedBigInteger('size');
```

The Sprint 2 upload limit is 10 MB.

The database uses an unsigned BIGINT because DataFile is intended to remain usable for future supported formats and larger limits.

### Timestamps

```php
$table->timestamps();
```

### Soft Delete

```php
$table->softDeletes();
```

Logical deletion and physical file deletion are separate concerns.

Soft-deleting a DataFile does not automatically remove the physical file from storage.

Physical file deletion is outside Sprint 2 scope.

---

## Migration Example

```php
Schema::create('data_files', function (Blueprint $table) {
    $table->comment('Uploaded input files belonging to projects.');

    $table->id('data_file_id');

    $table->foreignId('project_id')
        ->constrained('projects', 'project_id')
        ->restrictOnDelete()
        ->comment('Owning project ID');

    $table->string('original_name', 255)
        ->comment('Original uploaded file name');

    $table->string('stored_path', 512)
        ->comment('Application-generated private storage path');

    $table->string('mime_type', 255)
        ->comment('Detected MIME type');

    $table->unsignedBigInteger('size')
        ->comment('File size in bytes');

    $table->timestamps();
    $table->softDeletes();
});
```

---

## Index Strategy

No additional indexes are required for the initial Sprint 2 implementation.

`project_id` is indexed through the foreign key definition.

The primary expected query is:

```text
WHERE project_id = ?
ORDER BY created_at DESC, data_file_id DESC
```

If performance requirements emerge later, a composite index may be considered.

```php
$table->index(['project_id', 'created_at']);
```

Do not add this index until there is a concrete performance need.

---

## Deletion Rules

Deletion behavior is intentionally conservative.

### Project Soft Delete

```text
Project SoftDelete
    ↓
DataFile records remain
```

Soft deletion does not trigger foreign key deletion behavior.

### Project Force Delete

```text
Project ForceDelete
    ↓
Related DataFiles exist
    ↓
Deletion is restricted
```

The application must explicitly handle related DataFiles before physically deleting a Project.

### DataFile Soft Delete

```text
DataFile SoftDelete
    ↓
Database row remains
    ↓
Physical file remains
```

Physical file cleanup may be introduced later as a separate application use case.

---

## Project Status and Upload

Project business status is not enforced at the database layer.

The business rule is:

```text
active Project
    → DataFile upload allowed

archived Project
    → DataFile upload not allowed
```

This rule belongs to the application/business layer.

`UploadDataFileAction` enforces the rule before file storage begins.

The Blade UI may hide the upload form for an archived Project for usability, but UI behavior must not be relied upon as the business-rule enforcement mechanism.

---

## Upload Rules

The MVP accepts CSV files only.

Upload requirements:

- File is required
- Input must be an uploaded file
- Original file name must be available
- File extension must be `.csv`
- Maximum file size is 10 MB
- File size must be greater than 0 bytes
- File must be readable as CSV
- File must contain a non-empty header row
- File must belong to an existing Project
- Project must be active

CSV validation must not rely only on MIME type.

MIME type is metadata and may differ between runtime environments.

---

## File Validation Architecture

File validation is separated into three responsibilities.

### StoreDataFileRequest

`StoreDataFileRequest` validates HTTP upload constraints.

Responsibilities:

- File is required
- Input must be an uploaded file
- Original file name must be available
- File extension must be `.csv`
- Maximum file size is 10 MB
- File size must be greater than 0 bytes

The FormRequest must not parse CSV contents.

It exposes the validated upload to the Controller through a typed accessor:

```text
uploadedFile(): UploadedFile
```

This prevents Laravel's broader file input return type from leaking into the Controller and Action boundary.

### CsvFileValidator

`CsvFileValidator` validates CSV-specific content and basic structure.

Responsibilities:

- CSV file can be opened
- CSV file can be read
- CSV contains a header row
- Header row contains at least one non-empty value

The validator must not:

- Store the file
- Create DataFile records
- Validate Project status
- Perform AI analysis
- Validate business-specific columns

Future validators may include:

```text
ExcelFileValidator
JsonFileValidator
PdfFileValidator
```

Do not introduce a shared Validator interface or Validator factory until multiple concrete validators create a real abstraction requirement.

### UploadDataFileAction

`UploadDataFileAction` coordinates the DataFile upload use case.

```text
Project business validation
        ↓
CsvFileValidator
        ↓
File metadata validation
        ↓
Private Storage
        ↓
DataFile creation
        ↓
Cleanup on persistence failure
```

Responsibilities:

- Ensure the Project is active
- Invoke `CsvFileValidator`
- Read required file metadata
- Generate the physical storage file name
- Store the file using Laravel Filesystem
- Create the DataFile record
- Remove the stored file if persistence fails
- Re-throw the original persistence failure after cleanup

The Action must not contain CSV parsing logic.

### Responsibility Boundary

```text
StoreDataFileRequest
= Is this a valid HTTP upload?

CsvFileValidator
= Is this structurally valid CSV content?

UploadDataFileAction
= Can this validated file be accepted and persisted as a DataFile?
```

Business-specific data validation belongs to later processing or analysis layers.

---

## CSV Basic Validation

Sprint 2 performs basic structural validation only.

Expected flow:

```text
Uploaded File
     ↓
HTTP Upload Validation
     ↓
CSV Readability Check
     ↓
Header Row Check
     ↓
Project Business Validation
     ↓
Private Storage
     ↓
DataFile Creation
```

The purpose of this validation is to confirm that the file can be treated as an input CSV.

The DataFile module does not validate business-specific columns.

For example, DataFile must not require columns such as:

```text
customer_id
sales
date
email
```

Business-specific schema validation belongs to a later processing or analysis layer.

---

## File Size

Maximum upload size:

```text
10 MB
```

Application-level validation enforces this limit.

Infrastructure-level limits such as PHP and Nginx must also allow the configured application upload size.

Infrastructure limits may be higher than the business validation limit.

---

## Storage

Uploaded files are stored using Laravel Filesystem.

Direct filesystem operations should not be used when Laravel Storage provides the required functionality.

The DataFile model stores the storage path, not the file binary.

Files must not be stored directly in a publicly accessible directory.

Current storage structure:

```text
storage/app/private/
└── projects/
    └── {project_id}/
        └── data-files/
            └── {uuid}.csv
```

The storage file name is generated by the application.

Sprint 2 uses a UUID-based file name with an explicit `.csv` extension.

This avoids relying on MIME-based extension guessing.

For example, a valid CSV may be detected as:

```text
text/plain
```

but is still stored as:

```text
{uuid}.csv
```

Requirements:

- Storage path is generated by the application
- Stored file name uses a generated UUID
- Sprint 2 stored file extension is `.csv`
- Original file name is preserved separately as metadata
- User-controlled file names must not determine filesystem paths
- Stored files are private by default

---

## File Metadata

The MVP stores:

```text
original_name
stored_path
mime_type
size
```

CSV-specific processing metadata is intentionally excluded.

Examples not stored in the MVP:

```text
row_count
column_count
encoding
headers
delimiter
```

These fields should only be introduced when concrete processing requirements require them.

---

## Upload Failure and Cleanup

A DataFile record must only be created after validation and storage succeed.

If request or CSV validation fails:

```text
Validation Failure
      ↓
No DataFile
      ↓
No accepted stored DataFile
```

If storage succeeds but database persistence fails:

```text
File Stored
     ↓
DataFile Creation Failure
     ↓
Delete Stored File
     ↓
Re-throw Original Exception
```

Cleanup belongs to `UploadDataFileAction`, not the Controller.

The application must not intentionally leave orphan files when DataFile persistence fails.

---

## Queries

Sprint 2 read operation:

```text
ListDataFilesQuery
```

Responsibilities:

- Retrieve DataFiles belonging to a Project
- Exclude soft-deleted DataFiles through the normal Eloquent scope
- Order newest files first
- Apply stable secondary ordering
- Paginate results
- Keep read logic out of the Controller

Current ordering:

```text
created_at DESC
data_file_id DESC
```

Current page size:

```text
20
```

Future queries may include:

```text
FindDataFileQuery
SearchDataFilesQuery
```

Do not implement them until required.

---

## Controller Responsibility

`DataFileController` remains thin.

Current methods:

```text
index
store
```

### index

Responsibilities:

- Receive the Project through Route Model Binding
- Call `ListDataFilesQuery`
- Prepare minimal presentation data
- Return the DataFile Blade view

Presentation-only file size formatting may remain local to the Controller for the Sprint 2 MVP.

It must not be added as a persisted or domain attribute to the DataFile model.

If file-size formatting becomes reused across multiple screens, extraction into a shared presentation/support component may be considered at that time.

### store

Responsibilities:

- Receive `StoreDataFileRequest`
- Obtain the typed `UploadedFile`
- Call `UploadDataFileAction`
- Redirect to the DataFile list
- Return a success flash message

The Controller must not:

- Perform CSV parsing
- Perform Storage operations
- Create DataFile records directly
- Implement Project upload business rules
- Contain complex Eloquent queries

---

## Routes

Sprint 2 exposes DataFile operations beneath Project.

```text
GET  /projects/{project}/data-files
POST /projects/{project}/data-files
```

Route names:

```text
projects.data-files.index
projects.data-files.store
```

DataFile is treated as a resource belonging to a Project.

---

## Blade UI

The Sprint 2 DataFile screen provides:

- Project name
- Project status
- CSV upload form
- Upload requirements
- Validation errors
- DataFile listing
- Original file name
- MIME type
- Human-readable file size
- Upload timestamp
- Pagination
- Back-to-Projects navigation

For archived Projects, the upload form is not displayed.

This is a usability rule only.

The authoritative upload restriction remains in `UploadDataFileAction`.

Blade must not contain:

- Database queries
- Storage operations
- CSV parsing
- Business-specific validation

---

## Business Rules

- Every DataFile must belong to one Project.
- A DataFile cannot exist without a Project.
- Only active Projects may accept new DataFiles.
- Only successfully validated and stored files create DataFile records.
- Soft-deleted DataFiles must not appear in normal queries.
- Business status and logical deletion are separate concepts.
- DataFile must not contain AI analysis logic.
- DataFile must not contain report generation logic.
- DataFile must not define business-specific CSV schemas.
- DataFile must not contain presentation-only formatting logic.
- Original file names must not be trusted as storage paths.
- Unsupported file formats must not be accepted.
- MIME type alone must not determine whether a file is valid CSV.

---

## MVP User Flow

```text
Project
   │
   ▼
Project DataFiles
   │
   ▼
Select CSV
   │
   ▼
Upload
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
   ├── Project validation
   ├── Metadata validation
   ├── Private storage
   ├── DataFile creation
   └── Failure cleanup
   │
   ▼
DataFile List
```

---

## Error Handling

Validation errors are returned against the uploaded `file` input where appropriate.

Examples:

```text
The file must not be empty.
The CSV file could not be read.
The CSV file must contain a header row.
```

If validation fails:

- No DataFile record is created
- The user receives a validation error
- Invalid input does not become a normal stored DataFile

If persistence fails after storage:

- The stored file is removed where possible
- The original exception is re-thrown
- The exception is not silently swallowed

A custom exception hierarchy is not introduced until a concrete requirement justifies it.

---

## Soft Delete

DataFile uses Laravel SoftDeletes.

Normal queries exclude soft-deleted records.

Physical file deletion behavior is not part of Sprint 2.

Logical deletion and physical storage deletion are separate concerns.

---

## Security Considerations

For the MVP:

- Uploaded files are private
- Original file names are not trusted as storage paths
- Physical storage names are application-generated
- File size is limited to 10 MB
- Unsupported formats are rejected
- Files are structurally checked before becoming DataFile records
- MIME type alone is not trusted as CSV validation
- Archived Projects cannot accept new DataFiles

More advanced security mechanisms may be introduced when required.

---

## Testing

Sprint 2 includes Feature Tests covering the DataFile HTTP workflow.

Important behaviors include:

- DataFile list can be displayed
- Only DataFiles belonging to the requested Project are shown
- Soft-deleted DataFiles are excluded
- Valid CSV files can be uploaded
- Original file metadata is persisted
- Physical storage paths are application-generated
- Invalid file types are rejected
- Empty files are rejected
- Invalid CSV structures are rejected
- Archived Projects cannot accept uploads
- DataFile ordering is stable
- Pagination works at 20 records per page
- Storage is isolated during tests

Feature Tests should exercise the real HTTP → Request → Action/Query → Model/Storage flow where practical rather than excessively mocking internal classes.

---

## Out of Scope

The following are not included in Sprint 2:

- AI analysis
- AnalysisJob execution
- Report generation
- Excel upload
- Excel parsing
- JSON upload
- PDF upload
- PDF parsing
- Automatic schema inference
- Business-specific column validation
- Data transformation
- Data cleansing
- Column mapping
- Encoding conversion
- Multi-file analysis
- Virus scanning
- External object storage such as S3 or GCS
- File sharing
- Authentication changes
- Authorization / Policy
- Company management
- Tenant management
- Physical file deletion
- DataFile status lifecycle

---

## Future Direction

The intended future workflow is:

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

DataFile must remain focused on input file management even as the analysis platform evolves.

AnalysisJob should own analysis execution state.

Report should own generated output.

---

## Design Principles

- Keep DataFile format-neutral at the domain level.
- Support CSV only where the current MVP requires it.
- Keep HTTP validation separate from file-content validation.
- Keep file-content validation separate from upload orchestration.
- Do not introduce lifecycle state without a real state transition.
- Keep file management separate from AI analysis.
- Keep business-specific data rules separate from generic file management.
- Keep presentation logic out of persisted Model attributes.
- Prefer Laravel conventions.
- Prefer simple implementation over speculative abstraction.
- Do not introduce interfaces or factories without a real abstraction requirement.
- Add complexity only when a concrete requirement requires it.
