<?php

namespace Database\Factories;

use App\Enums\GenerationMode;
use App\Enums\SiteStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SiteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'created_by_user_id' => User::factory(),
            'assigned_to_user_id' => null,
            'business_name' => fake()->company(),
            'business_type' => fake()->randomElement([
                'Plumber', 'Electrician', 'Builder', 'Roofer', 'Architect',
                'Landscaper', 'Decorator', 'Joiner', 'Plasterer', 'Tiler',
            ]),
            'location' => fake()->city(),
            'status' => SiteStatus::Draft,
            'generation_mode' => GenerationMode::NoSite,
            'theme' => 'trades-bold',
            // Production column default is false (inert-before-dev). Test
            // sites opt in so existing shop fixtures keep exercising the ON
            // path; pin the off-path with shopDisabled().
            'shop_enabled' => true,
        ];
    }

    public function shopEnabled(): static
    {
        return $this->state(['shop_enabled' => true]);
    }

    public function shopDisabled(): static
    {
        return $this->state(['shop_enabled' => false]);
    }
}
