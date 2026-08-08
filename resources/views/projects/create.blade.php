@extends('layouts.app')

@section('title', 'Create Project')

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

    <form method="POST" action="{{ route('projects.store') }}">
        @csrf

        <div class="field">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" value="{{ old('name') }}">
        </div>

        <div class="field">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="4">{{ old('description') }}</textarea>
        </div>

        <button type="submit" class="btn">Create Project</button>
        <a href="{{ route('projects.index') }}" class="btn btn-secondary">Cancel</a>
    </form>
@endsection
