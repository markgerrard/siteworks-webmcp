<?php

namespace Database\Factories;

use App\Enums\ProjectItemSource;
use App\Enums\ProjectItemStatus;
use App\Enums\ProjectItemType;
use App\Models\ProjectItem;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectItemFactory extends Factory
{
    protected $model = ProjectItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'page_id' => null,
            'detail_page_id' => null,
            'type' => ProjectItemType::Gallery,
            'sort_order' => 0,
            'status' => ProjectItemStatus::Draft,
            'source' => ProjectItemSource::AiGenerated,
            'category' => fake()->randomElement(['Residential', 'Commercial', 'Heritage']),
            'category_id' => null,
            'title' => fake()->sentence(4),
            'description' => fake()->sentence(12),
            'image_id' => null,
            'metrics' => null,
            'metadata' => null,
            'content_hash' => str_repeat('0', 40),
            'media_hash' => str_repeat('0', 40),
            'image_job_state' => null,
        ];
    }

    public function gallery(): self
    {
        return $this->state(['type' => ProjectItemType::Gallery]);
    }

    public function caseStudy(): self
    {
        return $this->state([
            'type' => ProjectItemType::CaseStudy,
            'metrics' => [['icon' => 'timer', 'label' => '5-7 day install']],
        ]);
    }

    public function archived(): self
    {
        return $this->state(['status' => ProjectItemStatus::Archived]);
    }

    public function published(): self
    {
        return $this->state(['status' => ProjectItemStatus::Published]);
    }
}
