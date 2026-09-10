# ReportFlow AI v1.1 — UI Polish (Design)

## 1. Metadata

- Status: Designed (investigation + design only; no Product code changed by this document)
- Target branch: `design/reportflow-v1-1-ui-polish`
- HEAD at investigation time: `3badfdba5716f96eccbb3637c6d3df93698c3e79`
- Created: 2026-09-10
- Author context: Claude Code, investigation performed against the running local Docker stack (`reportflow-app`, `reportflow-nginx` on `http://localhost:18080`, `reportflow-mysql`, `reportflow-redis`) using pre-existing synthetic Product Validation fixtures, read-only.

### Purpose of v1.1

Turn the existing, functionally-complete ReportFlow AI screens into a coherent, presentable **portfolio artifact**: one visual language, one navigable path through the product journey, and status/empty/error states that read clearly to someone who has never seen the app before. v1.1 adds **no new business capability** — every change is Blade markup, CSS, and (at most) trivial display-only Blade formatting logic.

### Out of scope (restated from the request)

Authentication/user management, billing, PDF export, Report sharing, external connectors, prediction, churn/sleep-user prediction, budget optimization, any new AI processing, any new Queue processing, any new DB table, and any change to existing business logic (Mapping, Evaluation, Diagnosis, Priority, Controlled Action, Report generation contracts). See §15 for the full restated list.

---

## 2. Current UI Inventory

All routes below are read from [routes/web.php](../../routes/web.php). The root `/` route is included because it was explicitly in scope to investigate, even though it is not part of the ReportFlow AI product surface today.

