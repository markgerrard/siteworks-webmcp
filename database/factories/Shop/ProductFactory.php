<?php

namespace Database\Factories\Shop;

use App\Enums\Shop\ProductStatus;
use App\Models\Shop\Product;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'site_id' => Site::factory(),
            'slug' => Str::slug($name).'-'.fake()->randomNumber(3),
            'name' => ucfirst($name),
            'description' => fake()->paragraph(),
            'status' => ProductStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state([
            'status' => ProductStatus::Published,
            'published_at' => now(),
        ]);
    }
}
