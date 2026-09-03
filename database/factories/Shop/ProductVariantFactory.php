<?php

namespace Database\Factories\Shop;

use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => strtoupper(fake()->lexify('???-???')),
            'label' => fake()->randomElement(['Small', 'Medium', 'Large']),
            'price_cents' => fake()->numberBetween(500, 10000),
        ];
    }
}
