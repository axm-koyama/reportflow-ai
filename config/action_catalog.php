<?php

declare(strict_types=1);

return [
    'verify_measurement_consistency' => [
        'enabled' => true,
        'label' => 'Verify measurement consistency',
        'allowed_diagnosis_categories' => ['measurement_consistency_risk'],
        'allowed_checks' => [
            'verify_event_definition',
            'verify_tag_firing',
            'verify_time_window',
            'verify_source_completeness',
        ],
        'title_max_characters' => 120,
        'rationale_max_characters' => 1000,
    ],
    'collect_explanatory_evidence' => [
        'enabled' => true,
        'label' => 'Collect explanatory evidence',
        'allowed_diagnosis_categories' => ['insufficient_explanatory_evidence'],
        'allowed_checks' => [],
        'title_max_characters' => 120,
        'rationale_max_characters' => 1000,
    ],
];
