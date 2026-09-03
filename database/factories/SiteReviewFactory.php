<?php

namespace Database\Factories;

use App\Enums\SiteReviewStatus;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\SiteReview>
 */
class SiteReviewFactory extends Factory
{
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'author_name' => fake()->name(),
            'rating' => fake()->numberBetween(4, 5),
            'text' => fake()->paragraph(),
            'status' => SiteReviewStatus::Pending,
            'ip_hash' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => SiteReviewStatus::Approved]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => ['status' => SiteReviewStatus::Rejected]);
    }
}