| # | Route (name) | Controller method | Blade | 画面目的 | 主操作 | Status / empty state | 現在の問題（要約。詳細は§4） | Browser確認 |
|---|---|---|---|---|---|---|---|---|
| 0 | `GET /` (no name) | closure in `routes/web.php` → `view('welcome')` | [resources/views/welcome.blade.php](../../resources/views/welcome.blade.php) | None — this is Laravel's default scaffold page | Links to Laravel docs / Laracasts / Cloud | N/A | Not a ReportFlow AI screen at all; the product has no real landing page | ✅ Browser (`http://localhost:18080/`) |
| 1 | `projects.index` (`GET /projects`) | `ProjectController::index` | [projects/index.blade.php](../../resources/views/projects/index.blade.php) | List all Projects | Create Project; per-row: Data Files / Analysis History / Edit | Empty: "No projects yet." row; pagination at 20/page | Table/action-link inconsistency; badge reuses "success green" semantics; no breadcrumb | ✅ Browser |
| 2 | `projects.create` (`GET/POST /projects`) | `ProjectController::create`/`store` | [projects/create.blade.php](../../resources/views/projects/create.blade.php) | Create a Project | Submit / Cancel | Validation errors via `$errors->any()` block | Generic error list, no per-field association | ⚠️ Code only (form not submitted, to avoid writing test data) |
| 3 | `projects.edit` (`GET /projects/{project}/edit`, `PUT`) | `ProjectController::edit`/`update` | [projects/edit.blade.php](../../resources/views/projects/edit.blade.php) | Edit a Project incl. status (Active/Archived) | Update / Cancel | Same as above | Archiving has no confirmation/warning about downstream effects (new DataFile/Analysis/Report creation becomes blocked) | ⚠️ Code only |
| 4 | `projects.data-files.index` (`GET /projects/{project}/data-files`) | `DataFileController::index` | [data-files/index.blade.php](../../resources/views/data-files/index.blade.php) | List DataFiles for a Project + upload | Upload CSV; per-row Analyze | Empty: "No data files uploaded yet." row; Archived: no upload form, "Unavailable" per row | Native unstyled file input; empty state has no visual weight | ✅ Browser (populated + empty + archived) |
| 5 | (embedded in #4) | (embedded in #4) | (embedded in #4) | "Data Fileアップロード" has no dedicated screen — it is a form embedded at the top of the DataFile list | Upload | — | Confirmed: no separate upload screen exists; documented here so it is not designed as if it were separate | ✅ Browser |
| 6 | `projects.data-files.analysis-jobs.create` (`GET`) | `AnalysisJobController::create` | [analysis-jobs/create.blade.php](../../resources/views/analysis-jobs/create.blade.php) | Start a new Analysis (Free or Template) | Start Analysis | Validation via `$errors->any()` | Template picker mixes an English label ("Prompt") with Japanese helper copy; no visual separation between "Free Analysis" and "Template" modes | ⚠️ Code only |
| 7 | `projects.data-files.analysis-jobs.store` (`POST`) | `AnalysisJobController::store` | (redirects to #9) | Submit new Analysis | — | — | — | N/A (POST only) |
| 8 | `projects.analysis-jobs.index` (`GET /projects/{project}/analysis-jobs`) | `AnalysisJobController::index` | [analysis-jobs/index.blade.php](../../resources/views/analysis-jobs/index.blade.php) | Analysis History — rediscover/monitor jobs | View Details; Review Mapping; Create Recovery Attempt; View Recovery Attempt | Empty: dedicated `.card` with a link back to Data Files (best empty state in the app) | 7-column table is dense; recovery lineage links use plain unstyled anchors | ✅ Browser (Completed, Failed, Pending, Processing, AwaitingMappingConfirmation all observed) |
| 9 | `projects.analysis-jobs.show` (`GET /projects/{project}/analysis-jobs/{analysisJob}`) | `AnalysisJobController::show` | [analysis-jobs/show.blade.php](../../resources/views/analysis-jobs/show.blade.php) | Analysis detail / live status; also where Failed-recovery and Controlled Actions are surfaced | Review Mapping (if waiting); Create Recovery Attempt (if Failed); Generate/View HTML Report; (Completed) read Summary/Metrics/Tables/Insights/Evaluation/Diagnosis/Controlled Actions | Pending/Processing: plain text + 5s meta-refresh; AwaitingMappingConfirmation: card + CTA; Failed: `alert-error` + recovery CTA or "recovery attempts can only be created for an active project" | Long vertical `<dl>` metadata block pushes content down; duplicated info density; long table cells overflow on mobile (confirmed); "確認優先度: High" badge reuses the same green as "Completed" | ✅ Browser (all 5 statuses) |
| 10 | `projects.analysis-jobs.recover` (`POST .../recover`) | `AnalysisJobController::recover` | (redirects to #9) | Create a Failed-analysis recovery attempt | — | Flash message on redirect | — | N/A (POST only) |
| 11 | `projects.analysis-jobs.mapping.edit` (`GET .../mapping`) | `AnalysisJobController::editMapping` | [analysis-jobs/mapping.blade.php](../../resources/views/analysis-jobs/mapping.blade.php) | Confirm/override AI column mapping before resuming Analysis | Select column per field; Submit | Only reachable while `AwaitingMappingConfirmation`; otherwise redirects back to #9 with a flash | Required-field marker is a bare `*` with only a `title` tooltip (not visible without hover); AI confidence shown as a raw fraction/decimal with no explanation | ✅ Browser |
| 12 | `projects.analysis-jobs.mapping.update` (`PATCH`) | `AnalysisJobController::updateMapping` | (redirects to #9 or back to #11) | Submit confirmed mapping | — | `withErrors(['mapping' => ...])` + `withInput()` on missing required fields | Error surfaces as a single generic string, not per-row | ⚠️ Code only |
| 13 | `projects.analysis-jobs.reports.store` (`POST .../report`) | `ReportController::store` | (redirects to #14) | Generate (or open existing) HTML Report | — | Flash: "Report generated." / "Opening the existing report." | — | N/A (POST only; not exercised in this session to avoid creating new rows — pre-existing Reports were viewed instead) |
| 14 | `projects.reports.show` (`GET /projects/{project}/reports/{report}`) | `ReportController::show` | [reports/show.blade.php](../../resources/views/reports/show.blade.php) + [reports/partials/document.blade.php](../../resources/views/reports/partials/document.blade.php) | View the immutable HTML Report | Back to Analysis; Analysis History | Absence semantics per §7 of `HTML_REPORT_MODULE.md` (not applicable / unavailable / eligible-but-unavailable, all distinct) | Duplicate `<h1>` (outer layout + inner document partial both render the report title); raw ISO‑8601 timestamps (`2026-09-10T06:15:58.000000Z`) shown instead of the app's normal `Y-m-d H:i:s`; no `@media print` rules at all; "Advisory only — not executed" renders as a bare, uncoloured `<span class="badge">` | ✅ Browser, including a Japanese/HTML-escaping fixture report (`<script>`, `<img onerror>`, `& ' " < >` all confirmed rendered as inert escaped text) |

**Screens explicitly requested but confirmed not to exist as separate routes/Blades** (documented, not designed as if real): a Dashboard/initial screen, a standalone Data File upload screen, a standalone Failed Analysis Recovery screen, and a standalone Controlled Actions screen. Their content is investigated above as sections embedded in existing screens.

---

## 3. Current Design System Inventory

Everything below is read from [resources/views/layouts/app.blade.php](../../resources/views/layouts/app.blade.php), the only stylesheet that actually reaches these screens (a single inline `<style>` block; no build step, no Blade component library).

| Aspect | Current state |
|---|---|
| Layout | One layout, `layouts.app`: fixed `<div class="container">` (max-width 960px, `margin: 2rem auto`), a `.page-header` flex row (`h1` + `@yield('actions')`), a single global `@if (session('success'))` banner, then `@yield('content')`. |
| Navigation | No persistent nav bar, no breadcrumb component. Each page hand-rolls 0–2 "back"/"related" links inside `@section('actions')` as `.btn`/`.btn-secondary`. |
| Container width | 960px fixed max-width for every screen, including the Report (a document meant to be read, and containing wide tables). |
| Typography | System font stack (`-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif`). Only `h1` (1.5rem) and headings **inside `.card`** (`.card h2` 1.1rem, `.card h3` 0.95rem) have explicit sizes. Headings outside a `.card` — every heading in `reports/partials/document.blade.php` — fall back to raw browser UA defaults. |
| Color | Hand-picked hex literals, no CSS custom properties/tokens. Background `#f7f7f8`; text `#1b1b18`; cards/tables `#fff`. No `:root` variables beyond `color-scheme: light dark` (which has no effect since every color is hardcoded, not `light-dark()`-aware). |
| Spacing | Ad hoc rem values (`0.15rem`, `0.25rem`, `0.5rem`, `0.75rem`, `1rem`, `1.5rem`, `2rem`) with no shared scale/tokens. |
| Button | `.btn` (solid `#1b1b18`, white text, 4px radius) and `.btn-secondary` (`#e5e5e5` bg). No disabled, danger, or loading state. Plain `<a>` tags used for the same "go do a thing" purpose in several places (e.g. "View Details", "Analyze") render as unstyled default blue/underlined browser links — no `a { color: ... }` rule exists anywhere in the stylesheet. |
| Form | `input[type=text]`, `textarea`, `select` share one rule (full width, 1px `#d4d4d8` border, 4px radius). `input[type=file]` is **not** styled — it renders as the bare OS file-picker control (confirmed in Browser). No focus-ring override (native focus outline is preserved — a good accessibility boundary, see §10). |
| Table | One global `table`/`th`/`td` rule: white background, 6px radius via `overflow:hidden`, subtle shadow, 0.875rem text. No `overflow-x` wrapper anywhere — confirmed in Browser (mobile) that a long unbroken table cell wraps character-by-character into an extremely tall, unreadable column instead of scrolling horizontally. |
| Card | `.card` (white, 6px radius, 1rem padding, subtle shadow) is the one structural building block used for every section on Analysis detail, Reports (partially), and empty/informational blocks. |
| Badge | 13 fixed classes (`.badge-active`, `.badge-archived`, `.badge-pending`, `.badge-processing`, `.badge-awaiting-mapping-confirmation`, `.badge-completed`, `.badge-failed`, `.badge-high`, `.badge-medium`, `.badge-low`, `.badge-insufficient_data`) plus a bare `.badge` (no color) used only for the Controlled Action "Advisory only — not executed" label. **Confirmed color collision:** `.badge-active`, `.badge-completed`, and `.badge-high` (a 確認優先度/Priority band, meaning "review this soon") are byte-identical CSS (`background:#dcfce7;color:#166534`) — the same green means "this is fine" and "this needs your attention" depending on context. |
| Alert | `.alert-success` (green) and `.alert-error`/`.errors` (red) — flat colored blocks, no icon, no border accent beyond the fill color. |
| Empty state | Plain text, either a lone `<td colspan="N">` inside an otherwise-empty table, or (Analysis History only) a `.card` with one sentence and a link. No icon/illustration anywhere. |
| Pagination | Laravel's default `tailwind.blade.php` pagination partial (`{{ $projects->links() }}` etc., `PER_PAGE = 20` on every list query). **Confirmed via code**: this partial emits Tailwind utility classes, and `layouts/app.blade.php` never loads Tailwind's compiled CSS (only `resources/views/welcome.blade.php`, via `@vite`, does) — so whenever a list exceeds 20 rows, the pager will render with zero styling. Not reproduced visually this session because no seeded list in the local database exceeds 20 rows (confirmed via `AnalysisJob::count()` = 20 on the largest seeded project). |
| Responsive breakpoint | None. No `@media` queries exist in `layouts/app.blade.php` at all. `.page-header`'s `display:flex; justify-content:space-between` does not stack on narrow viewports (confirmed in Browser at 375px: a long Japanese title wraps to 3 lines while the action buttons stay pinned beside it). |
| Print/report style | None. No `@media print` block exists anywhere in the codebase (`grep -rn "@media print" resources/` returned nothing). |

---

## 4. Confirmed UI Problems

Every item below was verified either by reading the shipped Blade/CSS/Controller/Enum source, or by loading the actual page in the Browser against the running `reportflow-app` container with pre-existing synthetic Product Validation fixtures (Projects 26, 29, 31, 32, 35). Nothing here is a guess about behavior that wasn't exercised.

### Navigation
- **[High]** `GET /` renders Laravel's default scaffold (`welcome.blade.php` — "Let's get started", Laravel branding, links to laravel.com/laracasts), not any ReportFlow AI screen. Confirmed in Browser. For a portfolio piece, this is the first thing anyone sees.
- **[Medium]** No breadcrumb exists anywhere. The only way to know "which Project am I in" on deep screens (Analysis detail, Mapping, Report) is the page title text and one or two hand-placed "back" links; the Report screen in particular has no path back to the owning Project or its Data Files, only "Back to Analysis" / "Analysis History".
- **[Low]** Recovery-lineage links ("Recovered from #100", "View Recovery Attempt") are plain `<a>` tags with no visual grouping to distinguish "this is a link to a different AnalysisJob" from the row's other text.

### Information hierarchy
- **[Medium]** `analysis-jobs/show.blade.php`'s metadata `<dl>` (Data File / Template / Prompt / Status / Created / Started / Completed — up to 7 label/value pairs) is rendered as one tall vertical stack before any of the actual analysis content appears, confirmed via screenshot: the HTML Report action and Summary section are pushed below the fold on a standard viewport for jobs with a template and a long prompt.
- **[Medium]** The Report's own metadata block (`reports/partials/document.blade.php`'s `<dl>`) duplicates several of the same facts (AnalysisJob id, mode, DataFile, timestamps) immediately below the outer `reports/show.blade.php` "Immutable HTML Report · Generated …" line, with no visual distinction between "facts about this Report" and "facts about its source AnalysisJob".

### Visual consistency
- **[High]** Confirmed color collision: `badge-active`, `badge-completed`, and `badge-high` share identical CSS (`#dcfce7`/`#166534`). A "High" 確認優先度 (a signal to *look at this*) is visually identical to "Active"/"Completed" (signals that things are fine). This directly conflicts with the "status/warning/success/failureを色だけに依存させない" and "priority ≠ execution" intent already documented in `PRIORITY_ENGINE.md`.
- **[Medium]** No shared color for plain links: `.btn`/`.btn-secondary` render as solid pill buttons, but every other actionable link ("View Details", "Analyze", "Review Mapping", "Edit", "Data Files"/"Analysis History" in table cells) is an unstyled default `<a>` (blue, underlined on hover per browser UA styles) — two incompatible link languages on the same page.
- **[Medium]** Headings outside `.card` (all headings in the Report document partial) have no defined size and fall back to inconsistent browser defaults, while the rest of the app uses the `.card h2`/`.card h3` scale — the Report, the one screen meant to "read like a document," has the least controlled typography in the app.
- **[Low]** Mixed English/Japanese labels on the same screen without a pattern (e.g. Analysis detail shows "HTML Report", "Summary", "Metrics", "Controlled Actions" in English beside "使用テンプレート", "原因の仮説", "確認優先度" in Japanese). Confirmed via Browser; not something v1.1 will fully re-localize (see §14), but the *inconsistency itself* — not the presence of two languages — is a polish problem worth partially addressing (§5, §7).

### Form usability
- **[Medium]** Mapping confirmation's required-field marker is a bare `*` whose only explanation is an HTML `title` attribute (`title="必須"`), invisible without a mouse hover; "(グループ必須)" is small, low-contrast `.hint` text easy to miss. Confirmed via Browser screenshot.
- **[Low]** `input[type=file]` is entirely unstyled (native OS control), sitting next to fully custom text/select inputs on the same form (Data Files upload).
- **[Low]** AI mapping confidence is shown as a raw value (e.g., a decimal) with a `title="AI confidence"` tooltip and no visible label/legend explaining the scale.

### Table readability
- **[High]** Confirmed in Browser at a 375px viewport: a long, unbroken table cell (from the Japanese/HTML-escaping fixture) wraps character-by-character into a column dozens of lines tall instead of scrolling horizontally, because no table anywhere is wrapped in an `overflow-x` container.
- **[Medium]** The Evaluation table (8 columns including Entity/Metric/Value/Baseline/Difference/Direction/Evaluation/確認優先度) and the Mapping table are dense with no zebra striping, sticky header, or column-width control, making longer tables harder to scan at a glance.

### Status communication
- **[High]** (Same root cause as the Visual consistency finding above, called out separately because it is a "status communication" failure, not just a palette inconsistency.) High/Medium/Low 確認優先度 badges currently borrow the exact same green/amber/gray family used for Project/AnalysisJob status, so "Completed" (outcome) and "High priority to check" (a call to attention) look identical.
- **[Medium]** The Controlled Action's mandated "Advisory only — not executed" label renders with the bare `.badge` class, which defines shape/padding but **no color at all** — it is the single most safety-critical string in the app (it is what stops a viewer from thinking the AI executed something) and it currently has the *least* visual weight of any badge on the page. Confirmed via `document.querySelectorAll('.badge')` in the running page: `<span class="badge">Advisory only — not executed</span>` next to `<span class="badge badge-high">High</span>`.

### Empty/error states
- **[Medium]** The DataFile empty state ("No data files uploaded yet.") and the Project empty state ("No projects yet.") are a single plain `<td>` with no icon, no suggested next action, and no visual separation from a populated table's chrome — confirmed via Browser on Project 29 ("AHV20260907 Empty Project Check"). Analysis History's empty state (a `.card` with a sentence and a link) is comparatively better and is the pattern worth generalizing (§6).
- **[Low]** Validation error blocks (`.errors`) render every message as a flat `<ul>` with no association to the specific field that failed (confirmed via code reading of `projects/create.blade.php`, `projects/edit.blade.php`, `analysis-jobs/create.blade.php`; not re-triggered live this session to avoid submitting forms/writing data during a design-only investigation).

### Responsive behavior
- **[High]** `.page-header`'s `display:flex; justify-content:space-between` does not stack at narrow widths. Confirmed via Browser at 375px on Analysis detail: a long Japanese title wraps across 3 lines while "Back to Analysis History"/"Data Files" buttons stay pinned to the top-right, visually competing with the title.
- **[High]** (Same evidence as the Table readability finding above.) No table has a horizontal-scroll wrapper, so wide/long-content tables become vertically-exploded, unreadable columns on mobile rather than scrolling sideways.

### Accessibility
- **[Confirmed good boundary]** No `outline: none` (or any outline override) exists anywhere in the stylesheet — native keyboard focus rings are preserved on every interactive element.
- **[Confirmed good boundary]** Every `<label for="...">`/`<input id="...">` pair checked (`projects/create.blade.php`, `projects/edit.blade.php`, `analysis-jobs/create.blade.php`) is correctly associated.
- **[Medium]** Zero `aria-*` attributes and zero `role="..."` attributes exist anywhere in `resources/views/` (confirmed via `grep`). The success/error banners are plain `<div>`s with no `role="status"`/`role="alert"`, so a screen reader user gets no notification when a flash message or validation error appears without a full page reload cue.
- **[Medium]** The Report page renders two `<h1>` elements for the same text: the outer layout's `@yield('title')` and the inner `reports/partials/document.blade.php`'s own `<h1>{{ $snapshot['source']['title'] }}</h1>` — confirmed by reading both files; both render the same Report title.
- **[Low]** No `<nav>`, `<main>`, or `<header>` landmark elements are used anywhere (`layouts/app.blade.php` uses a plain `<div class="container">`), so a screen reader's landmark navigation offers nothing to jump between page chrome and page content.

### Report readability
- **[Medium]** Confirmed via Browser: the Report page shows raw ISO-8601 timestamps with microseconds and a trailing `Z` (e.g. `2026-09-10T06:15:58.000000Z`, `2026-09-10T06:20:24.589410Z`) for "Completed At"/"Generated At", while every other screen in the app formats the same kind of value as `Y-m-d H:i:s` (e.g. `2026-09-10 15:15:58` on the Analysis detail page for the very same job). This is a pure display inconsistency; `HTML_REPORT_MODULE.md` §7.1/§7.3 does not mandate a specific display string, only which fields are in the snapshot.
- **[Confirmed good boundary]** Confirmed at the raw HTTP response byte level (this session, Report #9): `<`, `>`, `&`, `'`, and `"` are individually HTML-entity-escaped in every tested field (summary, highlights, a metric label, a long/malicious table cell, an insight title, an Evaluation entity_key reused across sections). `<script>`, `<img onerror>`, and `<svg onload>` payloads all rendered as inert visible text, never executed. Zero JS console errors, zero alert/dialog events.
- **[Medium]** No `@media print` styles exist, so printing the report (a natural "read this later"/portfolio action) currently prints the app's screen chrome (page header, "Back to Analysis"/"Analysis History" buttons, the "Immutable HTML Report · Generated …" hint line) exactly as displayed, with no page-break control around long tables/sections.
- **[Low]** The duplicate `<h1>` (see Accessibility) also affects Report readability: the Report's title appears twice, once in the browser chrome-styled outer `page-header` and once again as a document heading immediately below it.

No Critical-severity issue was found: nothing observed lets a viewer believe an advisory-only Controlled Action was executed, nothing leaks raw AI response/prompt/priority score/self-reported confidence (all confirmed absent from every Blade file read), and cross-Project 404 boundaries were not touched by this investigation's UI-only scope.

---

## 5. v1.1 Visual Direction

The direction below is a **refinement** of the existing look (dark neutral buttons, light card surfaces, the existing badge color families), not a redesign. It is expressed as CSS custom properties added to the *same* plain, build-free stylesheet approach the app already uses (see §6 for exactly where).

### Product identity
Keep the current restrained, "internal enterprise tool" tone — dark neutral primary actions, light neutral background, no gradients, no illustrations, no motion beyond a simple `opacity`/`transform` transition already acceptable for hover states. The one deliberate "AI service" accent is a single indigo accent color, used sparingly (links, focus rings, the primary nav/breadcrumb accent) — never as a full-bleed background, never a gradient.

### Page background
`--rf-bg: #F6F7F9` (was `#f7f7f8` — effectively unchanged, defined as a token instead of a literal).

### Surface / card
`--rf-surface: #FFFFFF`; `--rf-border: #E4E4E7`; `--rf-shadow-sm: 0 1px 2px rgba(16,24,40,0.06)`; `--rf-shadow-md: 0 1px 3px rgba(16,24,40,0.10), 0 1px 2px rgba(16,24,40,0.06)` (close to the current `0 1px 3px rgba(0,0,0,.08)` — kept nearly identical to avoid an unnecessary visual delta).

### Text hierarchy
`--rf-text: #1B1B18` (unchanged), `--rf-text-secondary: #5B6270` (was `#6b7280`, adjusted only to reach a comfortable AA contrast on white for the smallest `.hint` size), `--rf-text-muted: #8A8F98` for de-emphasized metadata labels.

### Primary / accent color
Primary action button stays the existing solid dark neutral: `--rf-primary: #1B1B18` / `--rf-primary-contrast: #FFFFFF` (unchanged — this is already distinctive and works; no reason to introduce risk here). New accent for links, focus rings, and the new breadcrumb/nav affordance: `--rf-accent: #4F46E5` (indigo-600), `--rf-accent-hover: #4338CA`. This is the only new hue introduced anywhere in the palette.

### Semantic colors (status families — kept separate from the priority family, see below)
Reuse the existing, already-reasonable badge hues as **named tokens** instead of literals, unchanged in value:
- Success/Active/Completed: `--rf-success-bg:#DCFCE7; --rf-success-fg:#166534;`
- Pending: `--rf-pending-bg:#FEF3C7; --rf-pending-fg:#92400E;`
- Processing: `--rf-info-bg:#DBEAFE; --rf-info-fg:#1E40AF;`
- Awaiting Mapping Confirmation ("waiting for you"): `--rf-waiting-bg:#EDE9FE; --rf-waiting-fg:#5B21B6;`
- Failed: `--rf-danger-bg:#FEE2E2; --rf-danger-fg:#991B1B;`
- Archived / insufficient data (neutral): `--rf-neutral-bg:#EFEFEF; --rf-neutral-fg:#52525B;`

### Semantic colors — 確認優先度 (Priority), intentionally a *different* family from status
This directly fixes the §4 "Visual consistency"/"Status communication" High findings. Priority bands must never reuse the status-success green:
- High (review first): `--rf-priority-high-bg:#FFEDD5; --rf-priority-high-fg:#9A3412;` (amber-orange — "attention", not "danger" red, and not "success" green)
- Medium: `--rf-priority-medium-bg:#FEF9C3; --rf-priority-medium-fg:#854D0E;`
- Low: `--rf-priority-low-bg:#F3F4F6; --rf-priority-low-fg:#4B5563;`
- Not eligible / unavailable: continue to render as plain secondary text (`-`, "取得できませんでした"), never a colored badge — this is already correct and must not change.

### Advisory-only label — a distinct, deliberately non-status chip
New token, used only for the fixed "Advisory only — not executed" string: `--rf-advisory-bg:#EEF2FF; --rf-advisory-fg:#3730A3; --rf-advisory-border:#C7D2FE` (outlined chip, indigo family — matches the new accent, reads as "informational", never green/red/amber so it cannot be mistaken for a status or a priority band). Rendered as an outlined pill with a small "ⓘ" glyph rather than a plain filled badge, so it is visually distinct in *shape*, not only in color, satisfying "not dependent on color alone."

### Border / radius / shadow
`--rf-radius-sm: 6px` (buttons/badges, was 4px), `--rf-radius-md: 10px` (cards/tables, was 6px) — a small, low-risk softening. Shadows as defined above (essentially unchanged values, now tokenized).

### Typography scale
Two related but distinct scales, both driven by the same font stack:
- **App/operational scale** (Projects, Data Files, Analysis screens — dense, scannable): `--rf-text-xs:12px; --rf-text-sm:14px (default); --rf-text-base:15px; --rf-h3:16px; --rf-h2:18px; --rf-h1:24px;` — a direct tokenization of what's already in use, with the missing bare `h2`/`h3` (outside `.card`) now defined globally so no heading anywhere falls back to browser defaults.
- **Report/prose scale** (`.html-report` only — meant to be read, not scanned): body `16px`/`1.6` line-height, `h1:28px`, `h2:20px`, `h3:17px` — slightly larger and more relaxed than the operational scale, matching "Reportは読み物として見やすくする."

### Spacing scale
`--rf-space-1:4px; --rf-space-2:8px; --rf-space-3:12px; --rf-space-4:16px; --rf-space-5:24px; --rf-space-6:32px; --rf-space-7:48px;` — a direct naming of the rem values already in informal use (`0.25rem…2rem` ≈ 4px…32px), so existing spacing does not need to move, only to gain names Codex can reuse consistently instead of re-guessing a value per file.

### Button hierarchy
- Primary (`.btn`): unchanged solid dark fill — the single "do the main thing" action per section (Create Project, Upload, Start Analysis, Generate HTML Report, Create Recovery Attempt).
- Secondary (`.btn-secondary`): unchanged light-gray fill — navigation/"go back" actions.
- **New: Link-style action** (`.btn-link`, using `--rf-accent`): for the currently-unstyled bare `<a>` actions ("View Details", "Analyze", "Review Mapping", "Edit", table-cell navigation links) so they read as *deliberately* lower-emphasis than a `.btn`, rather than accidentally unstyled.
- No new destructive/danger button is introduced — v1.1 adds no delete/destroy action, so none is needed.

### Badge design
One shared `.badge` base (shape/padding/weight, unchanged), plus the modifier classes above renamed to draw from tokens rather than literals. The bare, colorless `.badge` (currently misused for the advisory label) is retired in favor of the explicit `.badge-advisory` outlined variant.

### Table design
Every `<table>` gets wrapped in a new `.table-scroll { overflow-x: auto; }` container (see §6) — this alone fixes the High-severity mobile table finding without touching table markup itself. Optional light zebra striping (`tbody tr:nth-child(even) { background: #FAFAFA }`) for the two densest tables (Evaluation, Mapping).

### Empty state
Generalize the Analysis History pattern (a `.card`, one sentence, one link) into a shared `.empty-state` block used everywhere a list can be empty, adding a small neutral icon (a plain inline SVG glyph, no icon font/library) so an empty list is visually distinct from "the table is still loading" or "something broke."

### Responsive behavior
`.page-header` gains a `@media (max-width: 640px) { flex-direction: column; align-items: flex-start; gap: var(--rf-space-3); }` rule so the title and actions stack instead of competing for width. `.table-scroll` (above) is the responsive fix for tables. No other structural breakpoint is introduced — the app remains a single fluid column at every width, per "PCを主対象とし、基本的なスマートフォン表示にも対応."

---

## 6. Shared Component Plan

The guiding constraint is explicit: **do not over-engineer or introduce a component framework for this pass.** Every item below is either (a) a CSS-only class added to the existing single stylesheet, or (b) a small, presentation-only Blade `@component`/include with no business logic and no new data dependency — justified individually below. None of them touch a Controller, Action, Query, or Model.

| Component | Kind | Why it's justified now (not hypothetical) |
|---|---|---|
| Page header (title + actions) | Existing structure in `layouts.app`, CSS-only fix (`@media` stacking) | Already shared by every screen via `@yield`; no new component needed, just the responsive rule. |
| Breadcrumb | **New Blade partial**, `resources/views/components/breadcrumb.blade.php` | Every deep screen (Data Files → Analysis Create → Mapping → Analysis Detail → Report) currently has zero shared way to show "where am I", and the Report screen has no path back to its Project at all. A tiny, presentation-only partial (`@include`, or a Blade component if the team prefers `<x-breadcrumb :items="[...]" />` — either is acceptable; a component is slightly preferable since it avoids re-deriving `route()` calls per screen) removes duplicated ad hoc "back" links without introducing any state. |
| Section card | Existing `.card` class, unchanged | Already the shared building block; no new component needed. |
| Status badge | **New Blade component**, `<x-badge :variant="..." :label="..." />` | 13+ call sites currently hand-write `<span class="badge badge-{{ ... }}">{{ ... }}</span>` across 4 different Blade files with slightly different interpolation each time (some use `->badgeClass()`/`->label()` from the Enum, some use a raw string like `badge-{{ $fact->evaluation_level }}`). A single component removes the risk of a future edit touching only 3 of the 4 call sites and reintroducing an inconsistency — directly justified by the color-collision bug found in §4. |
| Empty state | **New Blade component**, `<x-empty-state :message="..." :action-label="..." :action-href="..." />` | Currently 3 different empty-state renderings (bare `<td>`, bare `<td>`, one nice `.card`) for the same underlying situation ("this list has nothing yet"). Generalizing the best existing pattern is a direct fix for a Confirmed problem, not a hypothetical one. |
| Alert (success/error) | Existing `.alert-success`/`.errors` classes, CSS-only token pass | Already shared via the layout's global success banner and the repeated `$errors->any()` block; add `role="status"`/`role="alert"` at the two call sites (layout + a small `@include('components.errors')` partial) rather than a full component, since the only change needed is an ARIA attribute and a token-based color. |
| Metric card | **Not introduced.** | Metrics currently render inline inside the existing "Metrics" `.card`/table. There is no confirmed reuse need beyond that one section; adding a dedicated component here would be exactly the kind of speculative abstraction the brief asks to avoid. |
| Data table / table-scroll wrapper | **New CSS-only class** `.table-scroll`, applied by wrapping existing `<table>` markup — no new Blade component | The fix is structural (an `overflow-x:auto` div) and needs no logic or props; a Blade component would add indirection with no benefit over a copy-pasted `<div class="table-scroll">…</div>`. |
| Action bar | Existing `@section('actions')` mechanism, unchanged | Already shared by every screen; no new component needed. |

Total new Blade components: **breadcrumb, badge, empty-state** (3), plus one small `errors` include for the ARIA attribute. This is deliberately the minimum needed to fix the Confirmed problems in §4 without introducing a general-purpose UI kit.

---

## 7. Screen-by-Screen Design

Every "変更予定ファイル" list is Blade/CSS/routes only, unless explicitly called out as a trivial display-only Blade formatting change (still no PHP class changes). No item in this section changes a Controller, Action, Query, Model, Enum, migration, or the Report/Mapping/Evaluation/Diagnosis/Priority/Action contracts.

### 0. Root `/`
- 表示優先順位: redirect immediately to the real product.
- Layout: none — a route-level `redirect()->route('projects.index')`.
- Primary action: N/A (immediate redirect).
- Secondary action: N/A.
- Status表示: N/A.
- Empty state: N/A.
- Mobile: N/A.
- 既存機能境界: this changes only `routes/web.php`'s `/` closure from `return view('welcome')` to a redirect; it adds no controller, no new template, and does not touch the (kept, unused-by-navigation) `welcome.blade.php` file itself.
- 変更予定ファイル: `routes/web.php`.

### 1. Projects index
- 表示優先順位: Project name/status first, then description, then metadata, then row actions.
- Layout: unchanged table + pagination; page-header gets the responsive stacking fix.
- Primary action: "Create Project" (`.btn`, top-right, unchanged).
- Secondary action: per-row Data Files / Analysis History (promoted to `.btn-link`) / Edit (`.btn-link`).
- Status表示: `<x-badge>` for Active/Archived, using the tokenized (unchanged-value) success/neutral colors.
- Empty state: `<x-empty-state message="No projects yet." action-label="Create Project" :action-href="route('projects.create')" />` replacing the current bare `<td>`.
- Mobile: table wrapped in `.table-scroll`.
- 既存機能境界: no change to `ListProjectsQuery`, pagination size, or ordering.
- 変更予定ファイル: `resources/views/projects/index.blade.php`, `resources/views/layouts/app.blade.php` (tokens/classes).

### 2. Project create / 3. Project edit
- 表示優先順位: form fields top-to-bottom (unchanged field order); errors surfaced immediately above the form.
- Layout: unchanged single-column form.
- Primary action: Create/Update Project (`.btn`).
- Secondary action: Cancel (`.btn-link`, was `.btn-secondary` — demoted since it's a "leave without saving" action, not equal in weight to the submit).
- Status表示 (edit only): the Status `<select>` is unchanged (still a plain dropdown — this is a Controlled, deliberate mutation the user is actively making, not a passive status display, so it correctly stays an input rather than becoming a badge).
- Empty state: N/A (forms).
- Mobile: full-width fields already stack naturally; no change needed beyond the shared token pass.
- 既存機能境界: no change to `StoreProjectRequest`/`UpdateProjectRequest` validation rules or `CreateProjectAction`/`UpdateProjectAction`.
- 変更予定ファイル: `resources/views/projects/create.blade.php`, `resources/views/projects/edit.blade.php`.

### 4/5. Data Files index (incl. embedded upload)
- 表示優先順位: Project name + status banner, then the upload form (if allowed), then the file table.
- Layout: unchanged; upload form and table remain on one screen (confirmed: this was never meant to be two screens).
- Primary action: "Upload" (`.btn`).
- Secondary action: per-row "Analyze" (`.btn-link`); "Data Files"/"Back to Projects" in the action bar stay as-is.
- Status表示: Project Active/Archived badge via `<x-badge>`.
- Empty state: `<x-empty-state message="No data files uploaded yet." />` (no action-label when uploading is disallowed for an archived Project; action-label="Upload a CSV file" scrolling to the form when active).
- Mobile: `.table-scroll` wrapper; the native file input keeps its OS-default appearance (styling a file input meaningfully typically requires JS-driven custom controls, which conflicts with "JavaScript依存を必要最小限にする" for a purely cosmetic gain — left as a Known Limitation, §14).
- 既存機能境界: no change to `UploadDataFileAction`, `StoreDataFileRequest`, or the 10MB/CSV constraints.
- 変更予定ファイル: `resources/views/data-files/index.blade.php`.

### 6. Analysis start (create)
- 表示優先順位: Project/DataFile context card first, then the form (Title → Template → Prompt).
- Layout: unchanged; add a visible divider/label between "choose a template" and "or write a free-form prompt" so the two modes read as a single choice rather than two independent fields.
- Primary action: "Start Analysis" (`.btn`).
- Secondary action: "Back to Data Files" (`.btn-link`).
- Status表示: N/A (nothing exists yet).
- Empty state: N/A.
- Mobile: unchanged single column.
- 既存機能境界: no change to `CreateAnalysisJobAction`, `CreateAnalysisJobRequest`, or `config/analysis_templates.php`.
- 変更予定ファイル: `resources/views/analysis-jobs/create.blade.php`.

### 11/12. Mapping confirmation
- 表示優先順位: template/DataFile context, explanatory paragraph, then the mapping table with required fields visually first-class.
- Layout: unchanged table-based form.
- Primary action: "このMappingで分析" (`.btn`).
- Secondary action: "戻る" (`.btn-link`).
- Status表示: replace the bare `*`/`title="必須"` with a small always-visible "必須" text chip (using the new `--rf-danger-fg`-toned but non-badge inline label, so it is never confused with a Failed status badge) next to the field label; keep "(グループ必須)" but raise its contrast slightly via the tokenized `--rf-text-secondary`.
- Empty state: N/A.
- Mobile: `.table-scroll` wrapper around the mapping table.
- 既存機能境界: no change to `ResolveEffectiveColumnMappingAction`, `BuildAnalysisTemplateColumnCandidatesAction`, or the manual-override request contract — only the always-visible "必須" text is new, and it reads the exact same `$isRequired`/`$inRequiredGroup` booleans already computed in the Blade `@php` block.
- 変更予定ファイル: `resources/views/analysis-jobs/mapping.blade.php`.

### 8. Analysis History (index)
- 表示優先順位: Project name, then a one-line hint, then the table (Analysis → Data File → Mode → Status → timestamps → Actions).
- Layout: unchanged table; page-header stacking fix.
- Primary action: none globally (this is a list, not a creation screen — Analysis creation happens from Data Files, unchanged).
- Secondary action: per-row View Details (`.btn-link`), Review Mapping (`.btn-link`, using the waiting/violet accent to visually pair with the AwaitingMappingConfirmation badge), Create/View Recovery Attempt (kept as `.btn`/`.btn-link` respectively, since "Create Recovery Attempt" is a real mutating action and deserves primary-button weight, while "View Recovery Attempt" is pure navigation).
- Status表示: `<x-badge>` for every status; AwaitingMappingConfirmation and Failed keep their existing, already-distinct violet/red hues (confirmed sufficient — see §4 "Confirmed good boundary" note under Priority, extended here) and are additionally distinguished from every Priority badge because the priority palette (§5) now shares no hue with any status badge.
- Empty state: unchanged — this is the one screen that already has a good pattern (`.card` + link); it becomes the template `<x-empty-state>` is generalized from.
- Mobile: `.table-scroll`.
- 既存機能境界: no change to `ListAnalysisJobsQuery` ordering/pagination or to the recovery/mapping route contracts.
- 変更予定ファイル: `resources/views/analysis-jobs/index.blade.php`.

### 9/10. Analysis detail (incl. embedded Failed/Recovery, embedded Controlled Actions)
- 表示優先順位: reorder the metadata `<dl>` into a single-line summary strip (Data File · Template · Status · Completed At) plus a "Prompt" disclosure only shown when non-empty, so the status/CTA area is visible without scrolling for every job, not only short-prompt ones. Failed/AwaitingMappingConfirmation/Processing/Pending states keep their current position (immediately below metadata) since they are correctly the single most important thing on the page in those states.
- Layout: unchanged section-by-`.card` structure (Summary, Highlights, Metrics, Tables, Insights, Recommendations, Evaluation, 原因の仮説, Controlled Actions) — content and order untouched, only spacing/typography tokens applied.
- Primary action: context-dependent, already correct — Review Mapping (waiting), Create Recovery Attempt (failed + active project), Generate HTML Report (completed, no report yet). No change to which action appears when.
- Secondary action: View HTML Report / View Recovery Attempt (`.btn-link` once a Report/attempt exists, matching the "this already happened, go look at it" semantics vs. "do a new thing").
- Status表示: `<x-badge>` for AnalysisJobStatus; 確認優先度 and Evaluation-level badges switch to the new, status-independent priority palette from §5 (this is the concrete fix for the High-severity color collision).
- Empty state: unchanged wording for every documented absence state (Diagnosis "取得できませんでした"/Priority "取得できませんでした"/Controlled Action's three-way distinction) — only their badge/text color tokens change, never their meaning or which of the three mutually-exclusive states is shown.
- Mobile: `.table-scroll` around the "使用した列", Evaluation, and any result "Tables" section tables; `.page-header` stacking fix (this is the exact screen where the 3-line-title/pinned-buttons problem was confirmed).
- 既存機能境界: no change to `AnalysisJobController::show()`, `DetermineDiagnosisEligibilityAction`, or `GetControlledActionViewDataQuery`; the Blade `@php` blocks that already compute `$diagnosisEligibility`/`$priorityEligibility`/`$eligibleFacts` are reused as-is.
- 変更予定ファイル: `resources/views/analysis-jobs/show.blade.php`.

### 14. HTML Report (show + document partial)
See §9 for the full content-level design; file-level summary here:
- 表示優先順位: Report title (once, not twice — see below) → source metadata → Summary → the rest of the persisted sections in their existing order.
- Layout: same single-column document; `.html-report` gets the prose typography scale from §5 and a slightly wider effective reading column than the 960px app container allows for wide tables (achieved via a CSS-only `max-width: none` + `.table-scroll` on report tables, not a layout/container change to the shared `layouts.app`).
- Primary action: none on the Report page itself (correctly so — it is a read-only artifact); "Back to Analysis" stays as the one wayfinding action, joined by a new breadcrumb (Project ▸ Analysis ▸ Report) for the Project-level link that is currently missing entirely.
- Secondary action: "Analysis History" (`.btn-link`).
- Status表示: N/A (a Report has no status of its own — it exists or it doesn't; this is already correct).
- Empty state: unchanged per-section absence text (not applicable / unavailable / eligible-but-unavailable), unchanged wording, tokenized colors only.
- Mobile: `.table-scroll` on every report table.
- 既存機能境界: **no change to `BuildReportSnapshotAction`, `RenderHtmlReportAction`, `snapshot_json`, `schema_version`, or `content_hash`.** The only content-level change is how already-present ISO-8601 strings are *formatted for display* inside `reports/partials/document.blade.php` (e.g. `\Carbon\Carbon::parse($snapshot['generated_at'])->format('Y-m-d H:i:s')`) — a pure Blade-template display change, not a snapshot/contract change, so `content_hash`/`schema_version` remain meaningful and unaffected (the hash is over `rendered_html`, and `rendered_html` is regenerated fresh at generation time from whatever the renderer currently emits — existing persisted Reports' `rendered_html`/`content_hash` are untouched, since v1.1 does not regenerate any existing Report; only the renderer used for *future* generations changes).
- Demote the document partial's own `<h1>` to `<h2>` to remove the duplicate top-level heading without touching the shared `layouts.app` (which every other screen also depends on for its single `<h1>`).
- 変更予定ファイル: `resources/views/reports/show.blade.php`, `resources/views/reports/partials/document.blade.php`.

---

## 8. Analysis Journey

v1.1 makes the existing journey **legible**, not different. No step is added, removed, or reordered.

```
Project (projects.index / .create / .edit)
   │  "Data Files" action
   ▼
Data File (projects.data-files.index — list + embedded upload)
   │  per-row "Analyze"
   ▼
Start Analysis (projects.data-files.analysis-jobs.create/.store)
   │  redirects to →
   ▼
Analysis Detail (projects.analysis-jobs.show)
   │
   ├─ if AwaitingMappingConfirmation → Mapping Confirmation (projects.analysis-jobs.mapping.edit/.update)
   │       │ confirm → dispatches ExecuteAnalysisJob → back to Analysis Detail (Processing)
   │       ▼
   ├─ Processing / Pending → (5s meta-refresh) → Analysis Detail
   │
   ├─ if Failed → Recovery CTA (projects.analysis-jobs.recover) → new AnalysisJob attempt → Analysis Detail (new job)
   │
   └─ if Completed → Analysis Result (same Analysis Detail screen: Summary/Metrics/Tables/Insights/Recommendations,
                       Evaluation, 原因の仮説, 確認優先度)
              │
              ├─ Controlled Action (embedded section, same screen — advisory-only, never a separate route)
              │
              └─ Generate/View HTML Report (projects.analysis-jobs.reports.store/.show)

Analysis History (projects.analysis-jobs.index) is reachable from every step above (Project, Data Files, and
Analysis Detail's action bar) and is where a returning viewer rediscovers any job in any status, including a
Failed one they still need to recover.
```

The v1.1 changes that make this legible on-screen:
1. The **breadcrumb** (§6) makes "which Project/Job/Report am I under" visible at every step, including the two screens (Mapping, Report) that currently have no path back to the Project.
2. The **status badge palette fix** (§5/§7) makes "waiting for you" (violet), "needs review soon" (new amber priority family), and "failed" (red) three visually distinct signals instead of two of them accidentally sharing a hue family with unrelated states.
3. The **root redirect** (§7.0) means the journey actually starts somewhere real instead of Laravel's scaffold page.

---

## 9. HTML Report Polish

Scope: `resources/views/reports/show.blade.php` and `resources/views/reports/partials/document.blade.php` only. Every bullet below is a rendering/typography/formatting change; none changes `BuildReportSnapshotAction`'s output shape, `schema_version`, or what data is included/excluded (the exclusions below were already enforced by the existing snapshot contract and are restated here only to confirm v1.1 does not weaken them).

- **Report header**: one `<h1>` (demoted duplicate removed, §7), Project/AnalysisJob breadcrumb above it, no action buttons inside the header itself.
- **Metadata**: AnalysisJob id, Mode, Data File name, Completed At, Generated At, Recovered-From id (when present) — same fields, reformatted to `Y-m-d H:i:s` for the two timestamps, laid out as a compact 2-column definition list instead of one long vertical stack, using the prose typography scale.
- **Summary**: unchanged position (first content section), prose scale (16px/1.6) for readability.
- **Metrics**: unchanged `<dl>` list of label/value/unit/change; tokenized spacing only.
- **Tables**: every persisted-result table and the Evaluation table wrapped in `.table-scroll`; header row gets a subtle `--rf-neutral-bg` fill to separate it from body rows in a long table.
- **Insights / Recommendations**: unchanged content and the existing backward-compatible "hide Recommendations entirely when empty" rule; heading level demoted to match the new scale (`h2`→ consistent 20px), no other change.
- **Evaluation**: unchanged columns/values (rates stay internal 0–1 until the existing percentage-point formatting is applied); the "not applicable" vs "no rows" distinction is preserved exactly.
- **Diagnosis (原因の仮説)**: unchanged category labels/rationale/missing-evidence text; unchanged "available" vs "unavailable" vs "not eligible" (no row) distinction.
- **Priority (確認優先度)**: badge color moves to the new amber priority family (§5) instead of borrowing green/status colors; unchanged "not_eligible"/"unavailable"/banded numeric wording.
- **Controlled Actions**: proposal cards unchanged in content; the "Advisory only — not executed" string renders as the new outlined `.badge-advisory` chip (§5) instead of the current bare, colorless `.badge` — a pure visibility improvement to a string that must never be confused for a status.
- **advisory-only表示**: reinforced, not weakened — the label's text is never altered, and no button/link is added near a proposal that could be mistaken for an execution control.
- **long table**: `.table-scroll` (horizontal scroll) plus a `word-break: normal` rule so long unbroken Japanese/CJK content wraps at reasonable boundaries instead of stretching or exploding vertically.
- **日本語**: no change to escaping (already correct, confirmed via raw-byte HTTP inspection this session); typography scale increases line-height to 1.6 specifically to improve Japanese readability in longer paragraphs.
- **print時の基本表示**: new `@media print` block: hide the outer app chrome (page-header action buttons, the "Immutable HTML Report · Generated …" hint banner is kept since it is content, not chrome), set the container to full page width, add `break-inside: avoid` on `.card`/report sections so a section doesn't split awkwardly across a page boundary, and force link URLs not to print inline (default browser behavior is acceptable — no custom `::after` content added, to avoid introducing print-specific new text).

**Explicitly not added** (restated, per the request): a PDF button, edit, delete, Approve, Execute, Retry, any external execution link, the raw AI response, or the raw priority score. None of these appear anywhere in `BuildReportSnapshotAction`'s output today (confirmed by reading `HTML_REPORT_MODULE.md` §7 and the actual `document.blade.php` markup), and v1.1 introduces no new field that could expose them.

---

## 10. Accessibility and Responsive Checklist

| Item | Current state | v1.1 target |
|---|---|---|
| Keyboard focus | Native focus ring preserved everywhere (Confirmed good boundary) | Unchanged — do not add `outline: none` anywhere; new `.btn-link`/`<x-badge>` inherit default focus styling. |
| Heading hierarchy | Report page has two `<h1>`s for the same text; headings outside `.card` are unsized | One `<h1>` per page (Report's inner heading demoted to `<h2>`); every heading level gets an explicit size token (§5). |
| Label | Every checked `<label for>`/`<input id>` pair is correctly associated (Confirmed good boundary) | Unchanged; new "必須" indicator added as visible text beside the existing label, not a separate unlabeled element. |
| Contrast | Existing badge/hint colors are broadly acceptable; `.hint`'s `#6b7280` on white is borderline for its very small size | `--rf-text-secondary:#5B6270` (a small, deliberate darkening) applied everywhere `.hint`/secondary text is used, to comfortably clear AA at small sizes. |
| Color-independent status | Every status/priority badge already pairs a color with a distinct text label (Confirmed good boundary, e.g. "Mapping confirmation required" vs "Failed" are never color-only) | Preserved; the advisory-only chip additionally gets a distinct **shape** (outlined, with an "ⓘ" glyph) so it is not dependent on hue at all. |
| Table overflow | No table has an overflow wrapper; long content explodes vertically on narrow viewports (Confirmed High) | Every `<table>` wrapped in `.table-scroll` (§5/§6). |
| Long text wrapping | Long unbroken Japanese content in table cells has no `word-break` control | `word-break: normal; overflow-wrap: anywhere;` added to `td`/`.card` body text so very long unbroken tokens (URLs, IDs) still wrap without character-by-character collapse. |
| Mobile navigation | `.page-header` does not stack; no hamburger/menu exists (none is needed — there is no persistent nav bar to collapse) | `.page-header` stacks under 640px (§5); breadcrumb wraps naturally at narrow widths (plain inline text + `/` separators, no menu component needed). |
| Touch target | `.btn`/`.btn-secondary` already have comfortable padding (`0.5rem 1rem`); new `.btn-link` uses the same padding as `.btn` so link-style actions remain a comfortable tap target rather than shrinking to bare text | Verified via the same padding token reused for `.btn-link`. |
| Error association | `.errors`/`$errors->any()` renders one flat list with no per-field `aria-describedby` | Out of scope to fully rebuild field-by-field association without touching FormRequest/Blade-per-field structure beyond a cosmetic pass (would risk expanding into a form-rebuild); v1.1 adds `role="alert"` to the existing block only (§6) and records true per-field association as a Known Limitation (§14) rather than solving it partially and inconsistently. |

---

## 11. Implementation Plan

Sized to fit inside **one** implementation branch (`feature/reportflow-v1-1-ui-polish` off this design branch's target), per the "1つのUI Polish branchで完了できる規模" constraint. Steps are ordered so each is independently testable before the next begins.

### Step 1 — Design tokens / layout / navigation
- 変更対象: `resources/views/layouts/app.blade.php` (CSS custom properties from §5; `.page-header` responsive stacking; new `.table-scroll`, `.btn-link`, `.badge-advisory` classes; `role="status"` on the success banner); `routes/web.php` (`/` redirect).
- 完成条件: every existing screen still renders with the same content/order; `/` redirects (302) to `projects.index`; no visual regression in badge text/labels (only colors/tokens change).
- 必要テスト: existing feature tests for every controller continue to pass unmodified (they assert route/text/status, not CSS); one new lightweight test asserting `GET /` redirects to `route('projects.index')`.
- リスク: low — purely additive CSS + one route change; the biggest risk is a copy/paste error in a hex token, caught by keeping every token's *value* identical to today's literal unless explicitly called out as changed in §5.

### Step 2 — Shared UI patterns
- 変更対象: new `resources/views/components/badge.blade.php`, `resources/views/components/empty-state.blade.php`, `resources/views/components/breadcrumb.blade.php`, `resources/views/components/errors.blade.php` (§6).
- 完成条件: components render byte-equivalent output to today's hand-written markup for every existing call site once adopted (verified by the text-based tests in Step 3+, not a snapshot diff of CSS classes).
- 必要テスト: none new at this step in isolation (components have no route); covered indirectly once adopted per-screen in later steps.
- リスク: low — pure additions; no existing file is edited in this step.

### Step 3 — Project / Data File screens
- 変更対象: `resources/views/projects/index.blade.php`, `create.blade.php`, `edit.blade.php`, `resources/views/data-files/index.blade.php` — adopt `<x-badge>`, `<x-empty-state>`, `.table-scroll`, `.btn-link`.
- 完成条件: `ProjectControllerTest`, `DataFileControllerTest` pass unmodified; manual Browser check confirms Active/Archived badges and the empty-project/empty-datafile states render with the new component.
- 必要テスト: existing `tests/Feature/Project/ProjectControllerTest.php`, `tests/Feature/DataFile/DataFileControllerTest.php` (no new assertions required unless a text string is intentionally changed, which is not planned here).
- リスク: low.

### Step 4 — Analysis start / Mapping / History
- 変更対象: `resources/views/analysis-jobs/create.blade.php`, `mapping.blade.php`, `index.blade.php`.
- 完成条件: `CreateAnalysisJobRequestTest`, `AnalysisJobMappingControllerTest`, `AnalysisHistoryControllerTest`, `ListAnalysisJobsQueryTest` pass unmodified; visible "必須" indicator confirmed in Browser without changing which fields are actually required (still driven by the same `$isRequired`/`$inRequiredGroup` booleans).
- 必要テスト: same suites as above, run unmodified; a design-only pass adds no new required Pest test unless a rendered string is intentionally changed.
- リスク: low-medium — the Mapping screen's required-field visual change must not accidentally alter the `name="mapping[...][column]"` field structure the controller/tests depend on.

### Step 5 — Analysis detail / Recovery / Controlled Action
- 変更対象: `resources/views/analysis-jobs/show.blade.php`.
- 完成条件: `AnalysisJobControllerTest`, `FailedAnalysisRecoveryControllerTest`, `ControlledActionUiTest` pass unmodified; the metadata-strip reflow does not remove or reorder any existing text a test asserts on; the priority-band badge class rename (`badge-high`→ still `badge-high` class name, only its CSS token changes — **no Blade string changes needed here**, since `$fact->priorityResult->priority_band` already produces the literal `high`/`medium`/`low` used to build the class name) keeps `ControlledActionUiTest`'s existing assertions valid.
- 必要テスト: run the three suites above unmodified.
- リスク: medium — this is the highest-traffic, most content-dense screen; the metadata-strip reflow is the one true layout change in this plan and deserves the most careful before/after Browser comparison in Step 9.

### Step 6 — HTML Report
- 変更対象: `resources/views/reports/show.blade.php`, `resources/views/reports/partials/document.blade.php`.
- 完成条件: `HtmlReportControllerTest`, `RenderHtmlReportActionTest` pass unmodified (they assert on rendered content/escaping, not on which heading level a string sits under, unless a test literally asserts `<h1>` — see risk below); single `<h1>` per page confirmed in Browser; timestamps display as `Y-m-d H:i:s`.
- 必要テスト: run `tests/Feature/Report/HtmlReportControllerTest.php` and `tests/Unit/Actions/Report/RenderHtmlReportActionTest.php`; if either asserts a literal `<h1>` tag (rather than just the title text), update that one assertion to `<h2>` as part of this step — this is a test-expectation update for an intentional, documented markup change, not a weakening of the escaping/content assertions in the same test.
- リスク: medium — the only step touching the (deliberately narrow) renderer output that already has a `content_hash`/byte-identity test; must confirm which existing assertions are string-exact vs. content-only before editing, per Test Plan §12.

### Step 7 — Responsive / accessibility
- 変更対象: final pass across all touched Blade files for `.table-scroll` coverage, `word-break`, `aria`/`role` additions, and `.page-header` stacking — most of this lands incidentally in Steps 3–6; this step is a dedicated sweep + Browser mobile check to catch anything missed (e.g., a table added inside `.card` markup that Steps 3–6 didn't individually call out).
- 完成条件: every `<table>` in `resources/views/` is inside a `.table-scroll` ancestor (grep-verifiable); mobile Browser check at 375px on Analysis Detail and the Report page shows no character-by-character column collapse and a stacked page-header.
- 必要テスト: no new Pest test (CSS/layout is not meaningfully unit-testable); Browser verification only, recorded in Step 9's Product Validation.
- リスク: low.

### Step 8 — Automated test update
- 変更対象: only the specific, pre-identified assertions from Steps 4–6 that touch an intentionally-changed literal string or tag (expected to be very few — most Confirmed Problems in §4 are pure CSS/token changes with no text-content impact).
- 完成条件: full existing suite passes; no test is weakened (an assertion is only ever changed to match an intentional, documented markup change from §7/§9, never removed to "make it pass").
- 必要テスト: full suite (`php artisan test` / equivalent Pest run) — actual execution is authorized only at implementation time by whoever runs Codex's changes, not by this design-only investigation (see §12 for the boundary).
- リスク: low, provided Step 8 only touches the handful of assertions flagged in Steps 5–6.

### Step 9 — Browser Product Validation
- 変更対象: none (validation only).
- 完成条件: every screen in §2's inventory re-checked in Browser at desktop and 375px width against a fresh set of synthetic fixtures (or the same ones used in this design's investigation, re-verified after implementation); a validation note saved under `docs/product/validation/ui-polish-v1-1/` per the project's existing convention (see `docs/product/validation/html-report-v1/` for the expected shape).
- 必要テスト: manual Browser scenarios listed in §12's "responsiveはBrowser確認" row.
- リスク: low — this is verification, not new implementation.

---

## 12. Test Plan

Goal: prove the polish pass changed **appearance and navigation clarity**, not behavior, without writing brittle full-class-name assertions.

| Area | Approach |
|---|---|
| Route / Controller | Re-run every existing controller Feature test unmodified (`ProjectControllerTest`, `DataFileControllerTest`, `AnalysisJobControllerTest`, `AnalysisHistoryControllerTest`, `AnalysisJobMappingControllerTest`, `FailedAnalysisRecoveryControllerTest`, `HtmlReportControllerTest`) — a passing result proves routing/redirects/validation/flash behavior is unchanged. Add exactly one new assertion for the `/` → `projects.index` redirect. |
| Text / link | Assert on the **presence of specific visible text** ("No projects yet.", "Mapping confirmation required", "Advisory only — not executed", "確認優先度") and on `route()`-generated `href`s, never on a CSS class name or hex value — this is the existing test style in the codebase (confirmed by reading `ControlledActionUiTest`) and should be continued, not replaced with brittle class-selector assertions. |
| Status | Assert the correct **status label text** appears for each `AnalysisJobStatus`/`ProjectStatus` value (already covered by `AnalysisHistoryControllerTest`/`AnalysisJobModelTest`/`Unit/Enums/AnalysisJobStatusTest`) — re-run unmodified; a v1.1 CSS/token change to a badge's color must never require touching these. |
| Empty state | Assert the exact empty-state sentence still renders for an empty Project/DataFile/AnalysisJob list — extend, don't replace, the coverage already implied by `ListProjectsQueryTest`-style tests if one exists, or add one Feature assertion per empty state if none currently exists. |
| Forbidden action | Re-run the existing negative assertions that prove no Approve/Execute/Retry/edit/delete control renders for a Controlled Action or a Report (`ControlledActionUiTest`, `HtmlReportControllerTest` §16.5's "no PDF/export control" assertion) — a shared component (`<x-badge>` etc.) must not accidentally introduce a stray control; these tests are the backstop. |
| Cross-Project boundary | Re-run existing 404 assertions (`AnalysisJobControllerTest`'s cross-Project checks, `HtmlReportControllerTest`'s cross-Project Report 404) unmodified — a breadcrumb/component change must not alter route model binding or the existing `ensureAnalysisJobBelongsToProject`/`abort_if` guards, since none of those files are touched by this plan. |
| Archived behavior | Re-run existing Archived-Project assertions (upload disabled, Analyze hidden, "Unavailable" text, Report still viewable after archive) unmodified. |
| Responsive | **Browser confirmation only** (not a Pest assertion) — a checklist run at 375px width against Analysis Detail, Data Files (populated + empty), Analysis History, and the Report page, confirming: `.page-header` stacks, no character-by-character table column, breadcrumb wraps without overlapping content. |
| Report escape boundary | Re-run `RenderHtmlReportActionTest`'s and `HtmlReportControllerTest`'s existing HTML/script escaping assertions unmodified — the only permitted change to this file's expectations is the heading-tag-level update flagged in §11 Step 6, never a change to what gets escaped or how. |

**Deliberately avoided**: asserting full CSS class strings (e.g. `assertSee('class="badge badge-high"')`) — these break on every token rename and prove nothing about user-visible behavior. Assertions target visible text, `href`s, and the *absence* of forbidden controls, consistent with the existing test style already in this codebase.

---

## 13. Acceptance Criteria

v1.1 UI Polish is complete when all of the following hold, verified via the full existing automated suite plus the Browser checklist in §12:

1. `GET /` redirects to the Projects list; no screen in the app renders Laravel's default scaffold content.
2. Every screen in §2's inventory uses the shared token palette from §5 (no remaining hardcoded literal duplicating a now-tokenized value) and the shared components from §6 where applicable.
3. No 確認優先度 (Priority) badge shares a color with any Project/AnalysisJob status badge (the §4 High-severity color collision is resolved).
4. The Controlled Action's "Advisory only — not executed" label renders as a visually distinct, colored/outlined chip, never the bare uncolored `.badge`.
5. Every `<table>` in the app is horizontally scrollable at narrow viewports instead of vertically exploding a long cell.
6. `.page-header` stacks (title above actions) below 640px on every screen.
7. The HTML Report page renders exactly one `<h1>`, formats its timestamps as `Y-m-d H:i:s`, and prints with a working `@media print` rule that hides app chrome and avoids splitting a section mid-page.
8. No new business capability, route parameter, DB column, Queue job, or AI call exists anywhere in the diff (verifiable by `git diff --stat` touching only `resources/views/`, the app layout's `<style>` block or an equivalent static CSS asset, and `routes/web.php`'s single redirect line).
9. The full pre-existing automated test suite passes with zero weakened assertions; any assertion changed is limited to the specific, pre-identified heading-tag update from §11 Step 6.
10. A Browser Product Validation pass (§11 Step 9) is recorded under `docs/product/validation/ui-polish-v1-1/`, covering at minimum: Projects (populated/empty), Data Files (populated/empty/archived), Analysis History (all 5 statuses), Analysis Detail (all 5 statuses incl. Failed-recovery and Controlled Action absence states), Mapping confirmation, and the HTML Report (a Japanese/escaping fixture, at both desktop and 375px width).

---

## 14. Known Limitations

Recorded so v1.1 has a clear finish line rather than an open-ended polish effort. None of these block a portfolio-quality release; all are candidates for a later, separately-scoped pass.

- **Mixed English/Japanese labels remain mixed.** v1.1 improves *consistency of treatment* (shared components, shared tokens) but does not undertake a full i18n pass or translate every remaining English label (e.g. "Summary", "Metrics", "HTML Report") to Japanese or vice versa. A true bilingual/locale-switchable UI is a larger, separately-scoped effort.
- **Native `<input type="file">` styling is not customized.** Meaningfully restyling a file picker requires JS-driven custom controls, which conflicts with "minimize JavaScript" for a purely cosmetic gain on one field.
- **Per-field validation-error association (`aria-describedby` linking a specific `<input>` to its specific error message) is not implemented.** v1.1 adds `role="alert"` to the existing flat error list but does not restructure `$errors->any()` rendering into per-field inline messages, which would touch every form Blade file's field markup more invasively than a polish pass warrants.
- **Pagination styling for lists beyond 20 rows is designed but not visually verified**, since no seeded local dataset currently exceeds one page on any list. The fix (ensure a plain, build-free CSS rule set styles Laravel's default Tailwind-class pagination partial, or publish and restyle a plain-CSS pagination view) is included in the token/layout pass (§5/§11 Step 1) but should be explicitly re-checked in Step 9 once a >20-row fixture exists.
- **No dark-mode-specific palette is defined.** `color-scheme: light dark` remains declared but has no effect (every color is a hardcoded literal, not a `light-dark()`-aware token) both before and after v1.1; v1.1 does not add a dark palette, only tokenizes the existing light one.
- **Breadcrumb is a lightweight text trail, not a full site-map/mega-nav.** This matches the "no large frontend framework, minimal JS" constraint but means very deep states (e.g., a Recovery attempt's Recovery attempt) still rely on the existing inline lineage links rather than a fully modeled path.

---

## 15. Explicit Non-goals (restated)

v1.1 does not add, and this design does not propose:

- Authentication or user management
- Billing
- PDF export
- Report sharing / public URLs
- External connectors
- Predictive features (forecasting, sleep/churn prediction, budget optimization)
- Any new AI processing or new AI call
- Any new Queue job or Queue processing change
- Any new database table or column
- Any change to existing business logic: Mapping resolution, Data Profiling, Derived Metrics, Evaluation, Diagnosis, Priority, Controlled Action eligibility/generation, or Report snapshot/generation contracts
- Approve/Reject/Edit/Execute/Retry controls on any Controlled Action
- A generic, speculative Blade component library beyond the three components justified in §6

---

## Appendix — Investigation Log

- **Static review**: `routes/web.php`, all 5 Controllers (`ProjectController`, `DataFileController`, `AnalysisJobController`, `ReportController`), all 3 Query classes, both status Enums, all 10 product Blade views, `layouts/app.blade.php`, `resources/css/app.css`, `resources/js/app.js`, `docs/development/CODING_STANDARDS.md`, `docs/development/AI_CODE_REVIEW.md`, `docs/product/REPORTFLOW_BUSINESS_V1_ROADMAP.md`, `docs/product/HTML_REPORT_MODULE.md`, `AGENTS.md`/`CLAUDE.md`.
- **Browser investigation**: performed against the already-running local stack (`http://localhost:18080`, containers `reportflow-app`/`reportflow-nginx`/`reportflow-mysql`/`reportflow-redis`, all already `Up` before this session started) using pre-existing synthetic Product Validation fixtures from prior sessions (Projects 26, 29, 31, 32, 35 and their AnalysisJobs/Reports). No migration, seed, `migrate:fresh`, `db:wipe`, or rollback was run. Two read-only `php artisan tinker` calls were used to resolve a project name to an ID and to confirm a job count for pagination analysis (no writes). No form was submitted, no Report was generated, no OpenAI call was made, no Queue worker was started, and no Product code, test, or formatter was run.
- **Not independently re-verified this session** (relied on code reading only): validation-error rendering (`.errors` block) on Project create/edit and Analysis create/mapping forms, and the flash-message banner's live appearance (its rendering was independently confirmed in the prior `docs/product/validation/html-report-v1/` session, referenced but not re-run here).
