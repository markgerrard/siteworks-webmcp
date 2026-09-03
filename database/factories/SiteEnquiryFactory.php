<?php

namespace Database\Factories;

use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\SiteEnquiry>
 */
class SiteEnquiryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'payload' => ['message' => fake()->sentence()],
            'page_type' => 'home',
            'status' => 'new',
            'ip_hash' => null,
        ];
    }
}
