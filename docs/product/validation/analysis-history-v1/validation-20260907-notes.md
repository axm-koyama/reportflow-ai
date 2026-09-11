# Analysis History & Status UX v1 — Product Validation Notes (2026-09-07)

Companion to `validation-20260907.json`. See that file for the structured manifest.

## Screenshot artifact limitation

This session's Browser tool renders screenshots inline for verification but does not expose a
way to save the underlying image bytes to a file on disk. Every screenshot requested by
`docs/product/ANALYSIS_HISTORY.md` §16 was captured and visually inspected during this session,
but the PNG files themselves could not be written into this directory. The textual description
below records what was observed in each case, in place of the binary file.

- **Empty state screenshot** — `/projects/29/analysis-jobs`. Page showed the project name,
  the exact text "No analyses have been created for this project yet." inside a card, and a
  single "Go to Data Files" link. No Analyze link, no Retry, and no "Open an analysis..." hint
  were present (the hint only renders in the non-empty branch).
- **History page 1 screenshot** — `/projects/27/analysis-jobs`. Table with 20 rows, columns
  Analysis / Data File / Mode / Status / Created At / Updated At / Actions, the "Open an
  analysis to view live status updates." hint above the table, and pagination footer reading
  "Showing 1 to 20 of 25 results" with page links "1 2".
- **History page 2 screenshot** — `/projects/27/analysis-jobs?page=2`. Table with the
  remaining 5 rows (Filler 11–15), footer "Showing 21 to 25 of 25 results", no overlap with
  page 1's last row (Filler 10).
- **Five-status screenshot** — top 5 rows of `/projects/27/analysis-jobs` page 1. Badge colors
  observed: Pending = amber/yellow, Processing = blue, "Mapping confirmation required" =
  violet/purple, Completed = green, Failed = red/pink. The Mapping-confirmation-required badge
  was clearly a different hue from Failed's red badge (visually confirmed side by side, same
  row block, same screenshot).
- **Mapping action screenshot** — the "Review Mapping" link appeared only on the row for job 71
  ("AHV20260907 Status Showcase Mapping Confirmation"), and clicking it navigated to the
  existing "列マッピングの確認" screen showing the "広告パフォーマンス分析" template and the
  correct DataFile name, rendered without error (no submission was performed — only navigation
  was verified, per the "no AnalysisJob execution" constraint).
- **Navigation confirmation** — recorded structurally in `validation-20260907.json` under
  `browser_scenarios.6_navigation`: Projects index lists an "Analysis History" link per project
  row, each pointing at that row's own `project_id` (verified for projects 1, 26, 27, 28, 29);
  the Data Files page for project 27 links to its Analysis History; the AnalysisJob detail page
  for job 71 links to both "Back to Analysis History" and "Data Files"; the mapping edit page's
  "戻る" link still points at the AnalysisJob detail page, unchanged from its existing contract.

## What this means for readiness

None of the above is a Finding — every scenario the screenshots would have documented was
independently confirmed via `get_page_text` output, `read_page`/`find` structural queries, and
direct visual inspection of the rendered screenshot during this session (see the main report in
the conversation transcript for the literal captured images and page dumps). The only gap is
that this session could not persist those images as separate files in this validation folder.
