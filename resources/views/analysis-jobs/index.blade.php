@extends('layouts.app')

@section('title', 'Analysis History - '.$project->name)

@section('actions')
    <a href="{{ route('projects.data-files.index', $project) }}" class="btn">Data Files</a>
    <a href="{{ route('projects.index') }}" class="btn btn-secondary">Back to Projects</a>
@endsection

@section('content')
    <p><strong>{{ $project->name }}</strong></p>

    @if ($analysisJobs->isEmpty())
        <div class="card">
            <p>No analyses have been created for this project yet.</p>
            <a href="{{ route('projects.data-files.index', $project) }}">Go to Data Files</a>
        </div>
    @else
        <p class="hint">Open an analysis to view live status updates.</p>
        <table>
            <thead>
                <tr>
                    <th>Analysis</th>
                    <th>Data File</th>
                    <th>Mode</th>
                    <th>Status</th>
                    <th>Created At</th>
                    <th>Updated At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($analysisJobs as $analysisJob)
                    @php($templateName = $analysisJob->template_key === null
                        ? 'Free Analysis'
                        : (config('analysis_templates.'.$analysisJob->template_key.'.name') ?? $analysisJob->template_key))
                    <tr>
                        <td>{{ $analysisJob->title }}</td>
                        <td>{{ $analysisJob->dataFile->original_name }}</td>
                        <td>{{ $templateName }}</td>
                        <td><span class="badge {{ $analysisJob->status->badgeClass() }}">{{ $analysisJob->status->label() }}</span></td>
                        <td>{{ $analysisJob->created_at?->format('Y-m-d H:i') }}</td>
                        <td>{{ $analysisJob->updated_at?->format('Y-m-d H:i') }}</td>
                        <td>
                            <a href="{{ route('projects.analysis-jobs.show', [$project, $analysisJob]) }}">View Details</a>
                            @if ($analysisJob->status === \App\Enums\AnalysisJobStatus::AwaitingMappingConfirmation)
                                <a href="{{ route('projects.analysis-jobs.mapping.edit', [$project, $analysisJob]) }}">Review Mapping</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="pagination">
            {{ $analysisJobs->links() }}
        </div>
    @endif
@endsection
