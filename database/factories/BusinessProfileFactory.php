<?php

namespace Database\Factories;

use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

class BusinessProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'profile_data' => [
                'name' => fake()->company(),
                'summary' => fake()->sentence(),
                'vibe' => 'professional and reliable',
                'products' => ['Boiler Installation', 'Bathroom Fitting', 'Emergency Repairs'],
                'audience' => 'Homeowners in ' . fake()->city(),
                'differentiators' => ['Gas Safe registered', '24/7 emergency call-out'],
                'tone' => 'friendly, trustworthy',
                'contact' => [
                    'phones' => [fake()->phoneNumber()],
                    'emails' => [fake()->email()],
                ],
                'geo' => [
                    'service_area' => fake()->city() . ' and surrounding areas',
                    'hq' => fake()->city(),
                ],
                'credibility' => [
                    'years_in_business' => '15+',
                    'insurance' => 'Fully insured',
                    'trade_bodies' => ['Gas Safe Register'],
                ],
            ],
            'model_used' => null,
        ];
    }
}
