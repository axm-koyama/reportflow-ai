@extends('layouts.app')

@section('title', 'Projects')

@section('actions')
    <a href="{{ route('projects.create') }}" class="btn">Create Project</a>
@endsection

@section('content')
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
            @forelse ($projects as $project)
                <tr>
                    <td>{{ $project->name }}</td>
                    <td>{{ $project->description }}</td>
                    <td>
                        <span class="badge {{ $project->status === \App\Enums\ProjectStatus::Active ? 'badge-active' : 'badge-archived' }}">
                            {{ $project->status->value }}
                        </span>
                    </td>
                    <td>{{ $project->created_at?->format('Y-m-d H:i') }}</td>
                    <td><a href="{{ route('projects.edit', $project) }}">Edit</a></td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">No projects yet.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="pagination">
        {{ $projects->links() }}
    </div>
@endsection
