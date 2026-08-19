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

    <dl class="metadata card">
        <dt>Data File</dt><dd>{{ $analysisJob->dataFile->original_name }}</dd>
        <dt>Prompt</dt><dd>{{ $detail?->prompt }}</dd>
        <dt>Status</dt><dd><span class="badge badge-{{ $statusName }}">{{ $analysisJob->status->name }}</span></dd>
        <dt>Created At</dt><dd>{{ $analysisJob->created_at?->format('Y-m-d H:i:s') ?? '-' }}</dd>
        <dt>Started At</dt><dd>{{ $detail?->started_at?->format('Y-m-d H:i:s') ?? '-' }}</dd>
        <dt>Completed At</dt><dd>{{ $detail?->completed_at?->format('Y-m-d H:i:s') ?? '-' }}</dd>
    </dl>

    @if ($analysisJob->status === \App\Enums\AnalysisJobStatus::Pending)
        <div class="card">The analysis is waiting to start. This page refreshes every 5 seconds.</div>
    @elseif ($analysisJob->status === \App\Enums\AnalysisJobStatus::Processing)
        <div class="card">The analysis is currently processing. This page refreshes every 5 seconds.</div>
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
    @endif
@endsection
