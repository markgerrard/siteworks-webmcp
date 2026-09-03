<?php

namespace Database\Factories;

use App\Models\GeneratedPage;
use App\Models\ImportedMedia;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

class ImportedMediaFactory extends Factory
{
    protected $model = ImportedMedia::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $externalId = (string) fake()->numberBetween(100000000000000, 999999999999999);

        return [
            'site_id' => Site::factory(),
            'source' => 'facebook',
            'external_id' => $externalId,
            'url' => "https://siteworks.lon1.digitaloceanspaces.com/sites/1/imported/facebook/{$externalId}.jpg",
            'width' => 1600,
            'height' => 1200,
            'caption' => fake()->optional()->sentence(),
            'imported_at' => now(),
            'assigned_to' => null,
            'assigned_page_id' => null,
            'placement' => null,
        ];
    }

    public function forSite(Site $site): static
    {
        return $this->state(fn () => [
            'site_id' => $site->id,
        ]);
    }

    public function assignedAsHero(): static
    {
        return $this->state(fn () => [
            'assigned_to' => 'hero',
            'assigned_page_id' => GeneratedPage::factory(),
        ]);
    }

    public function assignedAsProject(): static
    {
        return $this->state(fn () => [
            'assigned_to' => 'project',
            'assigned_page_id' => GeneratedPage::factory(),
        ]);
    }
}
