<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteMedia;
use Illuminate\Database\Eloquent\Factories\Factory;

class SiteMediaFactory extends Factory
{
    protected $model = SiteMedia::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'source' => 'upload',
            'url' => fake()->url(),
            's3_key' => 'test/'.fake()->uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'kind' => 'image',
            'origin' => 'uploaded',
            'provisional' => false,
            'tags' => [],
        ];
    }
}
