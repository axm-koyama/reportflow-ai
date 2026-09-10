@extends('layouts.app')

@section('title', $report->title)

@section('actions')
    <a href="{{ route('projects.analysis-jobs.show', [$project, $report->analysisJob]) }}" class="btn">Back to Analysis</a>
    <a href="{{ route('projects.analysis-jobs.index', $project) }}" class="btn btn-secondary">Analysis History</a>
@endsection

@section('content')
    <section class="card">
        <p class="hint">Immutable HTML Report · Generated {{ $report->generated_at->format('Y-m-d H:i:s') }}</p>
    </section>
    {!! $report->rendered_html !!}
@endsection
