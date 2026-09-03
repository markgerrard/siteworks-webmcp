<?php

namespace Database\Factories;

use App\Enums\PageType;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

class GeneratedPageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'page_type' => PageType::Home,
            'content_data' => [
                'hero' => [
                    'heading' => fake()->catchPhrase(),
                    'subheading' => fake()->sentence(),
                    'cta_label' => 'Get a Free Quote',
                ],
                'services' => [
                    'heading' => 'Our Services',
                    'intro' => 'We offer',
                    'items' => [
                        ['title' => 'Boiler Installation', 'body' => fake()->sentence()],
                        ['title' => 'Bathroom Fitting', 'body' => fake()->sentence()],
                    ],
                ],
                'cta' => [
                    'heading' => 'Ready to get started?',
                    'subheading' => 'Call us today.',
                    'cta_label' => 'Call Now',
                ],
            ],
            'version' => 1,
            'model_used' => null,
            'hero_source' => 'shared',
        ];
    }
}
