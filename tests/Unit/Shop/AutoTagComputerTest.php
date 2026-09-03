<?php

use App\Enums\Shop\OrderStatus;
use App\Enums\Shop\ProductStatus;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Models\SiteEnquiry;
use App\Services\Shop\AutoTagComputer;
use App\Services\Shop\StockService;
use App\Support\Shop\AutoTagConfig;

function autoTagProduct(Site $site, string $slug, array $attrs = []): Product
{
    $onHand = $attrs['on_hand'] ?? 10;
    unset($attrs['on_hand']);
    $product = Product::factory()->for($site)->create(array_merge([
        'slug' => $slug,
        'name' => ucfirst($slug),
        'status' => ProductStatus::Published,
    ], $attrs));
    $variant = ProductVariant::factory()->for($product)->create(['sku' => strtoupper($slug)]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => $onHand]);

    return $product->fresh(['variants']);
}

function paidOrderFor(Site $site, Product $product, int $qty, $placedAt = null): void
{
    $order = Order::create([
        'site_id' => $site->id,
        'number' => 'P-'.strtoupper($product->slug).'-'.$qty.'-'.uniqid(),
        'email' => 'buyer@example.com',
        'name' => 'Buyer',
        'status' => OrderStatus::Paid->value,
        'refund_status' => 'none',
        'subtotal_cents' => 1000 * $qty,
        'shipping_cents' => 0,
        'tax_cents' => 0,
        'shipping_tax_cents' => 0,
        'total_cents' => 1000 * $qty,
        'tax_country_code' => 'GB',
        'shipping_address_json' => [],
        'shipping_method_label' => 'Std',
        'placed_at' => $placedAt ?? now(),
    ]);
    \DB::table('shop_order_items')->insert([
        'order_id' => $order->id,
        'variant_id' => $product->variants->first()->id,
        'product_id' => $product->id,
        'product_name_snapshot' => $product->name,
        'sku_snapshot' => $product->variants->first()->sku,
        'qty' => $qty,
        'unit_price_cents' => 1000,
        'tax_class_code' => 'standard',
        'tax_rate_percent' => 20,
        'tax_amount_cents' => 167,
        'line_total_cents' => 1000 * $qty,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('computes no auto tags when every rule is disabled', function () {
    $site = Site::factory()->create(['shop_mode' => 'cart']);
    $product = autoTagProduct($site, 'plain');
    $product->update(['price_from' => true, 'published_at' => now()]);

    $tags = app(AutoTagComputer::class)->forSite($site, collect([$product]));

    expect($tags[$product->id] ?? [])->toBe([]);
});

it('tags the top N products by paid order qty inside the window as best-seller', function () {
    $site = Site::factory()->create(['shop_mode' => 'cart']);
    $site->forceFill([
        'auto_tags' => AutoTagConfig::parse([
            'best-seller' => ['enabled' => true, 'params' => ['n' => 2, 'days' => 30]],
        ]),
    ])->save();

    $alpha = autoTagProduct($site, 'alpha');
    $bravo = autoTagProduct($site, 'bravo');
    $charlie = autoTagProduct($site, 'charlie');
    paidOrderFor($site, $alpha, 5);
    paidOrderFor($site, $bravo, 3);
    paidOrderFor($site, $charlie, 1);
    paidOrderFor($site, $charlie, 10, now()->subDays(40));

    $tags = app(AutoTagComputer::class)->forSite($site->fresh(), collect([$alpha, $bravo, $charlie]));

    expect($tags[$alpha->id])->toBe(['best-seller'])
        ->and($tags[$bravo->id])->toBe(['best-seller'])
        ->and($tags[$charlie->id] ?? [])->toBe([]);
});

it('picks the lowest product id as best-seller when quantities tie, across three rebuilds', function () {
    $site = Site::factory()->create(['shop_mode' => 'quote']);
    $site->forceFill([
        'auto_tags' => AutoTagConfig::parse([
            'best-seller' => ['enabled' => true, 'params' => ['n' => 1, 'days' => 30]],
        ]),
    ])->save();

    $early = autoTagProduct($site, 'early');
    $late = autoTagProduct($site, 'late');
    expect($early->id)->toBeLessThan($late->id);

    // Insert the later product first so an unordered GROUP BY / stable arsort would
    // prefer the higher id. Tie-break is lowest product id, not insertion order.
    SiteEnquiry::create([
        'site_id' => $site->id,
        'name' => 'Pat',
        'email' => 'pat@example.com',
        'payload' => [
            'kind' => 'quote',
            'lines' => [
                ['product_id' => $late->id, 'qty' => 5],
            ],
        ],
    ]);
    SiteEnquiry::create([
        'site_id' => $site->id,
        'name' => 'Sam',
        'email' => 'sam@example.com',
        'payload' => [
            'kind' => 'quote',
            'lines' => [
                ['product_id' => $early->id, 'qty' => 5],
            ],
        ],
    ]);

    $computer = app(AutoTagComputer::class);
    $products = collect([$late, $early]);
    $winners = [];
    for ($i = 0; $i < 3; $i++) {
        $tags = $computer->forSite($site->fresh(), $products);
        $winners[] = collect($tags)
            ->filter(fn (array $slugs): bool => in_array('best-seller', $slugs, true))
            ->keys()
            ->all();
    }

    expect($winners[0])->toBe([$early->id])
        ->and($winners[1])->toBe($winners[0])
        ->and($winners[2])->toBe($winners[0])
        ->and($tags[$late->id] ?? [])->toBe([]);
});

it('counts quote-enquiry line qty toward best-seller', function () {
    $site = Site::factory()->create(['shop_mode' => 'quote']);
    $site->forceFill([
        'auto_tags' => AutoTagConfig::parse([
            'best-seller' => ['enabled' => true, 'params' => ['n' => 1, 'days' => 30]],
        ]),
    ])->save();

    $alpha = autoTagProduct($site, 'alpha');
    $bravo = autoTagProduct($site, 'bravo');
    paidOrderFor($site, $alpha, 2);
    SiteEnquiry::create([
        'site_id' => $site->id,
        'name' => 'Pat',
        'email' => 'pat@example.com',
        'payload' => [
            'kind' => 'quote',
            'lines' => [
                ['product_id' => $bravo->id, 'qty' => 9],
            ],
        ],
    ]);

    $tags = app(AutoTagComputer::class)->forSite($site->fresh(), collect([$alpha, $bravo]));

    expect($tags[$bravo->id])->toBe(['best-seller'])
        ->and($tags[$alpha->id] ?? [])->toBe([]);
});

it('tags published products whose published_at is within D days as new', function () {
    $site = Site::factory()->create();
    $site->forceFill([
        'auto_tags' => AutoTagConfig::parse([
            'new' => ['enabled' => true, 'params' => ['days' => 14]],
        ]),
    ])->save();

    $fresh = autoTagProduct($site, 'fresh', ['published_at' => now()->subDays(3)]);
    $old = autoTagProduct($site, 'old', ['published_at' => now()->subDays(20)]);
    $never = autoTagProduct($site, 'never', ['published_at' => null]);

    $tags = app(AutoTagComputer::class)->forSite($site->fresh(), collect([$fresh, $old, $never]));

    expect($tags[$fresh->id])->toBe(['new'])
        ->and($tags[$old->id] ?? [])->toBe([])
        ->and($tags[$never->id] ?? [])->toBe([]);
});

it('tags low-stock in cart mode when summed on_hand is at or below the threshold', function () {
    $site = Site::factory()->create(['shop_mode' => 'cart']);
    $site->forceFill([
        'auto_tags' => AutoTagConfig::parse([
            'low-stock' => ['enabled' => true, 'params' => ['threshold' => 5]],
        ]),
    ])->save();

    $low = autoTagProduct($site, 'low', ['on_hand' => 5]);
    $ok = autoTagProduct($site, 'ok', ['on_hand' => 6]);

    $tags = app(AutoTagComputer::class)->forSite($site->fresh(), collect([$low, $ok]));

    expect($tags[$low->id])->toBe(['low-stock'])
        ->and($tags[$ok->id] ?? [])->toBe([]);
});

it('tags low-stock from on_hand minus active reservations', function () {
    $site = Site::factory()->create(['shop_mode' => 'cart']);
    $site->forceFill([
        'auto_tags' => AutoTagConfig::parse([
            'low-stock' => ['enabled' => true, 'params' => ['threshold' => 5]],
        ]),
    ])->save();

    $held = autoTagProduct($site, 'held', ['on_hand' => 10]);
    $ok = autoTagProduct($site, 'ok', ['on_hand' => 10]);
    app(StockService::class)->reserve($held->variants->first()->id, 6, cartId: 1);

    $tags = app(AutoTagComputer::class)->forSite($site->fresh(), collect([$held, $ok]));

    expect($tags[$held->id])->toBe(['low-stock'])
        ->and($tags[$ok->id] ?? [])->toBe([]);
});

it('never applies low-stock in quote or enquire mode', function (string $mode) {
    $site = Site::factory()->create(['shop_mode' => $mode]);
    $site->forceFill([
        'auto_tags' => AutoTagConfig::parse([
            'low-stock' => ['enabled' => true, 'params' => ['threshold' => 50]],
        ]),
    ])->save();

    $product = autoTagProduct($site, 'scarce', ['on_hand' => 1]);
    $tags = app(AutoTagComputer::class)->forSite($site->fresh(), collect([$product]));

    expect($tags[$product->id] ?? [])->toBe([]);
})->with(['quote', 'enquire']);

it('tags made-to-order from price_from without a second flag', function () {
    $site = Site::factory()->create();
    $site->forceFill([
        'auto_tags' => AutoTagConfig::parse([
            'made-to-order' => ['enabled' => true],
        ]),
    ])->save();

    $mto = autoTagProduct($site, 'mto', ['price_from' => true]);
    $fixed = autoTagProduct($site, 'fixed', ['price_from' => false]);

    $tags = app(AutoTagComputer::class)->forSite($site->fresh(), collect([$mto, $fixed]));

    expect($tags[$mto->id])->toBe(['made-to-order'])
        ->and($tags[$fixed->id] ?? [])->toBe([]);
});
