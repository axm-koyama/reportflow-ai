@extends('layouts.app')

@section('title', 'Edit Project')

@section('content')
    @if ($errors->any())
        <div class="errors">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('projects.update', $project) }}">
        @csrf
        @method('PUT')

        <div class="field">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" value="{{ old('name', $project->name) }}">
        </div>

        <div class="field">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="4">{{ old('description', $project->description) }}</textarea>
        </div>

        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                @foreach ($statusOptions as $status)
                    <option value="{{ $status->value }}" @selected(old('status', $project->status->value) === $status->value)>
                        {{ ucfirst($status->value) }}
                    </option>
                @endforeach
            </select>
        </div>

        <button type="submit" class="btn">Update Project</button>
        <a href="{{ route('projects.index') }}" class="btn btn-secondary">Cancel</a>
    </form>
@endsection
