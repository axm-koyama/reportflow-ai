@extends('layouts.app')

@section('title', '列マッピングの確認')

@section('actions')
    <a href="{{ route('projects.analysis-jobs.show', [$project, $analysisJob]) }}" class="btn-link">戻る</a>
@endsection

@section('content')
    <x-breadcrumb :items="[['label' => 'Projects', 'href' => route('projects.index')], ['label' => $project->name, 'href' => route('projects.analysis-jobs.index', $project)], ['label' => $analysisJob->title, 'href' => route('projects.analysis-jobs.show', [$project, $analysisJob])], ['label' => 'Mapping']]" />
    <div class="card">
        <p><strong>テンプレート:</strong> {{ $template['name'] }}</p>
        <p><strong>Data File:</strong> {{ $analysisJob->dataFile->original_name }}</p>
        <p class="hint">
            AIによる列マッピングの確信度が十分でなかったため、分析を開始する前に列の対応関係をご確認ください。
            「AI」の列は、AIが提案しLaravelが検証した結果です。必要な項目には列を選択してください(未設定のままでは分析を開始できません)。
        </p>
    </div>

    @include('components.errors')

    <form method="POST" action="{{ route('projects.analysis-jobs.mapping.update', [$project, $analysisJob]) }}">
        @csrf
        @method('PATCH')

        <div class="table-scroll"><table>
            <thead>
                <tr>
                    <th>項目</th>
                    <th>AI提案</th>
                    <th>使用する列</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($template['fields'] as $field => $definition)
                    @php
                        $isRequired = in_array($field, $template['required_fields'] ?? [], true);
                        $inRequiredGroup = collect($template['required_field_groups'] ?? [])->contains(fn ($group) => in_array($field, $group, true));
                        $ai = $aiColumnMapping[$field] ?? null;
                        $candidates = $columnCandidates[$field] ?? [];

                        // Priority for what the dropdown shows as pre-selected:
                        // 1. a resubmitted form's own value (validation error redisplay)
                        // 2. an already-saved manual override for this field (column may itself be null = "explicitly unset")
                        // 3. the AI's mapped column
                        // 4. nothing
                        if (array_key_exists($field, $manualColumnMapping)) {
                            $default = $manualColumnMapping[$field]['column'] ?? null;
                        } elseif ($ai !== null && $ai['status'] === 'mapped') {
                            $default = $ai['column'];
                        } else {
                            $default = null;
                        }

                        $selected = old("mapping.{$field}.column", $default);
                    @endphp
                    <tr>
                        <td>
                            {{ $definition['label'] }}
                            @if ($isRequired)
                                <span class="required-label">必須</span>
                            @elseif ($inRequiredGroup)
                                <span class="hint" title="このグループのいずれか1つが必須">(グループ必須)</span>
                            @endif
                        </td>
                        <td>
                            @if ($ai && $ai['status'] === 'mapped')
                                {{ $ai['column'] }} <span class="hint">AI confidence: {{ $ai['confidence'] }}</span>
                            @else
                                未設定
                            @endif
                        </td>
                        <td>
                            <select name="mapping[{{ $field }}][column]">
                                <option value="" @selected($selected === null)>(未設定)</option>
                                @foreach ($candidates as $candidate)
                                    <option value="{{ $candidate['column'] }}" @selected($selected === $candidate['column'])>{{ $candidate['column'] }}</option>
                                @endforeach
                            </select>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>

        <button type="submit" class="btn">このMappingで分析</button>
    </form>
@endsection
