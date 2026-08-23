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
            <label for="template_key">分析テンプレート</label>
            <select id="template_key" name="template_key">
                <option value="" @selected(old('template_key') === null)>自由分析</option>
                @foreach ($analysisTemplates as $key => $template)
                    <option value="{{ $key }}" @selected(old('template_key') === $key)>{{ $template['name'] }}</option>
                @endforeach
            </select>
            <ul class="hint">
                @foreach ($analysisTemplates as $template)
                    <li>{{ $template['name'] }}: {{ $template['description'] }}</li>
                @endforeach
            </ul>
        </div>
        <div class="field">
            <label for="prompt">Prompt</label>
            <textarea id="prompt" name="prompt" rows="10" maxlength="5000">{{ old('prompt') }}</textarea>
            <p class="hint">
                自由分析を選択した場合は必須です。テンプレートを選択した場合は、追加のご要望があれば入力してください(任意)。最大5,000文字。
            </p>
        </div>
        <button type="submit" class="btn">Start Analysis</button>
    </form>
@endsection
