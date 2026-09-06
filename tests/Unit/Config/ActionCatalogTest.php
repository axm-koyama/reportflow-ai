<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Tests\TestCase;

class ActionCatalogTest extends TestCase
{
    public function test_v1_catalog_contains_only_the_two_approved_entries(): void
    {
        $this->assertSame([
            'verify_measurement_consistency',
            'collect_explanatory_evidence',
        ], array_keys(config('action_catalog')));
    }

    public function test_every_entry_has_the_required_contract_shape(): void
    {
        foreach (config('action_catalog') as $entry) {
            $this->assertTrue($entry['enabled']);
            $this->assertIsString($entry['label']);
            $this->assertIsArray($entry['allowed_diagnosis_categories']);
            $this->assertIsArray($entry['allowed_checks']);
            $this->assertSame(120, $entry['title_max_characters']);
            $this->assertSame(1000, $entry['rationale_max_characters']);
        }
    }
}
