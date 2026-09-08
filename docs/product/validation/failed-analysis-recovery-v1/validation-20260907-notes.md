# Failed Analysis Recovery v1 — Product Validation Notes (2026-09-07)

Companion to `validation-20260907.json`. See that file for the structured manifest.

## Screenshot artifact limitation

As in the prior Analysis History Product Validation session, this session's Browser tool
renders screenshots inline for verification but exposes no way to save the underlying image
bytes to a file on disk, and no headless-screenshot CLI (node/puppeteer/wkhtmltoimage) is
installed in the app container. Every screenshot referenced below was captured and visually
inspected during this session but **is not saved as a file in this directory**. This is stated
plainly rather than claimed as saved.

- **Migration `SHOW CREATE TABLE` output** — captured as text (see manifest `migration` section),
  not a screenshot; this is the authoritative, higher-fidelity evidence for schema verification.
- **History page (Active Project, 20 rows)** — table with correct per-row Mode/Status/Actions;
  badge colors observed: Failed = red/pink, Pending = amber, Processing = blue, "Mapping
  confirmation required" = violet, Completed = green (all pre-existing from the Analysis
  History feature, confirmed unaffected). Every childless Failed row showed a black "Create
  Recovery Attempt" button plus the two-line explanation/cost-warning hint directly beneath it.
- **Failed source detail page (job #98, before recovery)** — showed the original error message
  in a pink alert box, the pre-existing "使用した列" Mapping table, and below that a card reading
  "Recovery creates a new AnalysisJob attempt. This failed attempt remains unchanged." / "The
  new attempt may make new AI calls and incur additional cost." / a "Create Recovery Attempt"
  button.
- **Recovery child detail page (job #113, immediately after recovery)** — Status badge "Pending"
  (amber), Started At / Completed At both "-", a card reading "Recovered from AnalysisJob #98"
  linked to the source, the same "使用した列" Mapping table now populated with the copied Mapping,
  and the pre-existing "The analysis is waiting to start. This page refreshes every 5 seconds."
  notice.
- **Chain link 2 (job #116) after being manually flipped to Failed** — showed both "Recovered
  from AnalysisJob #108" (its own lineage) and its own fresh "Create Recovery Attempt" form
  (since it had no child yet), demonstrating a Failed recovery child is itself recoverable.
- **Archived Project source detail page (job #111)** — no form; only "Recovery attempts can
  only be created for an active project." was shown where the form would otherwise appear.
- **Mapping Confirmation screen (job #104, via "Review Mapping")** — rendered normally
  ("列マッピングの確認", template name, 8-row mapping table, "このMappingで分析" button never
  clicked), confirming this pre-existing flow is unaffected by the Recovery feature.

## Confirmation-dialog handling note

The automated Browser tool cannot click "OK" on a native `window.confirm()` dialog — it can
only be cancelled automatically. This was used as a genuine test in its own right: clicking the
real "Create Recovery Attempt" button once, and confirming via `read_network_requests` that
**zero** POST request to `/recover` resulted, directly proving the confirm-dialog gate blocks
submission when declined. To then exercise the actual POST/redirect/copy/reset/dispatch path
end-to-end (which is the majority of what this report verifies), the same CSRF-protected
`<form>` was submitted via `form.requestSubmit()` instead of a literal dialog-accepted click —
functionally identical from the server's perspective (same form, same token, same endpoint),
just not routed through a dialog this tooling cannot accept.

## What this means for readiness

Every scenario described above was independently confirmed via `get_page_text` structural
dumps, direct DOM/network inspection (`javascript_tool`, `read_network_requests`), and real
MySQL query results (via `php artisan tinker`) cross-referencing what the Browser session
produced — not solely visual inspection. The only gap is that the visual screenshots themselves
were not persisted as separate files in this folder.
