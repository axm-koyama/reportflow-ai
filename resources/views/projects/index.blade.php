@extends('layouts.app')

@section('title', 'Projects')

@section('actions')
    <a href="{{ route('projects.create') }}" class="btn">Create Project</a>
@endsection

@section('content')
    @if ($projects->isEmpty())
        <x-empty-state message="No projects yet." action-label="Create Project" :action-href="route('projects.create')" />
    @else
    <div class="table-scroll">
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Description</th>
                <th>Status</th>
                <th>Created At</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($projects as $project)
                <tr>
                    <td>{{ $project->name }}</td>
                    <td>{{ $project->description }}</td>
                    <td>
                        <x-badge :variant="$project->status === \App\Enums\ProjectStatus::Active ? 'active' : 'archived'" :label="$project->status->value" />
                    </td>
                    <td>{{ $project->created_at?->format('Y-m-d H:i') }}</td>
                    <td>
                        <a href="{{ route('projects.data-files.index', $project) }}" class="btn-link">Data Files</a>
                        <a href="{{ route('projects.analysis-jobs.index', $project) }}" class="btn-link">Analysis History</a>
                        <a href="{{ route('projects.edit', $project) }}" class="btn-link">Edit</a>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>

    <div class="pagination">
        {{ $projects->links() }}
    </div>
    @endif
@endsection
