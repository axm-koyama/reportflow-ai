@extends('layouts.app')

@section('title', 'Create Analysis Job')

@section('actions')
    <a href="{{ route('projects.data-files.index', $project) }}" class="btn btn-secondary">Back to Data Files</a>
@endsection

@section('content')
    <div class="card">
        <p><strong>Project:</strong> {{ $project->name }}</p>
        <p><strong>Data File:</strong> {{ $dataFile->original_name }}</p>
    </div>

    @if ($errors->any())
        <div class="errors"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ route('projects.data-files.analysis-jobs.store', [$project, $dataFile]) }}">
        @csrf
        <div class="field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" value="{{ old('title') }}" maxlength="255" required>
        </div>
        <div class="field">
            <label for="prompt">Prompt</label>
            <textarea id="prompt" name="prompt" rows="10" maxlength="5000" required>{{ old('prompt') }}</textarea>
            <p class="hint">Maximum 5,000 characters.</p>
        </div>
        <button type="submit" class="btn">Start Analysis</button>
    </form>
@endsection
