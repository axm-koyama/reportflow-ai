# ReportFlow AI v1.1 UI Polish — Browser Product Validation Notes (2026-09-11)

Companion to `validation-20260911.json`. See that file for the full structured manifest. This is a
**validation-only** session: no Product code, test, or design document was modified. All fixes
applied since the prior code review (renderer_version bump, breadcrumb `aria-current` scoping, the
advisory-label icon prefix removal, the `table` min-width CSS scoping, and the nav `routeIs` scoping)
were verified live in this session and confirmed fixed — see "Previously-reported findings, re-verified"
below.

## Screenshot artifact limitation

As in every prior Product Validation session in this project, this session's Browser tool renders
screenshots inline for verification but exposes no way to persist the underlying image bytes to a
file on disk, and no headless-screenshot CLI is installed in the app container. **No PNG files were
saved to this directory.** Every visual check below was performed and observed directly in this
session (visible in this conversation's transcript); DOM-level facts (overflow widths, `aria-current`
counts, badge colors, exact text) were additionally captured via `javascript_tool`, which is stronger
evidence than a screenshot alone for anything measurable.

## 1. Root / Navigation

`GET /` returns a 302 redirect to `route('projects.index')` (confirmed via `location.href` resolving
to `/projects` and via the page title "Projects - ReportFlow AI"). The Laravel welcome scaffold
("Let's get started") is never shown. The primary nav shows "ReportFlow AI" (brand, links home) and
"Projects" (the only nav item). `aria-current="page"` is present on the Projects link **only** on
`/projects` itself — confirmed absent on Data Files, Mapping, Analysis History, Analysis detail, and
both new/old Report pages (previously it used `routeIs('projects.*')`, which matched almost every
route in the app; it now reads `routeIs('projects.index')`). No nav overflow at 375px or 320px.

## 2. Projects

Verified against the shared local Projects list (15+ pre-existing rows from earlier validation
sessions, plus the 3 new `UIV11PV20260911 *` rows). Active/Archived badges render in visually
distinct green/gray. Row actions ("Data Files", "Analysis History", "Edit") render as `.btn-link`
(indigo, low-emphasis). At 375px the table's own `.table-scroll` wrapper overflows internally
(821px content in a 343px column) while `document.documentElement.scrollWidth` stays exactly at
375 — the body itself never scrolls sideways.

**Unverified**: the Projects list's own empty state. This shared local database cannot be emptied
without deleting pre-existing rows from earlier validation sessions, which this session's
instructions do not permit. The `<x-empty-state>` component itself was fully exercised instead on
the Data Files screen (`UIV11PV20260911 Empty Project`, id 41) and is the same component/markup
Projects would use.

## 3. Project forms

