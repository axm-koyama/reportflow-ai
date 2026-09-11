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
| This file is AI-facing configuration only ('fields' / 'recommended_derived_metrics'
| are read into Mapping/Planning AI Context). Phase 4-A's Deterministic
| Evaluation Engine — a Laravel-only concern the AI never sees — is
| configured separately in config/evaluation_metrics.php, keyed by the
| same Template keys. See docs/product/EVALUATION_ENGINE.md.
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
| a hint whose left_field/right_field is not both a "mapped" column is
| deterministically filtered out by ResolveAnalysisTemplateAction before
| either AI call ever sees it (see docs/product/ANALYSIS_TEMPLATE_MODULE.md
| §9.1) — this is never left to Planning AI's own judgment.
|
| A 'temporal' field (e.g. 'date') is fully supported by Column Mapping —
| it resolves to a real column and is recorded in column_mapping like any
| other field. As of Phase 3-B it is a mapping-only concept, however:
| MetricAggregationAction never selects a 'date'/'datetime'-inferred
| column as an aggregation dimension (it only aggregates
| inferred_type === 'string' columns), so a temporal field never appears
| in aggregated_metrics.dimensions, is never usable as a
| CalculationDefinition group_by, and is never used to recompute a
| time-series trend from sample_rows. See
| docs/product/ANALYSIS_TEMPLATE_MODULE.md §15 for the full rationale and
| the planned future Temporal Aggregation / Trend Analysis extension.
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

    'sales_analysis' => [
        'name' => '売上分析',
        'description' => '商品・カテゴリ・店舗・地域・顧客など、データに存在する切り口で売上規模と構成を分析します。',

        'fields' => [
            'revenue' => ['kind' => 'measure', 'label' => '売上'],
            'quantity' => ['kind' => 'measure', 'label' => '数量'],
            'orders' => ['kind' => 'measure', 'label' => '注文数'],
            'product' => ['kind' => 'dimension', 'label' => '商品'],
            'category' => ['kind' => 'dimension', 'label' => 'カテゴリ'],
            'store' => ['kind' => 'dimension', 'label' => '店舗'],
            'region' => ['kind' => 'dimension', 'label' => '地域'],
            'customer' => ['kind' => 'dimension', 'label' => '顧客'],
            'date' => ['kind' => 'temporal', 'label' => '日付'],
        ],

        // Only "revenue" is required: Sales CSVs vary widely in shape, and
        // every other field (quantity/orders/product/category/store/region/
        // customer/date) is optional — whichever of them actually resolves
        // simply becomes an available cut for the analysis, per
        // docs/product/ANALYSIS_TEMPLATE_MODULE.md §14.
        'required_fields' => ['revenue'],
        'required_field_groups' => [],

        // "date" is deliberately not listed as an aggregation cut here: as
        // of Phase 3-B it is Column-Mapping-only (see the file-level
        // docblock above and docs/product/ANALYSIS_TEMPLATE_MODULE.md
        // §15) — it is never an aggregated_metrics dimension, so it must
        // not be described to the AI as something it can group sales by.
        'instruction' => '商品・カテゴリ・店舗・地域・顧客など、実際に利用可能な集計切り口を分析対象とし、売上の規模と構成における特徴的な傾向や偏りがあれば指摘し、可能であれば改善点も示してください。日付列が存在する場合、日付情報を含むデータであることの認識には利用できますが、このPhaseでは日付範囲の確定、日付別・月別・週別などの時系列集計、前年比・前月比・前週比などの期間比較には使用しないでください。',

        'recommended_derived_metrics' => [
            [
                'name' => 'average_unit_price',
                'left_field' => 'revenue',
                'right_field' => 'quantity',
                'operator_hint' => 'divide',
            ],
            [
                'name' => 'average_order_value',
                'left_field' => 'revenue',
                'right_field' => 'orders',
                'operator_hint' => 'divide',
            ],
        ],
    ],

];
