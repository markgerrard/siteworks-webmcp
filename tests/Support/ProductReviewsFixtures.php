<?php

namespace Tests\Support;

use App\Enums\Shop\ProductReviewStatus;
use App\Models\Shop\Product;
use App\Models\Shop\ProductReview;
use App\Models\Shop\ProductVariant;
use App\Models\Site;
use App\Models\User;
use App\Support\Shop\ProductReviewSettings;

final class ProductReviewsFixtures
{
    /**
     * @return array{bakery: list<string>, florist: list<string>}
     */
    public static function verticalDataset(): array
    {
        return [
            'bakery' => ['bakery'],
            'florist' => ['florist'],
        ];
    }

    /**
     * @return array{user: User, site: Site, products: list<Product>}
     */
    public static function make(string $vertical, array $settings = []): array
    {
        return match ($vertical) {
            'florist' => self::florist($settings),
            default => self::bakery($settings),
        };
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{user: User, site: Site, products: list<Product>}
     */
    public static function bakery(array $settings = []): array
    {
        $fixture = self::store(
            'reviews-bakery.example',
            'Camino Bakery',
            [
                ['slug' => 'victoria-sponge', 'name' => 'Victoria Sponge', 'published' => 8, 'pending' => 1],
                ['slug' => 'lemon-drizzle', 'name' => 'Lemon Drizzle', 'published' => 5, 'pending' => 1],
                ['slug' => 'sourdough', 'name' => 'Sourdough loaf', 'published' => 12, 'pending' => 0],
            ],
            $settings === [] ? ['enabled' => true] : $settings,
        );

        return $fixture;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{user: User, site: Site, products: list<Product>}
     */
    public static function florist(array $settings = []): array
    {
        return self::store(
            'reviews-florist.example',
            'Bloom & Stem',
            [
                ['slug' => 'hand-tied', 'name' => 'Hand-tied bouquet', 'published' => 6, 'pending' => 0],
                ['slug' => 'posy', 'name' => 'Posy', 'published' => 4, 'pending' => 0],
            ],
            $settings === [] ? ['enabled' => true] : $settings,
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{user: User, site: Site, products: list<Product>}
     */
    public static function disabled(array $settings = []): array
    {
        return self::store(
            'reviews-disabled.example',
            'Quiet Store',
            [
                ['slug' => 'plain', 'name' => 'Plain item', 'published' => 5, 'pending' => 0],
            ],
            array_merge(['enabled' => false], $settings),
        );
    }

    /**
     * @param  list<array{slug: string, name: string, published: int, pending: int}>  $catalogue
     * @param  array<string, mixed>  $settings
     * @return array{user: User, site: Site, products: list<Product>}
     */
    private static function store(string $host, string $name, array $catalogue, array $settings): array
    {
        $user = User::factory()->staff()->create();
        $validated = ProductReviewSettings::from(array_merge([
            'enabled' => false,
            'label' => 'Reviews',
            'public_form' => false,
            'moderate' => true,
            'show_on_cards' => true,
            'min_reviews_for_card' => 1,
        ], $settings));

        $site = Site::factory()->create([
            'created_by_user_id' => $user->id,
            'custom_domain' => $host,
            'custom_domain_status' => 'active',
            'business_name' => $name,
            'reviews_settings' => $validated->toArray(),
        ]);

        $created = [];
        foreach ($catalogue as $row) {
            $product = Product::factory()->for($site)->published()->create([
                'slug' => $row['slug'],
                'name' => $row['name'],
                'description' => $row['name'].' description.',
            ]);
            ProductVariant::factory()->for($product)->create(['price_cents' => 2500]);
            self::seedReviews($site, $product, $row['published'], $row['pending']);
            $created[] = $product;
        }

        return ['user' => $user, 'site' => $site, 'products' => $created];
    }

    private static function seedReviews(Site $site, Product $product, int $published, int $pending): void
    {
        $ratings = [5, 4, 5, 3, 4, 5, 2, 5, 4, 5, 1, 4];
        for ($i = 0; $i < $published; $i++) {
            ProductReview::factory()->for($site)->for($product)->published()->create([
                'rating' => $ratings[$i % count($ratings)],
                'title' => 'Review '.$i,
                'body' => 'Published review '.$i.' of '.$product->name.'.',
                'author_name' => 'Shopper '.$i,
                'created_at' => now()->subMinutes($published - $i),
            ]);
        }
        for ($i = 0; $i < $pending; $i++) {
            ProductReview::factory()->for($site)->for($product)->create([
                'rating' => 5,
                'title' => 'Pending '.$i,
                'body' => 'Awaiting approval.',
                'author_name' => 'Pending '.$i,
                'status' => ProductReviewStatus::Pending,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function cardProduct(Product $product, array $overrides = []): array
    {
        return array_replace_recursive([
            'slug' => $product->slug,
            'in_stock_any' => true,
            'image_urls' => null,
            'variants' => [['id' => 1]],
            'product_card' => [
                'slug' => $product->slug,
                'name' => $product->name,
                'price_display' => '£25.00',
            ],
            'rating' => $overrides['rating'] ?? null,
        ], $overrides);
    }
}
