<?php

namespace Database\Factories;

use App\Enums\LogoConceptSource;
use App\Models\LogoConcept;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LogoConcept>
 */
class LogoConceptFactory extends Factory
{
    protected $model = LogoConcept::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'source' => LogoConceptSource::Generated,
            'version' => 0,
            'path' => 'dev-previews/'.fake()->numberBetween(1, 999).'/logos/'.fake()->uuid().'.png',
            'is_selected' => false,
            'metadata' => [],
        ];
    }

    public function manual(): static
    {
        return $this->state(fn () => [
            'source' => LogoConceptSource::Manual,
            'prompt' => 'minimalist line-mark with a wrench',
            'quality' => 'low',
        ]);
    }

    public function selected(): static
    {
        return $this->state(['is_selected' => true]);
    }
}
