<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sample Rows
    |--------------------------------------------------------------------------
    |
    | Maximum number of sample rows included in a Data Profile. Rows are
    | selected via reservoir sampling while streaming the CSV, so the
    | sample represents the whole file rather than just its first rows.
    |
    */

    'sample_rows' => 10,

    /*
    |--------------------------------------------------------------------------
    | Categorical Top Values
    |--------------------------------------------------------------------------
    |
    | Maximum number of top values included in a categorical column's
    | summary, ordered by frequency (count DESC, value ASC as a
    | deterministic tiebreaker).
    |
    */

    'categorical_top_values' => 10,

];
