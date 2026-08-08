# Project Module

## Purpose

Project is the root business entity of ReportFlow AI.

A project groups uploaded data, AI analyses, and generated reports.

## MVP Features

- Create project
- List projects
- View project
- Update project

## Fields

- project_id
- name
- description
- status
- created_at
- updated_at
- deleted_at

## Status

- active
- archived

Project status represents the business lifecycle of a project.
Deletion is handled separately via Laravel SoftDeletes (`deleted_at`).

## Relationships

Project
- has many DataFiles
- has many AnalysisJobs
- has many Reports

## Out of Scope

- Multi-user ownership
- Organization
- Project sharing
- Permissions
