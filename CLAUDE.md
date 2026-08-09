# ReportFlow AI

## Tech Stack

Laravel 13

PHP 8.5

MySQL 8.4

Redis

Docker

Blade

Tailwind

---

## Architecture

Controller

↓

Application

↓

Domain

↓

Infrastructure

---

## Coding Rules

PSR-12

PHPStan

Pest

Conventional Commits

---

## AI Rules

Do not modify architecture.

Always write tests.

Follow ADR.

## Product Direction

ReportFlow AI is designed as an extensible enterprise AI backend platform.

The report automation feature is the first business module, not the entire product.

Future modules may include:
- Knowledge
- RAG
- Agent
- Prompt Management
- Workflow
- Chat

Do not introduce platform-wide abstractions unless they are justified by an actual implemented use case.
Avoid over-engineering for hypothetical future features.
