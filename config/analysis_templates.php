<?php

/*
|--------------------------------------------------------------------------
| Analysis Templates
|--------------------------------------------------------------------------
|
| Each entry declares one Analysis Template: a business-level analysis
| purpose a user can select instead of writing a free-form prompt. See
| docs/product/ANALYSIS_TEMPLATE_MODULE.md.
|
| A Template holds only declarative data — semantic field definitions,
| required/optional rules, a fixed business instruction, and hints for
| the Derived Metrics Planning AI. It never holds actual CSV column
| names (those are resolved per-DataFile at runtime by
| ResolveAnalysisTemplateAction / MapAnalysisTemplateColumnsAction /
| ValidateColumnMappingAction) and never holds fixed calculation
| formulas (recommended_derived_metrics are hints only — Planning AI
| still decides what to actually propose, per docs/product/DERIVED_METRICS.md).
|
| 'fields' keys are semantic field identifiers. Each field's 'kind'
| drives Column Mapping's type-based candidate filtering:
|
|   dimension -> DataProfilingAction inferred_type 'string'
|   measure   -> DataProfilingAction inferred_type 'integer' or 'decimal'
|   temporal  -> DataProfilingAction inferred_type 'date' or 'datetime'
|
| 'required_fields': semantic fields that must resolve to a column
| (confidence 'high') or the AnalysisJob fails.
|
| 'required_field_groups': each inner list is an "at least one of"
| requirement — at least one field in the group must resolve, or the
| AnalysisJob fails.
|
| 'recommended_derived_metrics' entries reference 'left_field'/'right_field'
| by semantic field key (not CSV column name) and an 'operator_hint' from
| CalculateDerivedMetricsAction's allowed operator list. Planning AI
| translates these into real CalculationDefinitions using column_mapping;
| a hint referencing an unmapped field is simply ignored — it is never
| enforced by Laravel.
|
*/

return [

    'ad_performance' => [
        'name' => '広告パフォーマンス分析',
        'description' => '広告チャネルごとの成果・効率・改善余地を分析します。',

        'fields' => [
            'channel' => ['kind' => 'dimension', 'label' => 'チャネル'],
            'campaign' => ['kind' => 'dimension', 'label' => 'キャンペーン'],
            'spend' => ['kind' => 'measure', 'label' => '広告費'],
            'revenue' => ['kind' => 'measure', 'label' => '売上'],
            'conversions' => ['kind' => 'measure', 'label' => 'コンバージョン数'],
            'clicks' => ['kind' => 'measure', 'label' => 'クリック数'],
            'impressions' => ['kind' => 'measure', 'label' => 'インプレッション数'],
            'date' => ['kind' => 'temporal', 'label' => '日付'],
        ],

        'required_fields' => ['channel'],

        'required_field_groups' => [
            ['spend', 'revenue', 'conversions', 'clicks', 'impressions'],
        ],

        'instruction' => '広告チャネルごとの成果を比較し、効率が良いチャネルと改善が必要なチャネルを特定してください。可能であれば改善案も示してください。',

        'recommended_derived_metrics' => [
            [
                'name' => 'return_on_ad_spend',
                'left_field' => 'revenue',
                'right_field' => 'spend',
                'operator_hint' => 'divide',
            ],
            [
                'name' => 'cost_per_conversion',
                'left_field' => 'spend',
                'right_field' => 'conversions',
                'operator_hint' => 'divide',
            ],
            [
                'name' => 'conversion_rate',
                'left_field' => 'conversions',
                'right_field' => 'clicks',
                'operator_hint' => 'percentage',
            ],
            [
                'name' => 'click_through_rate',
                'left_field' => 'clicks',
                'right_field' => 'impressions',
                'operator_hint' => 'percentage',
            ],
        ],
    ],

];
