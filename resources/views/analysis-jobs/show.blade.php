@extends('layouts.app')

@if (in_array($analysisJob->status, [\App\Enums\AnalysisJobStatus::Pending, \App\Enums\AnalysisJobStatus::Processing], true))
    @section('head')
        <meta http-equiv="refresh" content="5">
    @endsection
@endif

@section('title', $analysisJob->title)

@section('actions')
    <a href="{{ route('projects.data-files.index', $project) }}" class="btn btn-secondary">Back to Data Files</a>
@endsection

@section('content')
    @php($detail = $analysisJob->analysisJobDetail)
    @php($statusName = strtolower($analysisJob->status->name))
    @php($statusLabels = [
        'pending' => 'Pending',
        'processing' => 'Processing',
        'completed' => 'Completed',
        'failed' => 'Failed',
        'awaitingmappingconfirmation' => '列マッピング確認待ち',
    ])
    @php($statusLabel = $statusLabels[$statusName] ?? $analysisJob->status->name)

    @php($templateName = $analysisJob->template_key ? (config('analysis_templates.'.$analysisJob->template_key.'.name') ?? $analysisJob->template_key) : null)

    <dl class="metadata card">
        <dt>Data File</dt><dd>{{ $analysisJob->dataFile->original_name }}</dd>
        @if ($templateName)
            <dt>使用テンプレート</dt><dd>{{ $templateName }}</dd>
        @endif
        <dt>Prompt</dt><dd>{{ $detail?->prompt ?: '(なし)' }}</dd>
        <dt>Status</dt><dd><span class="badge badge-{{ $statusName }}">{{ $statusLabel }}</span></dd>
        <dt>Created At</dt><dd>{{ $analysisJob->created_at?->format('Y-m-d H:i:s') ?? '-' }}</dd>
        <dt>Started At</dt><dd>{{ $detail?->started_at?->format('Y-m-d H:i:s') ?? '-' }}</dd>
        <dt>Completed At</dt><dd>{{ $detail?->completed_at?->format('Y-m-d H:i:s') ?? '-' }}</dd>
    </dl>

    @if ($templateName && $detail?->column_mapping)
        <section class="card">
            <h2>使用した列</h2>
            <table>
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
            </table>
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
    @elseif ($analysisJob->status === \App\Enums\AnalysisJobStatus::Completed)
        @php($result = $detail?->result ?? [])

        <section class="card"><h2>Summary</h2><p>{{ $result['summary'] ?? '-' }}</p></section>
        <section class="card">
            <h2>Highlights</h2>
            <ul>@forelse ($result['highlights'] ?? [] as $highlight)<li>{{ $highlight }}</li>@empty<li>None</li>@endforelse</ul>
        </section>
        <section class="card">
            <h2>Metrics</h2>
            <table>
                <thead><tr><th>Label</th><th>Value</th><th>Unit</th><th>Change</th></tr></thead>
                <tbody>
                    @forelse ($result['metrics'] ?? [] as $metric)
                        <tr><td>{{ $metric['label'] }}</td><td>{{ $metric['value'] }}</td><td>{{ $metric['unit'] ?? '-' }}</td><td>{{ $metric['change'] ?? '-' }}</td></tr>
                    @empty<tr><td colspan="4">None</td></tr>@endforelse
                </tbody>
            </table>
        </section>
        <section class="card">
            <h2>Tables</h2>
            @forelse ($result['tables'] ?? [] as $resultTable)
                <h3>{{ $resultTable['title'] }}</h3>
                <table>
                    <thead><tr>@foreach ($resultTable['columns'] as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
                    <tbody>@foreach ($resultTable['rows'] as $row)<tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach</tbody>
                </table>
            @empty<p>None</p>@endforelse
        </section>
        <section class="card">
            <h2>Insights</h2>
            @forelse ($result['insights'] ?? [] as $insight)
                <h3>{{ $insight['title'] }}</h3><p>{{ $insight['description'] }}</p>
                @if ($insight['evidence'] ?? null)<p class="hint">Evidence: {{ $insight['evidence'] }}</p>@endif
            @empty<p>None</p>@endforelse
        </section>
        <section class="card">
            <h2>Recommendations</h2>
            @forelse ($result['recommendations'] ?? [] as $recommendation)
                <h3>{{ $recommendation['title'] }}</h3><p>{{ $recommendation['description'] }}</p>
                @if ($recommendation['priority'] ?? null)<p class="hint">Priority: {{ $recommendation['priority'] }}</p>@endif
            @empty<p>None</p>@endforelse
        </section>

        @if ($analysisJob->evaluationFacts->isNotEmpty())
            {{-- Phase 4-A: Deterministic Evaluation Engine. See docs/product/EVALUATION_ENGINE.md.
                 Rates are stored internally on a 0-1 scale; this is the only
                 place they are multiplied by 100 for display (percentage
                 points), per EVALUATION_ENGINE.md "Rate Scale". --}}
            <section class="card">
                <h2>Evaluation</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Entity</th>
                            <th>Metric</th>
                            <th>Value</th>
                            <th>Baseline</th>
                            <th>Difference</th>
                            <th>Direction</th>
                            <th>Evaluation</th>
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
                                <td><span class="badge badge-{{ $fact->evaluation_level }}">{{ $fact->evaluation_level === 'insufficient_data' ? 'Insufficient data' : ucfirst($fact->evaluation_level) }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif
    @endif
@endsection
