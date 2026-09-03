<?php

namespace Database\Factories\Shop;

use App\Models\Shop\Category;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        $name = fake()->words(2, true);

        return [
            'site_id' => Site::factory(),
            'slug' => Str::slug($name).'-'.fake()->randomNumber(3),
            'name' => ucfirst($name),
            'sort_order' => 0,
        ];
    }
}
