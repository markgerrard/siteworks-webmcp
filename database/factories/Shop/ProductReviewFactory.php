<?php

namespace Database\Factories\Shop;

use App\Enums\Shop\ProductReviewSource;
use App\Enums\Shop\ProductReviewStatus;
use App\Models\Shop\Product;
use App\Models\Shop\ProductReview;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductReview>
 */
class ProductReviewFactory extends Factory
{
    protected $model = ProductReview::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'product_id' => Product::factory(),
            'rating' => fake()->numberBetween(1, 5),
            'title' => fake()->sentence(3),
            'body' => fake()->paragraph(),
            'author_name' => fake()->firstName(),
            'author_email_hash' => null,
            'status' => ProductReviewStatus::Pending,
            'source' => ProductReviewSource::Shopper,
            'invite_token_hash' => null,
            'ip_hash' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => ProductReviewStatus::Published]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => ProductReviewStatus::Pending]);
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['status' => ProductReviewStatus::Hidden]);
    }

    public function seed(): static
    {
        return $this->state(fn () => [
            'source' => ProductReviewSource::Seed,
            'status' => ProductReviewStatus::Published,
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn () => ['source' => ProductReviewSource::Manual]);
    }

    public function shopper(): static
    {
        return $this->state(fn () => ['source' => ProductReviewSource::Shopper]);
    }

    public function invited(): static
    {
        return $this->state(fn () => ['source' => ProductReviewSource::Invited]);
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ProductReview $review): void {
            if ($review->site_id && ! $review->product_id) {
                return;
            }
            if ($review->product_id && $review->site_id) {
                $productSiteId = Product::query()->whereKey($review->product_id)->value('site_id');
                if ($productSiteId) {
                    $review->site_id = (int) $productSiteId;
                }
            }
        })->afterCreating(function (ProductReview $review): void {
            $product = $review->product;
            if ($product && (int) $review->site_id !== (int) $product->site_id) {
                $review->forceFill(['site_id' => $product->site_id])->saveQuietly();
            }
        });
    }
}
