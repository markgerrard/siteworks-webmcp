<?php

namespace Database\Factories;

use App\Models\BeforeAfterPair;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

class BeforeAfterPairFactory extends Factory
{
    protected $model = BeforeAfterPair::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'page_id' => null,
            'sort_order' => 0,
            'narrative' => fake()->sentence(20),
            'before_image_id' => null,
            'after_image_id' => null,
            'before_prompt' => fake()->sentence(15),
            'after_transformation_prompt' => 'Same scene, after transformation. '.fake()->sentence(10),
            'status' => 'draft',
        ];
    }
}
