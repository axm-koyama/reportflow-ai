@extends('layouts.app')

@section('title', 'Create Analysis Job')

@section('actions')
    <a href="{{ route('projects.data-files.index', $project) }}" class="btn-link">Back to Data Files</a>
@endsection

@section('content')
    <x-breadcrumb :items="[['label' => 'Projects', 'href' => route('projects.index')], ['label' => $project->name, 'href' => route('projects.data-files.index', $project)], ['label' => $dataFile->original_name], ['label' => 'Create Analysis']]" />
    <div class="card">
        <p><strong>Project:</strong> {{ $project->name }}</p>
        <p><strong>Data File:</strong> {{ $dataFile->original_name }}</p>
    </div>

    @include('components.errors')

    <form method="POST" action="{{ route('projects.data-files.analysis-jobs.store', [$project, $dataFile]) }}">
        @csrf
        <div class="field">
            <label for="title">Title <span class="required-label">Required</span></label>
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
            <p class="hint"><strong>自由分析を選ぶ場合</strong>は、ここに分析内容を入力してください。</p>
            <textarea id="prompt" name="prompt" rows="10" maxlength="5000">{{ old('prompt') }}</textarea>
            <p class="hint">
                自由分析を選択した場合は必須です。テンプレートを選択した場合は、追加のご要望があれば入力してください(任意)。最大5,000文字。
            </p>
        </div>
        <button type="submit" class="btn">Start Analysis</button>
    </form>
@endsection
