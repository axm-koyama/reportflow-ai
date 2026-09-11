@extends('layouts.app')

@section('title', 'Analysis History - '.$project->name)

@section('actions')
    <a href="{{ route('projects.data-files.index', $project) }}" class="btn">Data Files</a>
    <a href="{{ route('projects.index') }}" class="btn btn-secondary">Back to Projects</a>
@endsection

@section('content')
    <x-breadcrumb :items="[['label' => 'Projects', 'href' => route('projects.index')], ['label' => $project->name], ['label' => 'Analysis History']]" />
    <p><strong>{{ $project->name }}</strong></p>

    @if ($analysisJobs->isEmpty())
        <x-empty-state message="No analyses have been created for this project yet." action-label="Go to Data Files" :action-href="route('projects.data-files.index', $project)" />
    @else
        <p class="hint">Open an analysis to view live status updates.</p>
        <div class="table-scroll"><table>
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
                        <td><x-badge :variant="str_replace('badge-', '', $analysisJob->status->badgeClass())" :label="$analysisJob->status->label()" /></td>
                        <td>{{ $analysisJob->created_at?->format('Y-m-d H:i') }}</td>
                        <td>{{ $analysisJob->updated_at?->format('Y-m-d H:i') }}</td>
                        <td>
                            <a href="{{ route('projects.analysis-jobs.show', [$project, $analysisJob]) }}" class="btn-link">View Details</a>
                            @if ($analysisJob->recoveredFrom)
                                <a href="{{ route('projects.analysis-jobs.show', [$project, $analysisJob->recoveredFrom]) }}" class="btn-link">Recovered from #{{ $analysisJob->recovered_from_analysis_job_id }}</a>
                            @endif
                            @if ($analysisJob->status === \App\Enums\AnalysisJobStatus::AwaitingMappingConfirmation)
                                <a href="{{ route('projects.analysis-jobs.mapping.edit', [$project, $analysisJob]) }}" class="btn-link">Review Mapping</a>
                            @endif
                            @if ($analysisJob->status === \App\Enums\AnalysisJobStatus::Failed)
                                @if ($analysisJob->recoveryAttempt)
                                    <a href="{{ route('projects.analysis-jobs.show', [$project, $analysisJob->recoveryAttempt]) }}" class="btn-link">View Recovery Attempt</a>
                                @elseif ($project->status === \App\Enums\ProjectStatus::Active)
                                    <form method="POST" action="{{ route('projects.analysis-jobs.recover', [$project, $analysisJob]) }}">
                                        @csrf
                                        <button
                                            type="submit"
                                            class="btn"
                                            onclick="return confirm('Create a new recovery attempt? This may make new AI calls and incur additional cost.')"
                                        >Create Recovery Attempt</button>
                                        <span class="hint">This creates a new attempt and may make new AI calls and incur additional cost. The failed attempt remains unchanged.</span>
                                    </form>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>

        {{ $analysisJobs->links('components.pagination') }}
    @endif
@endsection
