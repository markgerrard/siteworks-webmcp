<?php

namespace Database\Factories;

use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PreviewFactory extends Factory
{
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'slug' => Str::random(12),
            'theme' => 'trades-bold',
            'snapshot' => [],
            'is_active' => true,
            'published_at' => now(),
        ];
    }
}
