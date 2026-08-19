<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Max Dimensions
    |--------------------------------------------------------------------------
    |
    | Maximum number of categorical columns aggregated as group-by
    | dimensions. Dimension candidates are ranked by ascending cardinality
    | (fewer distinct values first, since a lower-cardinality dimension
    | groups more meaningfully and costs less AI Context), and only the
    | top N are kept; the rest are dropped, never truncated mid-dimension.
    |
    | 5 covers the typical number of genuinely low-cardinality business
    | dimensions found in a single CSV (e.g. channel, region, product
    | category, segment, status) without letting AI Context size grow
    | unbounded on wide CSVs with many categorical columns.
    |
    */

    'max_dimensions' => 5,

    /*
    |--------------------------------------------------------------------------
    | Max Measures
    |--------------------------------------------------------------------------
    |
    | Maximum number of numeric columns aggregated as measures. Measure
    | candidates are taken in CSV column order (no ranking heuristic
    | exists yet for "which numeric column matters more").
    |
    | 10 is generous relative to how many genuinely distinct numeric
    | business metrics a single CSV typically has (e.g. spend, revenue,
    | conversions, clicks, impressions), while still bounding the worst
    | case for very wide CSVs.
    |
    */

    'max_measures' => 10,

    /*
    |--------------------------------------------------------------------------
    | Max Cardinality Per Dimension
    |--------------------------------------------------------------------------
    |
    | A categorical column only qualifies as a dimension candidate when
    | its unique_count (from the Data Profile) is at most this value.
    | Columns above this threshold (e.g. customer_id, transaction_id,
    | email) are excluded entirely rather than truncated to their top
    | values, since a "group" built from a handful of a near-unique
    | column's most frequent values would not represent a meaningful
    | business dimension.
    |
    | 20 comfortably covers common low-cardinality business dimensions
    | (e.g. up to ~20 channels / regions / product categories) while
    | keeping any single dimension's group count small.
    |
    */

    'max_cardinality_per_dimension' => 20,

    /*
    |--------------------------------------------------------------------------
    | Max Aggregated Rows
    |--------------------------------------------------------------------------
    |
    | Hard cap on the total number of group rows across all included
    | dimensions combined (the sum of every included dimension's
    | group_count). This is the final safety net: with the defaults
    | above it can never actually bind (max_dimensions *
    | max_cardinality_per_dimension = 5 * 20 = 100, exactly this value),
    | but it protects against misconfiguration (e.g. raising the two
    | limits above without reconsidering this one) or unusual data shapes
    | where many dimensions simultaneously sit at the cardinality ceiling.
    |
    | When the running total would exceed this limit, dimensions are kept
    | in priority order (ascending cardinality, same as max_dimensions
    | selection) and the first dimension that would overflow the budget
    | has its lowest-volume groups dropped (ordered by row count
    | ascending) rather than raising an exception; any dimensions after
    | that are dropped entirely.
    |
    */

    'max_aggregated_rows' => 100,

];
