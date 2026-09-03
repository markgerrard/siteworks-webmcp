<?php

namespace Tests\Support;

use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Models\User;
use App\Support\Shop\ProductFacts;
use App\Enums\Shop\ShopSnapshotStatus;

final class ProductFactsFixtures
{
    /**
     * @return array{user: User, site: Site, products: list<Product>}
     */
    public static function bakery(array $siteAttrs = []): array
    {
        return self::vertical(
            'bakery',
            'facts-bakery.example',
            'Camino Bakery',
            [
                [
                    'slug' => 'victoria-sponge',
                    'name' => 'Victoria Sponge',
                    'description' => 'A classic sponge.',
                    'facts' => [
                        'allergens' => ['text' => 'Eggs, milk, gluten.'],
                        'ingredients' => ['text' => 'Flour, sugar, butter, eggs.'],
                        'nutrition' => ['pairs' => [
                            ['label' => 'Calories', 'value' => '320 kcal'],
                            ['label' => 'Fat', 'value' => '12 g'],
                            ['label' => 'Carbohydrate', 'value' => '45 g'],
                            ['label' => 'Protein', 'value' => '6 g'],
                            ['label' => 'Sugar', 'value' => '22 g'],
                            ['label' => 'Salt', 'value' => '0.4 g'],
                            ['label' => 'Serving size', 'value' => '1 slice'],
                        ]],
                        'serves' => ['pairs' => [['label' => 'Serves', 'value' => '12']]],
                    ],
                ],
                [
                    'slug' => 'lemon-drizzle',
                    'name' => 'Lemon Drizzle',
                    'description' => 'Zesty loaf.',
                    'facts' => [
                        'allergens' => ['text' => 'Gluten, eggs.'],
                        'serves' => ['pairs' => [['label' => 'Serves', 'value' => '8']]],
                    ],
                ],
            ],
            $siteAttrs,
        );
    }

    /**
     * @return array{user: User, site: Site, products: list<Product>}
     */
    public static function florist(array $siteAttrs = []): array
    {
        return self::vertical(
            'florist',
            'facts-florist.example',
            'Bloom & Stem',
            [
                [
                    'slug' => 'hand-tied',
                    'name' => 'Hand-tied bouquet',
                    'description' => 'Seasonal stems.',
                    'facts' => [
                        'whats-included' => ['text' => 'A wrapped bunch of seasonal flowers.'],
                        'care' => ['text' => 'Trim stems and change water daily.'],
                        'delivery-notes' => ['text' => 'Leave in a cool porch if out.'],
                    ],
                ],
                [
                    'slug' => 'posy',
                    'name' => 'Posy',
                    'description' => 'A small bunch.',
                    'facts' => [
                        'care' => ['text' => 'Mist lightly.'],
                    ],
                ],
            ],
            $siteAttrs,
        );
    }

    /**
     * @return array{user: User, site: Site, products: list<Product>}
     */
    public static function empty(array $siteAttrs = []): array
    {
        $user = User::factory()->staff()->create();
        $site = Site::factory()->create(array_merge([
            'created_by_user_id' => $user->id,
            'custom_domain' => 'facts-empty.example',
            'custom_domain_status' => 'active',
            'business_name' => 'Plain Store',
        ], $siteAttrs));
        $product = Product::factory()->for($site)->published()->create([
            'slug' => 'plain-item',
            'name' => 'Plain item',
            'description' => 'Just a description.',
        ]);
        ProductVariant::factory()->for($product)->create(['price_cents' => 1000]);

        return ['user' => $user, 'site' => $site, 'products' => [$product]];
    }

    /**
     * @return array<string, array{0: string}>
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
    public static function make(string $vertical, array $siteAttrs = []): array
    {
        return match ($vertical) {
            'bakery' => self::bakery($siteAttrs),
            'florist' => self::florist($siteAttrs),
            default => self::empty($siteAttrs),
        };
    }

    /**
     * @param  list<array{slug: string, name: string, description: string, facts: array<string, mixed>}>  $products
     * @return array{user: User, site: Site, products: list<Product>}
     */
    private static function vertical(string $preset, string $host, string $name, array $products, array $siteAttrs): array
    {
        $user = User::factory()->staff()->create();
        $site = Site::factory()->create(array_merge([
            'created_by_user_id' => $user->id,
            'custom_domain' => $host,
            'custom_domain_status' => 'active',
            'business_name' => $name,
            'product_fact_groups' => ProductFacts::presetGroups($preset),
        ], $siteAttrs));

        $created = [];
        foreach ($products as $row) {
            $product = Product::factory()->for($site)->published()->create([
                'slug' => $row['slug'],
                'name' => $row['name'],
                'description' => $row['description'],
                'facts' => $row['facts'],
            ]);
            ProductVariant::factory()->for($product)->create(['price_cents' => 2500]);
            $created[] = $product;
        }

        return ['user' => $user, 'site' => $site, 'products' => $created];
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public static function pdpSnapshot(Site $site, Product $product, array $facts): void
    {
        self::snapshotProduct($site, $product, [
            'id' => $product->id,
            'slug' => $product->slug,
            'status' => 'published',
            'primary_category_slug' => null,
            'price_cents' => 2500,
            'price_display' => '£25.00',
            'in_stock_any' => true,
            'variant_in_stock' => [1 => true],
            'image_urls' => null,
            'product_card' => ['slug' => $product->slug, 'name' => $product->name, 'price_display' => '£25.00'],
            'product_detail' => [
                'slug' => $product->slug,
                'name' => $product->name,
                'description' => $product->description,
                'facts' => $facts,
            ],
            'variants' => [['id' => 1, 'sku' => 'SKU-1', 'label' => 'Std', 'price_cents' => 2500, 'image_urls' => null]],
            'is_ai_seeded' => false,
            'is_ai_reviewed' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $product
     */
    public static function snapshotProduct(Site $site, Product $product, array $productPayload): void
    {
        $existing = ShopSnapshotCurrent::query()->where('site_id', $site->id)->first();
        $json = [
            'meta' => ['site_id' => $site->id, 'product_count' => 1, 'currency' => 'GBP'],
            'categories' => [],
            'products' => [$product->slug => $productPayload],
            'featured_slugs' => [],
        ];
        if ($existing !== null) {
            ShopSnapshot::query()->whereKey($existing->snapshot_id)->update(['json' => $json]);
            app(\App\Services\Shop\SnapshotReader::class)->invalidate($site->id);

            return;
        }

        $snap = ShopSnapshot::query()->create([
            'site_id' => $site->id,
            'version' => 1,
            'status' => ShopSnapshotStatus::Success,
            'json' => $json,
            'built_at' => now(),
        ]);
        ShopSnapshotCurrent::query()->create([
            'site_id' => $site->id,
            'snapshot_id' => $snap->id,
            'updated_at' => now(),
        ]);
        app(\App\Services\Shop\SnapshotReader::class)->invalidate($site->id);
    }
}