Create and Edit were both loaded and inspected structurally rather than submitted (the instructions
state a real save/validation POST is unnecessary here). Both show a breadcrumb (`Projects › Create
Project` / `Projects › <name> › Edit`), with exactly one `<span aria-current="page">` in each case —
the fix for the earlier "aria-current on every hrefless item" defect holds across both a 2-item and a
3-item breadcrumb. "Name" carries an always-visible "Required" chip; "Description" (Create) does not.
Save renders as the primary `.btn`, Cancel as `.btn-link`. Tabbing from a neutral click point lands
first on the Name input with a clearly visible focus ring (`outline: solid`, color `#c7d2fe`, matching
the app's `--rf-advisory-border` token). No horizontal overflow at 375px.

The validation-error summary itself (`components/errors.blade.php`) was confirmed by reading source
only — `role="alert"`, a heading, and a `<ul>` of messages — and was not re-triggered by an actual
invalid submission in this Browser session, per the instructions.

## 4. Data Files

Three states checked: populated (Active Project 39, including a deliberately ~190-character
filename), empty (Empty Project 41), and archived (Archived Project 40). The empty state shows the
shared icon + "No data files uploaded yet." + an "Upload a CSV file" link that jumps to `#upload-form`
(present only when uploads are allowed). The archived state shows no upload form, "Unavailable" in
place of the Analyze link per row, and the "Archived projects cannot accept new DataFiles." hint. The
very long filename wraps within its table cell and never drags the page wider than 375px.

## 5. Analysis start / Mapping

The Create Analysis screen (Project 39 / DataFile 62) shows "Title <Required>", the Free/Template
selector, and both prompt-related hints. The Mapping screen (job 133, `AwaitingMappingConfirmation`)
was loaded against a **real** CSV file this session wrote to the `local` disk at the DataFile's own
`stored_path` — `DataProfilingAction` successfully re-profiled it live and produced real column
candidates (`channel`, `campaign`, `spend`, `revenue`, `conversions`, `clicks`, `impressions`,
`date`), confirming the Mapping screen's "read the file fresh, no persisted candidates" contract still
works end-to-end. "必須" renders as an always-visible red inline label next to "チャネル" (no
hover needed, unlike the pre-Polish bare `*`/`title` tooltip), and "(グループ必須)" appears on the
five grouped fields. The pre-set AI mapping shows "AI confidence: 0.42" as plain visible text. No
form was submitted. No overflow at 375px; the mapping table's own `.table-scroll` handles its own
internal width.

## 6. Analysis History — all five statuses

Project 39's history table shows all five `AnalysisJobStatus` values simultaneously with visually and
textually distinct badges: Pending (amber), Processing (blue), "Mapping confirmation required"
(violet), Completed (green), Failed (red). Row actions match status exactly (Review Mapping only for
the waiting job; Create Recovery Attempt only for the Failed job with no child; Recovered-from /
View-Recovery-Attempt links where applicable) — see §8.

**Pagination** (Finding P1): Project 27 has 25 AnalysisJobs, so its history page is the first list in
this database that actually exceeds the 20-per-page limit. The paginator does render ("Showing 1 to
20 of 25 results", "1 2", "Next »") and the links work, but it is completely unstyled: `getComputedStyle`
on a pagination link/span returns `padding: 0px`, `background-color: rgba(0,0,0,0)`, `border: 0px
none`. Worse than "merely unstyled": the default Tailwind pagination partial's decorative mobile/
desktop chevron `<svg>` icons (meant to be sized ~20px by Tailwind's `w-5 h-5`, which this app's
plain-CSS layout never loads) render at their raw intrinsic size — roughly 400-500px square — at both
375px and 1024px, visually dominating the bottom of the page. This does not cause body-level
horizontal overflow (confirmed 375×375 and 1024×1024 at that exact scroll position) and the links
remain clickable, so it is a **visual defect, not a functional break** — recorded as Medium Finding
P1, not a stop condition.

## 7. Analysis detail / Controlled Actions

Verified end-to-end on the newly-seeded Completed job (134), which was deliberately built with a
Summary/Highlights/Metrics/Tables/Insights/Recommendations-populated result, one High-band
EvaluationFact (`Social` / `conversion_rate`), a DiagnosisResult (`measurement_consistency_risk`), a
PriorityResult (`band=high`), and one ActionProposal. Confirmed via `getComputedStyle`:
`.badge-completed` background `rgb(220,252,231)` (green) vs. `.badge-high` background
`rgb(255,237,213)` (amber/orange) — visually and unambiguously distinct, directly confirming the
priority/status color-family separation from the design holds in the live app. The
"Advisory only — not executed" chip renders as an exact string match with no leading "ⓘ" (the icon
prefix flagged in the prior code review has been removed) inside a distinct outlined indigo pill,
clearly different in both shape and color from the amber priority badge next to it. Raw
`priority_score` (0.821345) and `self_reported_confidence` (0.62) do not appear anywhere in the
rendered text. Prompt renders inside a `<details>` disclosure. The three pre-existing Controlled
Action no-proposal states (Project 26, jobs 66/67/68) were re-confirmed to still read as three
distinct, non-conclusive sentences. HTML/script payloads embedded in the synthetic summary/highlights/
table cells (`<script>alert(1)</script>`, `<b>HTML-like</b>`, `<img src=x onerror=alert(2)>`) all
render as literal escaped text — zero actual `<script>` elements exist in the rendered content. No
body-level overflow at 375px, 1024px, or 320px; the widest inner `.table-scroll` reaches 739px inside
a 309px column, exactly the intended "table scrolls, page doesn't" behavior.

## 8. Failed Analysis Recovery

Four distinct source states were built and each rendered exactly as specified, with no Recovery POST
ever submitted:

- **Failed, Active, no child** (job 136): "Create Recovery Attempt" form with the cost-warning
  copy ("This creates a new attempt and may make new AI calls...").
- **Failed, Active, child already exists** (job 137, child 138): "View Recovery Attempt" link only —
  no form.
- **Failed, Archived project** (job 139): "Recovery attempts can only be created for an active
  project." — no form, no link.
- **Completed** (job 134): no recovery text or control of any kind.

## 9. New-format HTML Report

Generated via a real click on the "Generate HTML Report" button (job 134) — not a direct DB insert.
The **first** attempt returned an HTTP 500 (`NormalizeAnalysisResultAction`: "metrics item is missing
a valid 'value' string") because this session's own synthetic `result.metrics[].value` fields were
integers instead of strings, as the normalized-result contract `BuildReportSnapshotAction` re-validates
against requires; this was a mistake in the synthetic fixture, not a Product defect. No Report row was
created by the failed attempt (`reports` count stayed at 7). The fixture was corrected
(`value` cast to a string) and generation was retried successfully, producing **Report #10**.

Confirmed via direct DB read: `renderer_version = report_renderer_v1.1` (the version bump applied
since the prior code review), `schema_version = report_schema_v1.0` (unchanged, as expected — the
snapshot contract itself did not change), `content_hash` independently verified to equal
`hash('sha256', $report->rendered_html)`. The persisted `rendered_html` itself contains zero `<h1`
occurrences, 11 `<h2`, two `.table-scroll` wrapper divs, and one `badge-advisory` class instance.

On screen: exactly one `<h1>` for the whole page (the layout's own title); the document's own
heading renders as `<h2>`. "Completed At"/"Generated At" show as `2026-09-11 02:54:12` /
`2026-09-11 03:01:51` — no raw ISO-8601 string appears anywhere on the page. No form, button,
Approve/Reject/Execute/Retry, or "PDF" text exists anywhere in the DOM or rendered text. Japanese
text and the long unbroken table cell both render correctly at 1024px and 375px with **no body-level
horizontal overflow** (confirmed `scrollWidth === clientWidth` at both widths) — the internal
`.table-scroll` correctly absorbs the overflow instead (640×293 at 375px). `jobs` table count was 0
before and after generation; `reports` count moved from 7 to 8, exactly matching the one intentional
generation.

## 10. Old-format HTML Report

Two pre-existing Reports (from the earlier `html-report-v1` validation session, never touched by this
one) were used: **Report #9** (job 127, `renderer_version = report_renderer_v1.0`) and **Report #3**
(job 122, same version). Both were confirmed byte-for-byte unchanged before and after being viewed in
this session — `content_hash`, `renderer_version`, and `updated_at` all identical, and the `reports`
row count did not increase from viewing them. Nothing regenerates them on display.

- **Double `<h1>`**: confirmed present on Report #9 (2 `<h1>` elements: the layout's title and the
  persisted document's own, un-demoted heading). Per this task's own instructions, this is recorded
  as a known, pre-existing, non-blocking constraint inherent to the immutable-Report contract — the
  persisted HTML cannot gain the `<h2>` demotion retroactively without rewriting stored content, which
  this session was explicitly forbidden from doing.
- **Raw ISO-8601 timestamps**: confirmed still present verbatim on Report #9
  (`2026-09-10T06:15:58.000000Z`) — expected, since only the renderer used for *future* generations
  changed.
- **Advisory text minimally readable**: Report #3's Controlled Action proposal renders
  `Advisory only — not executed` as a plain, unstyled `<p>` (this Report's `rendered_html` predates
  even the badge-wrapped advisory markup) — plainly legible, just without the newer chip styling.
- **Mobile body overflow — new finding this session (P2)**: both old Reports overflow the page at
  375px (Report #9: 740 vs 375; Report #3: 678 vs 375), while both are overflow-free at 1024px. Root
  cause, isolated via DOM inspection: their persisted Evaluation table has no `.table-scroll` wrapper
  (that markup only exists in *new* renderer output), and one cell's content includes a long,
  unbreakable Latin-script token — the browser has no space to line-wrap on, so the table simply grows
  to its natural ~700px content width, and with no scroll container to absorb that width, the whole
  page overflows sideways. This is a different, narrower mechanism than the `table { min-width: 640px
  }` global rule flagged in the prior code review — that rule is now correctly scoped to
  `.table-scroll table` (confirmed by reading the current stylesheet) and no longer applies to bare
  tables at all. The residual overflow exists only because old HTML never had a wrapper to receive
  *any* overflow-containment rule, old or new. This is judged **non-blocking** for the same reason the
  task pre-authorizes not blocking on the related double-`<h1>` limitation: both are inherent,
  unfixable-without-rewriting-persisted-HTML consequences of the Report immutability contract, not
  regressions this UI Polish pass introduced into the *live* rendering path (confirmed clean for the
  brand-new Report in §9).

## 11. Breadcrumb / Accessibility

`aria-current="page"` count was checked as a hard DOM assertion (not eyeballed) on eight distinct
screens (Create Project, Edit Project, Data Files, Mapping, Analysis History, Analysis detail, the new
Report, and implicitly the old Report via the same shared component) — **every single one showed
exactly 1**, confirming the earlier "aria-current on every hrefless breadcrumb item" defect is fixed
project-wide, not just on the one screen spot-checked in the prior code review. Heading hierarchy is
single-`<h1>`-per-page everywhere except old Reports (§10, recorded limitation). Keyboard focus was
exercised once (Tab into the Create Project form) and produced a clearly visible ring; no `outline:
none` exists anywhere in the stylesheet. Status and priority are never color-only — every badge pairs
its color with a distinct text label, and the advisory chip is additionally distinct in *shape*
(outlined pill) from every colored status/priority badge.

**Not performed**: an actual assistive-technology (screen reader) pass — no such tool was available in
this session; every accessibility claim above is a DOM/attribute-level inspection, not an audio/AT
transcript.

## 12. Print preview

No dedicated `@media print` emulation action exists among this session's Browser tools. As a
disclosed approximation, the page's own print CSS block (read directly from
`resources/views/layouts/app.blade.php`) was re-injected as an equivalent `media="screen"` `<style>`
override on the live new-Report page, screenshotted, and then removed. Under that override: `.app-nav`,
`.page-actions`, `.breadcrumb`, and `.alert-success` all disappeared; the page's `<h1>` (Report title)
remained visible at the very top — confirming the earlier "print hides the only h1" defect is fixed
(the print rule now targets `.page-actions`, not the whole `.page-header`); the Report body, including
the long table, rendered at full container width with no horizontal scrollbar and no visible form,
button, or bare URL.

## 13. Overflow measurements

See `validation-20260911.json` → `scenarios.13_overflow_measurements` for the full numeric table.
Summary: **zero** body-level horizontal overflow was found on any current-renderer screen at 1024px,
375px, or 320px (Projects, Data Files, Analysis History — including its paginated page — Mapping,
Analysis detail, and the new-format Report all measured `scrollWidth === clientWidth` exactly). The
only body-level overflow found anywhere in this session was on the two **old-format** Reports at
375px (§10, Finding P2) — never at 1024px, and never on anything generated with the current renderer.

## AI / Queue non-execution

`jobs` table: 0 → 0 across the whole session. `failed_jobs`: 2 → 2 (both pre-existing, untouched).
`reports`: 7 → 8, exactly the one Report this session intentionally generated. No `queue:work` or
similar process was found running inside the `reportflow-app` container at any point checked. No line
containing "openai" exists in `storage/logs/laravel.log`. These three signals together are the
evidence available in this session; no independent network-level call counter existed to make a
stronger claim than "no evidence of any AI/Queue activity was found."

## Previously-reported findings, re-verified fixed in this session

All six findings from the prior code-review session were re-checked against the current source and/or
live behavior and confirmed resolved:

1. **renderer_version not bumped** → now `report_renderer_v1.1` (confirmed on Report #10).
2. **Breadcrumb `aria-current` on every hrefless item** → now `@if ($loop->last)`-scoped; confirmed
   exactly 1 per breadcrumb on 8 different screens.
3. **Advisory label "ⓘ " prefix** → removed; confirmed exact-match text at both call sites.
4. **Global `table { min-width: 640px }` affecting legacy Report tables** → now scoped to
   `.table-scroll table`; confirmed via source read. (A narrower, different overflow mechanism still
   affects old Reports specifically — see Finding P2, a new observation from this validation, not the
   same defect.)
5. **Nav `aria-current` via `routeIs('projects.*')`** → now `routeIs('projects.index')`; confirmed
   absent on every non-index screen checked.
6. **Print CSS hiding the page's only `<h1>`** → print rule now targets `.page-actions` instead of the
   whole `.page-header`; confirmed the `<h1>` remains visible under the print-equivalent override.

---

## Follow-up: limited re-validation of Finding P1 / P2 (2026-09-11, second pass)

A separate, narrowly-scoped session re-verified only Finding P1 (pagination) and Finding P2
(old-Report mobile overflow) after Product code corrections were applied. **No other screen was
re-checked, no code review was performed, and no automated test was run in this pass.** Full
structured measurements are recorded under `revalidation_20260911_p1_p2` in
`validation-20260911.json`; this section summarizes them in prose. As before, no Product/test code,
`docs/development/CODING_STANDARDS.md`, or `AGENTS.md` was modified — only these two artifact files
were touched, and only by appending this section and marking P1/P2 `"status": "Resolved"` in the
`findings` array (their original entries were kept intact, not deleted).

### Safety re-check

Branch unchanged (`feature/reportflow-v1-1-ui-polish`), `APP_ENV=local`, DB still the local Docker
`reportflow-mysql`/`reportflow`, no queue worker process found, `jobs=0` / `failed_jobs=2` /
`reports=8` before starting — identical to the state at the end of the original validation, confirming
nothing else changed the database in between.

### What changed in the Product code since the original validation

- `resources/views/analysis-jobs/index.blade.php` now calls `$analysisJobs->links('components.pagination')`
  instead of Laravel's default view.
- A new `resources/views/components/pagination.blade.php` renders plain `<nav>`/`<ul>`/`<a>`/`<span>`
  markup with no `<svg>` at all, and explicit `aria-current="page"` only on the current page number.
- `resources/views/reports/show.blade.php` now wraps `{!! $report->rendered_html !!}` in a new
  `<div class="report-content">`.
- `resources/views/layouts/app.blade.php` gained `.pagination`/`.pagination-list`/`.pagination-link`
  styling (normal button sizing, indigo current-page state, disabled state) and
  `.report-content { max-width: 100%; overflow-x: auto; }` (reset to `overflow: visible` under
  `@media print`).
- `app/Actions/Report/RenderHtmlReportAction.php` shows a further diff, but it is the same
  `renderer_version` line already validated in the original pass and unrelated to P1/P2.

The `.report-content` fix is structurally significant: it lives in the **outer**, always-freshly-
rendered `reports/show.blade.php` template, not inside the persisted `rendered_html` string — so it
applies uniformly to every Report regardless of which renderer version originally generated its
content, with zero change to any stored row.

### P1 — Pagination, re-verified on Project 27 (25 AnalysisJobs)

**Desktop (1440-class width):** `document.querySelectorAll('.pagination svg, nav[aria-label*="pagination" i] svg').length` → **0**. Every pagination link/span (`Previous`, `1`, `2`, `Next`) measured a normal
button-sized bounding box (heights 40.5px, widths 40–85px) — no 400–500px giant element anywhere.
`aria-current="page"` appears exactly once **inside the pagination `<nav aria-label="Analysis History
pagination">`** (on page "1"), and exactly once, separately, inside the page's `<nav aria-label="Breadcrumb">`
— two independent landmarks each correctly marking their own "current" concept, not a duplicate marker
within one widget. Clicking **Next** moved to `?page=2`, showed exactly **5** rows (`Filler 11`–`Filler
15`), and moved the current-page marker to "2". Clicking **Previous** returned to `?page=1` with
exactly the original **20** rows (`Filler 1`–`Filler 10` plus the ten named jobs), current-page marker
back on "1". No row appeared on both pages and none were missing (20 + 5 = 25, matching the DB count).
`document.documentElement.scrollWidth === clientWidth === 1440` throughout.

**Mobile (375×812):** identical SVG count (0). `.pagination-list` computed `flex-wrap: wrap` (so it can
wrap at even narrower widths, though at 375px all four controls still fit on one row: rightmost edge
at x=262.66, well inside the 375px viewport). `document.documentElement.scrollWidth === clientWidth
=== 375` — no body-level horizontal overflow anywhere on this page.

**P1 result: RESOLVED.**

### P2 — Old-format Report overflow, re-verified on Report #9 (job 127, `report_renderer_v1.0`)

**Before viewing**, read-only: `renderer_version=report_renderer_v1.0`, `schema_version=report_schema_v1.0`,
`content_hash=09fd08ea…09b09141f` truncated in the summary above but recorded in full in the JSON,
`updated_at=2026-09-10 15:20:24`, `reports` count 8.

**Mobile (375×812):** `document.documentElement.scrollWidth === clientWidth === 375` — **the
page-level overflow that previously measured 740 vs 375 is gone.** `.report-content` exists,
and its own `scrollWidth` (724) exceeds its own `clientWidth` (343) — exactly the intended shape: the
overflow that used to escape onto the whole page is now contained inside this one element. Confirmed
this is genuinely scrollable, not just visually clipped, by directly setting
`document.querySelector('.report-content').scrollLeft = 300` and reading it back as `300` (then
resetting to `0`). The primary nav (`width 375, right edge at 375`) and the page-header
(`width 343, right edge at 359`) both stay fully inside the 375px viewport. The old table's content —
including the pre-existing `<script>`/`<img onerror>` escape-test payloads — remains visible and
readable, scrolled into view exactly as before, just without dragging the rest of the page sideways
with it.

**Desktop (1440-class width):** `document.documentElement.scrollWidth === clientWidth === 1440`, and
`.report-content`'s own `scrollWidth === clientWidth === 912` (the content comfortably fits the wider
container, so no internal scrolling is even needed there).

**After viewing**, read-only re-check: `renderer_version`, `schema_version`, `content_hash`, and
`updated_at` are all byte-identical to the "before" values, and the `reports` table count is still 8 —
confirming once again that simply viewing an old Report does not regenerate it.

The two items the task explicitly pre-classified as non-blocking known limitations were reconfirmed
present and are **not** re-raised as findings here: Report #9 still renders two `<h1>` elements, and
its "Completed At" still shows the raw ISO-8601 string `2026-09-10T06:15:58.000000Z`.

**P2 result: RESOLVED.**

### AI / Queue, re-checked after this revalidation pass

`jobs`: 0 (unchanged). `failed_jobs`: 2 (unchanged, pre-existing). `reports`: 8 (unchanged — this
pass created no new Report). No queue worker process found. No line containing "openai" in
`storage/logs/laravel.log`.

### Screenshots

Not saved as files in this pass either — same tooling limitation as the original session, stated
plainly rather than claimed otherwise. `pagination-mobile.png` and `report-old-mobile-fixed.png` were
**not** created.

### Outcome

Both P1 and P2 are Resolved. Per the task's stop condition, no further Low-severity exploration or
full-screen re-check was performed after confirming both.
