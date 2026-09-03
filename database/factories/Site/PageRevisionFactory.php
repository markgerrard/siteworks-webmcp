<?php

namespace Database\Factories\Site;

use App\Models\GeneratedPage;
use App\Models\Site\PageRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

class PageRevisionFactory extends Factory
{
    protected $model = PageRevision::class;

    public function definition(): array
    {
        return [
            'page_id' => GeneratedPage::factory(),
            'content_data' => ['sections' => [['type' => 'hero', 'title' => fake()->sentence()]]],
            'ai_generated' => true,
            'ai_model_version' => null,
            'created_at' => now(),
        ];
    }
}
