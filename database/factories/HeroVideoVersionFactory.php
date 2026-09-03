<?php

namespace Database\Factories;

use App\Models\HeroVideoVersion;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HeroVideoVersion>
 */
class HeroVideoVersionFactory extends Factory
{
    protected $model = HeroVideoVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            's3_key' => 'dev-previews/'.fake()->numberBetween(1, 999).'/hero-home-video-'.fake()->uuid().'.mp4',
            'prompt' => 'A wide cinematic shot of a UK trades workshop.',
            'provider' => 'demo',
            'resolution' => '720p',
            'duration_secs' => 8,
            'source' => 'ai_generated',
            'metadata' => [],
            'is_active' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(['is_active' => true]);
    }
}
