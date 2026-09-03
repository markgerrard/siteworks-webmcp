<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteSubscription>
 */
class SiteSubscriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'product' => SiteSubscription::PRODUCT_MANAGED_CONTENT,
            'monthly_page_quota' => 3,
            'kinds' => SiteSubscription::DEFAULT_KINDS,
            'active' => true,
            'started_at' => now(),
            'carryover_credit' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'active' => false,
        ]);
    }
}
