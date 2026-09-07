@extends('layouts.app')

@section('title', 'Data Files - '.$project->name)

@section('actions')
    <a href="{{ route('projects.analysis-jobs.index', $project) }}" class="btn">Analysis History</a>
    <a href="{{ route('projects.index') }}" class="btn btn-secondary">Back to Projects</a>
@endsection

@section('content')
    <p>
        <strong>{{ $project->name }}</strong>
        <span class="badge {{ $canUpload ? 'badge-active' : 'badge-archived' }}">
            {{ $project->status->value }}
        </span>
    </p>

    @if ($errors->any())
        <div class="errors">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($canUpload)
        <form method="POST" action="{{ route('projects.data-files.store', $project) }}" enctype="multipart/form-data">
            @csrf

            <div class="field">
                <label for="file">CSV File</label>
                <input type="file" id="file" name="file" accept=".csv">
                <p class="hint">CSV files only. Maximum 10 MB.</p>
            </div>

            <button type="submit" class="btn">Upload</button>
        </form>
    @else
        <p class="hint">Archived projects cannot accept new DataFiles.</p>
    @endif

    <table>
        <thead>
            <tr>
                <th>Original File Name</th>
                <th>MIME Type</th>
                <th>Size</th>
                <th>Uploaded At</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($dataFiles as $dataFile)
                <tr>
                    <td>{{ $dataFile->original_name }}</td>
                    <td>{{ $dataFile->mime_type }}</td>
                    <td>{{ $formattedSizes[$dataFile->data_file_id] }}</td>
                    <td>{{ $dataFile->created_at?->format('Y-m-d H:i') }}</td>
                    <td>
                        @if ($canUpload)
                            <a href="{{ route('projects.data-files.analysis-jobs.create', [$project, $dataFile]) }}" class="btn">Analyze</a>
                        @else
                            <span class="hint">Unavailable</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">No data files uploaded yet.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="pagination">
        {{ $dataFiles->links() }}
    </div>
@endsection
