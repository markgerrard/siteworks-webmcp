<?php

namespace Database\Factories;

use App\Models\ThemeTokenPreset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThemeTokenPreset>
 */
class ThemeTokenPresetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'preset-'.fake()->unique()->numerify('####'),
            'description' => fake()->sentence(),
            'tokens' => [
                'color-band' => '#f7f2ea',
                'color-text-on-band' => '#1a1a1a',
            ],
            'created_by_user_id' => User::factory(),
        ];
    }
}
