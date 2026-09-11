@extends('layouts.app')

@section('title', $report->title)

@section('actions')
    <a href="{{ route('projects.analysis-jobs.show', [$project, $report->analysisJob]) }}" class="btn">Back to Analysis</a>
    <a href="{{ route('projects.analysis-jobs.index', $project) }}" class="btn-link">Analysis History</a>
@endsection

@section('content')
    <x-breadcrumb :items="[['label' => 'Projects', 'href' => route('projects.index')], ['label' => $project->name, 'href' => route('projects.analysis-jobs.index', $project)], ['label' => $report->analysisJob->title, 'href' => route('projects.analysis-jobs.show', [$project, $report->analysisJob])], ['label' => 'HTML Report']]" />
    <section class="card">
        <p class="hint">Immutable HTML Report · Generated {{ $report->generated_at->format('Y-m-d H:i:s') }}</p>
    </section>
    <div class="report-content">
        {!! $report->rendered_html !!}
    </div>
@endsection
