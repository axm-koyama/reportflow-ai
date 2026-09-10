# HTML Report Module v1 — Product Validation Notes (2026-09-10)

Companion to `validation-20260910.json`. See that file for the full structured manifest.

## Screenshot artifact limitation

As in every prior Product Validation session in this project, this session's Browser tool
renders screenshots inline for verification but exposes no way to persist the underlying
image bytes to a file on disk, and no headless-screenshot CLI is installed in the app
container. **No PNG files were saved to this directory.** This is stated plainly rather than
claimed. Every visual check below was performed and observed directly in this session (visible
in this conversation's transcript), and — for the most security-critical check (Scenario 10) —
was additionally confirmed at the raw HTTP response byte level, which is stronger evidence than
a screenshot alone.

- **complete-report.png (not saved)** — Report #3 (Job A, "Complete Decision-enabled"). Observed:
  green "Report generated." flash banner; "Immutable HTML Report · Generated ..." metadata card;
  Summary/Highlights/Metrics/Tables/Insights rendered from the persisted normalized result;
  Evaluation table with the Social/Conversion-rate row; 原因の仮説 (Diagnosis) showing "Measurement
  Consistency Risk" as an *available* row; 確認優先度 (Priority) showing a "High" band with
  流量影響/比較対照との差 percentages (never the raw `priority_score`); Controlled Actions showing
  one real proposal with the exact fixed label "Advisory only — not executed".
- **free-analysis.png (not saved)** — Report #4 (Job B). Mode "Free Analysis"; the legacy
  recommendation ("Legacy recommendation" / "...pre-Decision-enabled style recommendation." /
  "Priority: medium") rendered intact; Evaluation/Diagnosis/Priority each showing their own
  distinct, neutral absence text (never a 500, never "no problem"); Controlled Actions showing
  the "not applicable" wording.
- **evaluation-not-applicable.png (not saved)** — Report #5 (Job C, `sales_analysis` / 売上分析).
  Core analysis sections rendered normally; Evaluation section reads exactly "Evaluation is not
  applicable to this analysis." — distinguished from an empty-but-applicable state, no "no
  issue"/"異常なし" claim anywhere.
- **eligible-unavailable.png (not saved)** — Report #6 (Job D). Evaluation row present (entity
  "Eligible No Downstream"); Diagnosis reads "Diagnosis was unavailable for this eligible
  evidence."; Priority reads "Priority was unavailable for this eligible evidence." — both
  visibly distinct from a "not eligible" fact, which would produce no Diagnosis row at all and a
  "Not eligible." Priority row instead.
- **controlled-action-empty-states.png (not saved)** — three separate reports, each showing one
  of the three distinct Controlled-Action absence wordings side by side in this note (all
  confirmed via `get_page_text`, not just visual inspection): "Controlled Actions are not
  applicable to this analysis." (Report #4); "No evidence currently meets the controlled
  eligibility contract." (Report #6); "Eligible evidence existed, but proposals are best-effort
  output and may be unavailable." (Report #7). None of the three ever reads "No action is
  needed" or any equivalent conclusive phrasing.
- **archived-report.png (not saved)** — Report #8 (Job F), viewed after its Project (36) was
  archived: page loads normally (`200 OK`), full content intact, no archive-related warning
  intrudes into the report body. A duplicate generate POST while archived was also sent directly
  (bypassing the now-absent UI form) and redirected to the same report with the "Opening the
  existing report." flash, content byte-identical before/after.
- **immutable-report.png (not saved)** — Report #3 re-viewed after deliberately mutating six
  separate downstream layers of its source (Job A's own title, its AnalysisJobDetail result
  summary, its EvaluationFact's entity_key/metric_value, its DiagnosisResult rationale, its
  PriorityResult band, and its ActionProposal title — all via `MUTATED ... SHOULD NOT APPEAR`
  marker strings). The re-rendered page was confirmed, via a full `get_page_text` dump, to
  contain **none** of the six marker strings; `content_hash` was independently confirmed
  unchanged before and after via direct DB queries.
- **japanese-escape-report.png (not saved)** — Report #9 (Job H). See below; this is the most
  thoroughly evidenced scenario in this validation.

## Scenario 10 — the strongest evidence gathered this session

Rather than relying on a screenshot alone, this scenario's escaping claim was verified at four
independent levels, all captured as text in this session:

1. **DOM element counts** inside the rendered `.html-report` article: `0` `<script>`, `0`
   `<img>`, `0` `<svg>`, `0` elements carrying `onerror`/`onload`/`onmouseover` attributes.
2. **Raw HTTP response body** (fetched directly, not read back through `innerHTML`, which the
   browser itself re-serializes and can mask quote-escaping): confirmed byte-for-byte
   `&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;`, `&lt;img src=x onerror=alert(1)&gt;`,
   `&quot;&gt;&lt;svg onload=alert(1)&gt;`, `onmouseover=&quot;alert(1)&quot;`, and
   `&amp; &#039; &quot; &lt; &gt;` for the literal `& ' " < >` sequence — every one of `<`, `>`,
   `&`, `'`, and `"` is individually HTML-entity-escaped by the server, in every field tested
   (summary, highlights, a metric label, a long/malicious table cell, an insight title, and an
   Evaluation-table entity_key that is reused across the Evaluation/Diagnosis/Priority sections).
3. **Content-Type header**: `text/html; charset=utf-8`, and a direct substring scan of the full
   response body for the UTF-8 replacement character confirmed **no mojibake** anywhere in the
   Japanese text.
4. **Console messages**: zero JavaScript execution errors and zero alert/dialog events; the only
   console entries were three pre-existing 404s left over from the earlier cross-Project
   navigation checks in this same session.

## What this means for readiness

Every scenario above was independently confirmed via `get_page_text` structural dumps, direct
DOM/network/response-body inspection (`javascript_tool`, `read_network_requests`,
`read_console_messages`), and real MySQL query results (`php artisan tinker`, plus native
`SHA2()`) cross-referencing what the Browser session produced — not solely visual inspection.
The only gap is that the visual screenshots themselves were not persisted as separate files in
this folder; the underlying claims they would have illustrated are otherwise fully evidenced.
