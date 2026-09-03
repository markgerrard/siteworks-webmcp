<?php

namespace Database\Factories;

use App\Enums\HeroVersionSource;
use App\Models\HeroVersion;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HeroVersion>
 */
class HeroVersionFactory extends Factory
{
    protected $model = HeroVersion::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'page_type' => 'home',
            'slot' => 'hero',
            'url' => 'https://example.com/hero-'.fake()->uuid().'.png',
            'watermark_url' => null,
            'prompt' => 'A wide hero shot of a UK trades workshop.',
            'model' => null,
            'placement' => null,
            'is_active' => false,
            'upgrade_candidate' => false,
            'source' => HeroVersionSource::AiGenerated,
        ];
    }

    public function active(): static
    {
        return $this->state(['is_active' => true]);
    }
}
