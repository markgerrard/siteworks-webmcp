<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\Product;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;

/**
 * @param  array<string, mixed>  $categoryHero
 * @param  array<string, mixed>  $snapshotExtras
 */
function categoryIntroBandHtml(string $host, array $categoryHero = [], array $snapshotExtras = []): string
{
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
    ]);

    Product::factory()->published()->for($site)->create(['slug' => 'conserve', 'name' => 'Strawberry Conserve']);

    $category = array_merge([
        'id' => 1,
        'slug' => 'preserves',
        'name' => 'Preserves',
        'description' => 'Seasonal jars from the pantry.',
        'product_slugs' => ['conserve'],
        'hero_image_url' => '/cat-hero.jpg',
        'hero_mode' => 'custom',
    ], $categoryHero);

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => 1],
            'categories' => [
                'preserves' => $category,
            ],
            'products' => [
                'conserve' => [
                    'id' => 1,
                    'slug' => 'conserve',
                    'status' => 'published',
                    'primary_category_slug' => 'preserves',
                    'price_cents' => 595,
                    'price_display' => '£5.95',
                    'in_stock_any' => true,
                    'variant_in_stock' => [1 => true],
                    'image_urls' => null,
                    'product_card' => ['slug' => 'conserve', 'name' => 'Strawberry Conserve', 'price_display' => '£5.95'],
                    'product_detail' => ['slug' => 'conserve', 'name' => 'Strawberry Conserve', 'description' => 'Jam'],
                    'variants' => [['id' => 1, 'sku' => 'CON', 'label' => 'Jar', 'price_cents' => 595, 'image_urls' => null]],
                    'is_ai_seeded' => false,
                    'is_ai_reviewed' => false,
                ],
            ],
            'featured_slugs' => [],
            ...$snapshotExtras,
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => $snap->id,
        'updated_at' => now(),
    ]);

    $html = test()->get('http://'.$host.'/collections/preserves')->assertOk()->getContent();
    preg_match('/<main\b[^>]*>(.*?)<\/main>/is', $html, $main);
    expect($main)->not->toBeEmpty();

    return $main[1];
}

test('intro_band is absent when the flag is off or omitted', function (?bool $flag) {
    $hero = $flag === null ? [] : ['intro_band' => $flag];
    $main = categoryIntroBandHtml('intro-off-'.($flag === null ? 'omit' : 'false').'.example', $hero);

    expect($main)->not->toContain('data-category-intro-band')
        ->and($main)->toContain("background-image: url('/cat-hero.jpg')");
})->with([null, false]);

test('intro_band replaces the photo hero with a tinted name-and-image band', function () {
    $main = categoryIntroBandHtml('intro-on.example', [
        'intro_band' => true,
        'hero_accent_word' => 'Preserves',
    ]);

    expect($main)->toContain('data-category-intro-band')
        ->and($main)->toContain('background-color: color-mix(in srgb, var(--brand-primary)')
        ->and($main)->toContain('<span class="accent-word" style="color: var(--color-accent);">Preserves</span>')
        ->and($main)->toContain('Seasonal jars from the pantry.')
        ->and($main)->toContain('src="/cat-hero.jpg"')
        ->and($main)->not->toContain("background-image: url('/cat-hero.jpg')")
        ->and($main)->toContain('<nav aria-label="Breadcrumb"')
        ->and(strpos($main, 'data-category-intro-band'))->toBeLessThan(strpos($main, '<nav aria-label="Breadcrumb"'));
});

test('intro_band still renders when the category has no image', function () {
    $main = categoryIntroBandHtml('intro-no-image.example', [
        'intro_band' => true,
        'hero_image_url' => null,
        'hero_mode' => 'none',
    ]);

    expect($main)->toContain('data-category-intro-band')
        ->and($main)->toContain('Preserves')
        ->and($main)->not->toContain('object-contain')
        ->and($main)->not->toContain("background-image: url(");
});
