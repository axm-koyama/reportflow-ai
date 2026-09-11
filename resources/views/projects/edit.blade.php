@extends('layouts.app')

@section('title', 'Edit Project')

@section('content')
    <x-breadcrumb :items="[['label' => 'Projects', 'href' => route('projects.index')], ['label' => $project->name], ['label' => 'Edit']]" />
    @include('components.errors')

    <form method="POST" action="{{ route('projects.update', $project) }}">
        @csrf
        @method('PUT')

        <div class="field">
            <label for="name">Name <span class="required-label">Required</span></label>
            <input type="text" id="name" name="name" value="{{ old('name', $project->name) }}">
        </div>

        <div class="field">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="4">{{ old('description', $project->description) }}</textarea>
        </div>

        <div class="field">
            <label for="status">Status <span class="required-label">Required</span></label>
            <select id="status" name="status">
                @foreach ($statusOptions as $status)
                    <option value="{{ $status->value }}" @selected(old('status', $project->status->value) === $status->value)>
                        {{ ucfirst($status->value) }}
                    </option>
                @endforeach
            </select>
        </div>

        <button type="submit" class="btn">Update Project</button>
        <a href="{{ route('projects.index') }}" class="btn-link">Cancel</a>
    </form>
@endsection
