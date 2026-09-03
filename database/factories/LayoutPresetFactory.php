<?php

namespace Database\Factories;

use App\Models\LayoutPreset;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LayoutPreset>
 */
class LayoutPresetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'page_kind' => 'service',
            'key' => 'bespoke-'.fake()->unique()->numerify('####'),
            'label' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'recipe' => [
                'schema_version' => 1,
                'variants' => ['intro' => 'editorial', 'features' => 'numbered'],
                'eyebrow_policy' => 'first-only',
                'assigner_hints' => ['wants_imagery' => false, 'minimum_features' => 3],
            ],
            'status' => LayoutPreset::STATUS_DRAFT,
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => LayoutPreset::STATUS_ACTIVE]);
    }

    public function retired(): static
    {
        return $this->state(['status' => LayoutPreset::STATUS_RETIRED]);
    }
}
