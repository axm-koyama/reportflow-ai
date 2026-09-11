@extends('layouts.app')

@if (in_array($analysisJob->status, [\App\Enums\AnalysisJobStatus::Pending, \App\Enums\AnalysisJobStatus::Processing], true))
    @section('head')
        <meta http-equiv="refresh" content="5">
    @endsection
@endif

@section('title', $analysisJob->title)

@section('actions')
    <a href="{{ route('projects.analysis-jobs.index', $project) }}" class="btn">Back to Analysis History</a>
    <a href="{{ route('projects.data-files.index', $project) }}" class="btn btn-secondary">Data Files</a>
@endsection

@section('content')
    @php($detail = $analysisJob->analysisJobDetail)
    @php($templateName = $analysisJob->template_key ? (config('analysis_templates.'.$analysisJob->template_key.'.name') ?? $analysisJob->template_key) : null)
    <x-breadcrumb :items="[['label' => 'Projects', 'href' => route('projects.index')], ['label' => $project->name, 'href' => route('projects.analysis-jobs.index', $project)], ['label' => $analysisJob->title]]" />

    <dl class="metadata card">
        <dt>Data File</dt><dd>{{ $analysisJob->dataFile->original_name }}</dd>
        @if ($templateName)
            <dt>使用テンプレート</dt><dd>{{ $templateName }}</dd>
        @endif
        <dt>Status</dt><dd><x-badge :variant="str_replace('badge-', '', $analysisJob->status->badgeClass())" :label="$analysisJob->status->label()" /></dd>
        <dt>Created At</dt><dd>{{ $analysisJob->created_at?->format('Y-m-d H:i:s') ?? '-' }}</dd>
        <dt>Started At</dt><dd>{{ $detail?->started_at?->format('Y-m-d H:i:s') ?? '-' }}</dd>
        <dt>Completed At</dt><dd>{{ $detail?->completed_at?->format('Y-m-d H:i:s') ?? '-' }}</dd>
    </dl>
    @if ($detail?->prompt)
        <details class="card"><summary>Prompt</summary><p>{{ $detail->prompt }}</p></details>
    @endif

    @if ($analysisJob->recoveredFrom)
        <section class="card">
            <p>
                <a href="{{ route('projects.analysis-jobs.show', [$project, $analysisJob->recoveredFrom]) }}" class="btn-link">
                    Recovered from AnalysisJob #{{ $analysisJob->recovered_from_analysis_job_id }}
                </a>
            </p>
        </section>
    @endif

    @if ($templateName && $detail?->column_mapping)
        <section class="card">
            <h2>使用した列</h2>
            <div class="table-scroll"><table>
                <thead><tr><th>項目</th><th>CSV列</th><th>状態</th><th>確信度</th></tr></thead>
                <tbody>
                    @foreach ($detail->column_mapping as $field => $mapping)
                        <tr>
                            <td>{{ config("analysis_templates.{$analysisJob->template_key}.fields.{$field}.label") ?? $field }}</td>
                            <td>{{ $mapping['column'] ?? '-' }}</td>
                            <td>{{ $mapping['status'] }}</td>
                            <td>{{ $mapping['confidence'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </section>
    @endif

    @if ($analysisJob->status === \App\Enums\AnalysisJobStatus::Pending)
        <div class="card">The analysis is waiting to start. This page refreshes every 5 seconds.</div>
    @elseif ($analysisJob->status === \App\Enums\AnalysisJobStatus::Processing)
        <div class="card">The analysis is currently processing. This page refreshes every 5 seconds.</div>
    @elseif ($analysisJob->status === \App\Enums\AnalysisJobStatus::AwaitingMappingConfirmation)
        <div class="card">
            <p><strong>列マッピングの確認が必要です。</strong></p>
            <p>AIによる列マッピングの確信度が十分ではなかったため、分析を開始する前にご確認ください。</p>
            <a href="{{ route('projects.analysis-jobs.mapping.edit', [$project, $analysisJob]) }}" class="btn">列マッピングを確認する</a>
        </div>
    @elseif ($analysisJob->status === \App\Enums\AnalysisJobStatus::Failed)
        <div class="alert-error"><strong>Analysis failed:</strong> {{ $detail?->error_message }}</div>
        <section class="card">
            @if ($analysisJob->recoveryAttempt)
                <a href="{{ route('projects.analysis-jobs.show', [$project, $analysisJob->recoveryAttempt]) }}" class="btn-link">View Recovery Attempt</a>
            @elseif ($project->status === \App\Enums\ProjectStatus::Active)
                <p>Recovery creates a new AnalysisJob attempt. This failed attempt remains unchanged.</p>
                <p class="hint">The new attempt may make new AI calls and incur additional cost.</p>
                <form method="POST" action="{{ route('projects.analysis-jobs.recover', [$project, $analysisJob]) }}">
                    @csrf
                    <button
                        type="submit"
                        class="btn"
                        onclick="return confirm('Create a new recovery attempt? This may make new AI calls and incur additional cost.')"
                    >Create Recovery Attempt</button>
                </form>
            @else
                <p class="hint">Recovery attempts can only be created for an active project.</p>
            @endif
        </section>
    @elseif ($analysisJob->status === \App\Enums\AnalysisJobStatus::Completed)
        @php($result = $detail?->result ?? [])

        <section class="card">
            <h2>HTML Report</h2>
            @if ($analysisJob->report)
                <a href="{{ route('projects.reports.show', [$project, $analysisJob->report]) }}" class="btn-link">View HTML Report</a>
            @elseif ($project->status === \App\Enums\ProjectStatus::Active)
                <form method="POST" action="{{ route('projects.analysis-jobs.reports.store', [$project, $analysisJob]) }}">
                    @csrf
                    <button type="submit" class="btn">Generate HTML Report</button>
                </form>
            @else
                <p class="hint">HTML Reports can only be generated while the project is active.</p>
            @endif
        </section>

        <section class="card"><h2>Summary</h2><p>{{ $result['summary'] ?? '-' }}</p></section>
        <section class="card">
            <h2>Highlights</h2>
            <ul>@forelse ($result['highlights'] ?? [] as $highlight)<li>{{ $highlight }}</li>@empty<li>None</li>@endforelse</ul>
        </section>
        <section class="card">
            <h2>Metrics</h2>
            <div class="table-scroll"><table>
                <thead><tr><th>Label</th><th>Value</th><th>Unit</th><th>Change</th></tr></thead>
                <tbody>
                    @forelse ($result['metrics'] ?? [] as $metric)
                        <tr><td>{{ $metric['label'] }}</td><td>{{ $metric['value'] }}</td><td>{{ $metric['unit'] ?? '-' }}</td><td>{{ $metric['change'] ?? '-' }}</td></tr>
                    @empty<tr><td colspan="4">None</td></tr>@endforelse
                </tbody>
            </table></div>
        </section>
        <section class="card">
            <h2>Tables</h2>
            @forelse ($result['tables'] ?? [] as $resultTable)
                <h3>{{ $resultTable['title'] }}</h3>
                <div class="table-scroll"><table>
                    <thead><tr>@foreach ($resultTable['columns'] as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
                    <tbody>@foreach ($resultTable['rows'] as $row)<tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach</tbody>
                </table></div>
            @empty<p>None</p>@endforelse
        </section>
        <section class="card">
            <h2>Insights</h2>
            @forelse ($result['insights'] ?? [] as $insight)
                <h3>{{ $insight['title'] }}</h3><p>{{ $insight['description'] }}</p>
                @if ($insight['evidence'] ?? null)<p class="hint">Evidence: {{ $insight['evidence'] }}</p>@endif
            @empty<p>None</p>@endforelse
        </section>
        {{-- Phase 4-B: an empty recommendations array (the expected new
             shape for a Decision-enabled AnalysisJob — see
             docs/product/DIAGNOSIS_ENGINE.md "Final Analyze最終責務") hides
             this section entirely rather than showing an empty "None"
             card. A past AnalysisJob whose stored result still has
             recommendations (Free Analysis, sales_analysis, or any
             AnalysisJob completed before Phase 4-B) keeps showing them
             exactly as before — full backward compatibility. --}}
        @if (! empty($result['recommendations']))
            <section class="card">
                <h2>Recommendations</h2>
                @foreach ($result['recommendations'] as $recommendation)
                    <h3>{{ $recommendation['title'] }}</h3><p>{{ $recommendation['description'] }}</p>
                    @if ($recommendation['priority'] ?? null)<p class="hint">Priority: {{ $recommendation['priority'] }}</p>@endif
                @endforeach
            </section>
        @endif

        @if ($analysisJob->evaluationFacts->isNotEmpty())
            {{-- Phase 4-A: Deterministic Evaluation Engine. See docs/product/EVALUATION_ENGINE.md.
                 Rates are stored internally on a 0-1 scale; this is the only
                 place they are multiplied by 100 for display (percentage
                 points), per EVALUATION_ENGINE.md "Rate Scale". --}}
            <section class="card">
                <h2>Evaluation</h2>
                <div class="table-scroll"><table>
                    <thead>
                        <tr>
                            <th>Entity</th>
                            <th>Metric</th>
                            <th>Value</th>
                            <th>Baseline</th>
                            <th>Difference</th>
                            <th>Direction</th>
                            <th>Evaluation</th>
                            <th>確認優先度</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($analysisJob->evaluationFacts as $fact)
                            <tr>
                                <td>{{ $fact->entity_key }}</td>
                                <td>{{ ucfirst(str_replace('_', ' ', $fact->metric_key)) }}</td>
                                <td>{{ $fact->metric_value !== null ? number_format($fact->metric_value * 100, 2).'%' : '-' }}</td>
                                <td>{{ $fact->display_baseline_value !== null ? number_format($fact->display_baseline_value * 100, 2).'%' : '-' }}</td>
                                <td>{{ $fact->delta_absolute !== null ? ($fact->delta_absolute >= 0 ? '+' : '').number_format($fact->delta_absolute * 100, 2).'pp' : '-' }}</td>
                                <td>{{ $fact->direction ? ucfirst($fact->direction) : '-' }}</td>
                                <td><x-badge :variant="$fact->evaluation_level" :label="$fact->evaluation_level === 'insufficient_data' ? 'Insufficient data' : ucfirst($fact->evaluation_level)" /></td>
                                {{-- Phase 4-C: Deterministic Priority Layer. See docs/product/PRIORITY_ENGINE.md.
                                     Priority is a distinct axis from Evaluation above — "確認優先度" (never
                                     "優先度" alone, to avoid reading as an execution priority; see
                                     PRIORITY_ENGINE.md "UI日本語名称") — so a High Evaluation next to a Low
                                     確認優先度 (or vice versa) is expected, not a bug. Its "比較対照との差"
                                     (deliberately not "基準との差", to avoid reading as the Difference column
                                     above) is $fact->priorityResult->gap_raw_value — the leave-one-out
                                     peer/control gap (test_baseline_value) — never the Difference column's own
                                     delta_absolute (display_baseline_value, which includes this entity itself
                                     and self-dilutes for a large-traffic-share entity; see
                                     PRIORITY_ENGINE.md "Gap Reference"). Three distinct states, never
                                     collapsed into one another:
                                     1) not Priority-eligible (favorable/low/insufficient_data): "-"
                                     2) eligible with a PriorityResult: the Band, plus its deterministic inputs
                                     3) eligible but no PriorityResult (a per-AnalysisJob technical soft-fail —
                                        see PrioritizeAnalysisJobAction "Soft-fail"): an explicit unavailable message,
                                        never silently rendered the same as "-" (case 1), mirroring the Diagnosis
                                        section's own "診断結果を取得できませんでした" distinction. --}}
                                <td>
                                    @if (! ($priorityEligibility[$fact->evaluation_fact_id] ?? false))
                                        -
                                    @elseif ($fact->priorityResult)
                                        <x-badge :variant="$fact->priorityResult->priority_band" :label="ucfirst($fact->priorityResult->priority_band)" />
                                        <div class="hint">
                                            流量影響 {{ number_format($fact->priorityResult->impact_score * 100, 1) }}% /
                                            比較対照との差 {{ number_format($fact->priorityResult->gap_raw_value * 100, 2) }}pp
                                        </div>
                                    @else
                                        <span class="hint">取得できませんでした</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            </section>

            {{-- Phase 4-B: Controlled Diagnosis. See docs/product/DIAGNOSIS_ENGINE.md.
                 Only EvaluationFacts DetermineDiagnosisEligibilityAction judged
                 eligible (see $diagnosisEligibility, computed by the
                 Controller) get a row here — a fact that was never a
                 Diagnosis candidate (favorable, low, insufficient_data)
                 shows nothing, exactly like it never showed a
                 Recommendations-style entry before Phase 4-B.
                 self_reported_confidence is deliberately never rendered
                 (uncalibrated — see DIAGNOSIS_ENGINE.md "self_reported_confidence"). --}}
            @php($eligibleFacts = $analysisJob->evaluationFacts->filter(fn ($fact) => $diagnosisEligibility[$fact->evaluation_fact_id] ?? false))
            @if ($eligibleFacts->isNotEmpty())
                {{-- "計測整合性の確認候補" (not "計測異常"/"計測問題"/"トラッキング異常") —
                     deliberately reads as one unconfirmed candidate worth checking, never as
                     a confirmed finding. See DIAGNOSIS_ENGINE.md "measurement_consistency_risk". --}}
                @php($diagnosisCategoryLabels = [
                    'measurement_consistency_risk' => '計測整合性の確認候補',
                    'insufficient_explanatory_evidence' => '十分な根拠がありません',
                ])
                <section class="card">
                    <h2>原因の仮説</h2>
                    @foreach ($eligibleFacts as $fact)
                        <h3>{{ $fact->entity_key }}</h3>
                        @if ($fact->diagnosisResult)
                            <p>{{ $diagnosisCategoryLabels[$fact->diagnosisResult->category_key] ?? $fact->diagnosisResult->category_key }}</p>
                            <p>{{ $fact->diagnosisResult->rationale_summary }}</p>
                            @if (! empty($fact->diagnosisResult->missing_evidence_json))
                                <p class="hint">
                                    診断の精度を上げるために必要な情報:
                                    {{ implode(' / ', $fact->diagnosisResult->missing_evidence_json) }}
                                </p>
                            @endif
                        @else
                            <p class="hint">診断結果を取得できませんでした</p>
                        @endif
                    @endforeach
                </section>
            @endif
        @endif

        <section class="card">
            <h2>Controlled Actions</h2>
            @forelse ($controlledActionViewData['proposals'] as $proposal)
                <article>
                    <h3>{{ $proposal->title }}</h3>
                    <p><x-badge variant="advisory" label="Advisory only — not executed" /></p>
                    <dl class="metadata">
                        <dt>Catalog</dt>
                        <dd>{{ config("action_catalog.{$proposal->catalog_key}.label") ?? $proposal->catalog_key }}</dd>
                        <dt>Target</dt>
                        <dd>{{ $proposal->evaluationFact->entity_key }} / {{ ucfirst(str_replace('_', ' ', $proposal->evaluationFact->metric_key)) }}</dd>
                        <dt>確認優先度</dt>
                        <dd>
                            <x-badge :variant="$proposal->priorityResult->priority_band" :label="ucfirst($proposal->priorityResult->priority_band)" />
                            <span class="hint">
                                流量影響 {{ number_format($proposal->priorityResult->impact_score * 100, 1) }}% /
                                比較対照との差 {{ number_format($proposal->priorityResult->gap_raw_value * 100, 2) }}pp
                            </span>
                        </dd>
                    </dl>
                    <p>{{ $proposal->rationale_summary }}</p>
                    <p class="hint">Evidence: {{ implode(' / ', $proposal->evidence_refs_json) }}</p>
                    @if ($proposal->selected_checks_json !== [])
                        <p class="hint">Checks: {{ implode(' / ', $proposal->selected_checks_json) }}</p>
                    @endif
                    @if ($proposal->missing_evidence_json !== [])
                        <p class="hint">Missing evidence: {{ implode(' / ', $proposal->missing_evidence_json) }}</p>
                    @endif
                </article>
            @empty
                <p>No controlled action proposal was generated.</p>
                @if (! $controlledActionViewData['applicable'])
                    <p class="hint">Controlled Actions are not applicable to this analysis.</p>
                @elseif ($controlledActionViewData['eligible_count'] === 0)
                    <p class="hint">No evidence currently meets the controlled eligibility contract.</p>
                @else
                    <p class="hint">Eligible evidence existed, but proposals are best-effort output and may be unavailable.</p>
                @endif
            @endforelse
        </section>
    @endif
@endsection
