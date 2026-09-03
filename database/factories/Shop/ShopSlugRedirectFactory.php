<?php

namespace Database\Factories\Shop;

use App\Models\Shop\ShopSlugRedirect;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShopSlugRedirectFactory extends Factory
{
    protected $model = ShopSlugRedirect::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'kind' => 'product',
            'old_slug' => fake()->slug().'-'.fake()->bothify('??????'),
            'slug' => fake()->slug(),
        ];
    }
}
