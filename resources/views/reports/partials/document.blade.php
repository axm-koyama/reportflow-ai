<article class="html-report">
    <header>
        <h2>{{ $snapshot['source']['title'] }}</h2>
        <dl class="metadata">
            <dt>AnalysisJob</dt><dd>#{{ $snapshot['source']['analysis_job_id'] }}</dd>
            <dt>Mode</dt><dd>{{ $snapshot['source']['display_mode'] }}</dd>
            <dt>Data File</dt><dd>{{ $snapshot['source']['data_file_name'] }}</dd>
            <dt>Completed At</dt><dd>{{ $snapshot['source']['completed_at'] ? \Illuminate\Support\Carbon::parse($snapshot['source']['completed_at'])->format('Y-m-d H:i:s') : '-' }}</dd>
            <dt>Generated At</dt><dd>{{ \Illuminate\Support\Carbon::parse($snapshot['generated_at'])->format('Y-m-d H:i:s') }}</dd>
            @if ($snapshot['source']['recovered_from_analysis_job_id'] !== null)
                <dt>Recovered From</dt><dd>#{{ $snapshot['source']['recovered_from_analysis_job_id'] }}</dd>
            @endif
        </dl>
    </header>

    <section><h2>Summary</h2><p>{{ $snapshot['analysis']['summary'] }}</p></section>
    <section><h2>Highlights</h2><ul>@forelse ($snapshot['analysis']['highlights'] as $highlight)<li>{{ $highlight }}</li>@empty<li>None</li>@endforelse</ul></section>
    <section>
        <h2>Metrics</h2>
        @forelse ($snapshot['analysis']['metrics'] as $metric)
            <dl><dt>{{ $metric['label'] }}</dt><dd>{{ $metric['value'] }} {{ $metric['unit'] ?? '' }} {{ $metric['change'] ?? '' }}</dd></dl>
        @empty<p>None</p>@endforelse
    </section>
    <section>
        <h2>Tables</h2>
        @forelse ($snapshot['analysis']['tables'] as $table)
            <h3>{{ $table['title'] }}</h3>
            <div class="table-scroll"><table><thead><tr>@foreach ($table['columns'] as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
                <tbody>@foreach ($table['rows'] as $row)<tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach</tbody>
            </table></div>
        @empty<p>None</p>@endforelse
    </section>
    <section><h2>Insights</h2>@forelse ($snapshot['analysis']['insights'] as $insight)<h3>{{ $insight['title'] }}</h3><p>{{ $insight['description'] }}</p>@if ($insight['evidence'] !== null)<p>Evidence: {{ $insight['evidence'] }}</p>@endif @empty<p>None</p>@endforelse</section>
    @if ($snapshot['analysis']['recommendations'] !== [])
        <section><h2>Recommendations</h2>@foreach ($snapshot['analysis']['recommendations'] as $recommendation)<h3>{{ $recommendation['title'] }}</h3><p>{{ $recommendation['description'] }}</p>@if ($recommendation['priority'] !== null)<p>Priority: {{ $recommendation['priority'] }}</p>@endif @endforeach</section>
    @endif

    <section>
        <h2>Evaluation</h2>
        @if (! $snapshot['evaluation']['applicable'])
            <p>Evaluation is not applicable to this analysis.</p>
        @elseif ($snapshot['evaluation']['rows'] === [])
            <p>Evaluation data was not available for this report.</p>
        @else
            <div class="table-scroll"><table><thead><tr><th>Entity</th><th>Metric</th><th>Value</th><th>Baseline</th><th>Difference</th><th>Direction</th><th>Evaluation</th></tr></thead><tbody>
                @foreach ($snapshot['evaluation']['rows'] as $row)
                    <tr><td>{{ $row['entity_key'] }}</td><td>{{ $row['metric_label'] }}</td><td>{{ $row['metric_value'] !== null ? number_format($row['metric_value'] * 100, 2).'%' : '-' }}</td><td>{{ $row['display_baseline_value'] !== null ? number_format($row['display_baseline_value'] * 100, 2).'%' : '-' }}</td><td>{{ $row['delta_absolute'] !== null ? ($row['delta_absolute'] >= 0 ? '+' : '').number_format($row['delta_absolute'] * 100, 2).'pp' : '-' }}</td><td>{{ $row['direction'] ?? '-' }}</td><td>{{ $row['evaluation_level'] }}</td></tr>
                @endforeach
            </tbody></table></div>
        @endif
    </section>

    <section><h2>原因の仮説</h2>@forelse ($snapshot['diagnosis']['rows'] as $row)<h3>{{ $row['entity_key'] }}</h3>@if ($row['status'] === 'available')<p>{{ $row['category_label'] }}</p><p>{{ $row['rationale'] }}</p>@if ($row['evidence_refs'] !== [])<p>Evidence: {{ implode(' / ', $row['evidence_refs']) }}</p>@endif @if ($row['missing_evidence'] !== [])<p>Missing evidence: {{ implode(' / ', $row['missing_evidence']) }}</p>@endif @else<p>Diagnosis was unavailable for this eligible evidence.</p>@endif @empty<p>No diagnosis-eligible evidence was included.</p>@endforelse</section>

    <section><h2>確認優先度</h2>@forelse ($snapshot['priority']['rows'] as $row)<h3>{{ $row['entity_key'] }}</h3>@if ($row['status'] === 'not_eligible')<p>Not eligible.</p>@elseif ($row['status'] === 'unavailable')<p>Priority was unavailable for this eligible evidence.</p>@else<p>{{ ucfirst($row['priority_band']) }}</p><p>流量影響 {{ number_format($row['impact_score'] * 100, 1) }}% / 比較対照との差 {{ $row['gap_raw_value'] !== null ? number_format($row['gap_raw_value'] * 100, 2).'pp' : '-' }}</p>@endif @empty<p>No priority evidence was included.</p>@endforelse</section>

    <section>
        <h2>Controlled Actions</h2>
        @forelse ($snapshot['controlled_actions']['proposals'] as $proposal)
            <article><h3>{{ $proposal['title'] }}</h3><p><span class="badge badge-advisory">{{ $proposal['advisory_label'] }}</span></p><p>Catalog: {{ $proposal['catalog_label'] }}</p><p>Target: {{ $proposal['entity_key'] }} / {{ $proposal['metric_label'] }}</p><p>確認優先度: {{ ucfirst($proposal['priority_band']) }}</p><p>{{ $proposal['rationale'] }}</p><p>Evidence: {{ implode(' / ', $proposal['evidence_refs']) }}</p>@if ($proposal['selected_checks'] !== [])<p>Checks: {{ implode(' / ', $proposal['selected_checks']) }}</p>@endif @if ($proposal['missing_evidence'] !== [])<p>Missing evidence: {{ implode(' / ', $proposal['missing_evidence']) }}</p>@endif</article>
        @empty
            <p>No controlled action proposal was generated.</p>
            @if (! $snapshot['controlled_actions']['applicable'])<p>Controlled Actions are not applicable to this analysis.</p>@elseif ($snapshot['controlled_actions']['eligible_count'] === 0)<p>No evidence currently meets the controlled eligibility contract.</p>@else<p>Eligible evidence existed, but proposals are best-effort output and may be unavailable.</p>@endif
        @endforelse
    </section>
</article>
