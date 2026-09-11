@extends('layouts.app')

@section('title', 'Create Project')

@section('content')
    <x-breadcrumb :items="[['label' => 'Projects', 'href' => route('projects.index')], ['label' => 'Create Project']]" />
    @include('components.errors')

    <form method="POST" action="{{ route('projects.store') }}">
        @csrf

        <div class="field">
            <label for="name">Name <span class="required-label">Required</span></label>
            <input type="text" id="name" name="name" value="{{ old('name') }}">
        </div>

        <div class="field">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="4">{{ old('description') }}</textarea>
        </div>

        <button type="submit" class="btn">Create Project</button>
        <a href="{{ route('projects.index') }}" class="btn-link">Cancel</a>
    </form>
@endsection
