<?php

use App\Enums\Shop\OrderStatus;
use App\Models\Shop\Category;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, site: Site}
 */
function shopAdminUiSite(): array
{
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    test()->actingAs($user);

    return compact('user', 'site');
}

function shopAdminUiHtml(string $name, array $params = []): string
{
    return Livewire::test($name, $params)->html();
}

/**
 * @return list<string>
 */
function shopAdminUiTableHeaders(string $html): array
{
    preg_match_all('/<th\b[^>]*>(.*?)<\/th>/is', $html, $matches);

    return array_values(array_filter(array_map(
        fn (string $cell): string => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($cell), ENT_QUOTES | ENT_HTML5))),
        $matches[1],
    )));
}

function shopAdminUiConfirm(string $html, string $method): string
{
    preg_match_all('/<button\b[^>]*>/i', $html, $buttons);
    expect($buttons[0])->not->toBeEmpty("no buttons rendered while looking for {$method}");

    foreach ($buttons[0] as $tag) {
        if (! str_contains($tag, 'wire:click="'.$method.'"') && ! str_contains($tag, "wire:click='{$method}'")) {
            continue;
        }

        preg_match('/wire:confirm="([^"]*)"/', $tag, $confirm);
        if ($confirm === []) {
            preg_match("/wire:confirm='([^']*)'/", $tag, $confirm);
        }

        return $confirm[1] ?? '';
    }

    expect(false)->toBeTrue("no button with wire:click=\"{$method}\" in rendered HTML");

    return '';
}

test('the orders list is a table with headers and a status badge, not a raw ul of enum values', function () {
    ['site' => $site] = shopAdminUiSite();
    Order::create([
        'site_id' => $site->id,
        'number' => 'P-4242',
        'email' => 'ava@example.com',
        'name' => 'Ava O\'Neil',
        'status' => OrderStatus::Paid->value,
        'refund_status' => 'none',
        'subtotal_cents' => 1540,
        'shipping_cents' => 0,
        'tax_cents' => 0,
        'shipping_tax_cents' => 0,
        'total_cents' => 1540,
        'tax_country_code' => 'GB',
        'shipping_address_json' => [],
        'shipping_method_label' => 'Std',
        'placed_at' => now(),
    ]);

    $html = shopAdminUiHtml('shop.orders-list', ['siteId' => $site->id]);
    $headers = shopAdminUiTableHeaders($html);

    expect($headers)->toContain('Order')
        ->and($headers)->toContain('Customer')
        ->and($headers)->toContain('Total')
        ->and($headers)->toContain('Status');

    expect($html)->toContain('P-4242')
        ->and($html)->toContain('data-flux-badge')
        ->and($html)->toContain('Paid')
        ->and($html)->toContain('wire:loading')
        ->and($html)->not->toMatch('/\son(?:click|change|submit)\s*=/');
});

test('the orders list empty state is copy, not an empty table body', function () {
    ['site' => $site] = shopAdminUiSite();

    $html = shopAdminUiHtml('shop.orders-list', ['siteId' => $site->id]);

    expect($html)->toMatch('/no orders/i')
        ->and($html)->not->toContain('P-4242');
});

test('ship, cancel and both refund actions require confirmation', function () {
    ['site' => $site] = shopAdminUiSite();
    $order = Order::create([
        'site_id' => $site->id,
        'number' => 'P-4242',
        'email' => 'ava@example.com',
        'name' => 'Ava',
        'status' => OrderStatus::Paid->value,
        'refund_status' => 'none',
        'subtotal_cents' => 5000,
        'shipping_cents' => 0,
        'tax_cents' => 0,
        'shipping_tax_cents' => 0,
        'total_cents' => 5000,
        'tax_country_code' => 'GB',
        'shipping_address_json' => ['line1' => '14 Rose Lane', 'city' => 'Lancaster', 'postcode' => 'LA1 1AA'],
        'shipping_method_label' => 'Std',
        'placed_at' => now(),
        'stripe_payment_intent_id' => 'pi_test',
    ]);

    $html = shopAdminUiHtml('shop.order-detail', ['siteId' => $site->id, 'orderId' => $order->id]);

    expect(shopAdminUiConfirm($html, 'markShipped'))->not->toBeEmpty()
        ->and(shopAdminUiConfirm($html, 'cancelOrder'))->not->toBeEmpty()
        ->and(shopAdminUiConfirm($html, 'refundFull'))->not->toBeEmpty()
        ->and(shopAdminUiConfirm($html, 'refundPartial'))->not->toBeEmpty();

    expect($html)->toContain('14 Rose Lane')
        ->and($html)->toContain('data-flux-badge')
        ->and($html)->toContain('wire:loading')
        ->and($html)->not->toMatch('/\son(?:click|change|submit)\s*=/');
});

test('the products list is a table with headers, a status badge, and an empty state', function () {
    ['site' => $site] = shopAdminUiSite();

    $empty = shopAdminUiHtml('shop.products-list', ['siteId' => $site->id]);
    expect($empty)->toMatch('/no products/i')
        ->and($empty)->toContain('wire:loading');

    Product::factory()->for($site)->create(['name' => 'Scarlet Rose']);

    $html = shopAdminUiHtml('shop.products-list', ['siteId' => $site->id]);
    $headers = shopAdminUiTableHeaders($html);

    expect($headers)->toContain('Product')
        ->and($headers)->toContain('Status');
    expect($html)->toContain('Scarlet Rose')
        ->and($html)->toContain('data-flux-badge')
        ->and($html)->not->toMatch('/\son(?:click|change|submit)\s*=/');
});

test('the product editor shows the variant validation message in the rendered HTML', function () {
    ['site' => $site] = shopAdminUiSite();
    $product = Product::factory()->for($site)->create(['name' => 'Scarlet Rose']);

    $component = Livewire::test('shop.product-editor', [
        'siteId' => $site->id,
        'productId' => $product->id,
    ])
        ->set('newVariantSku', '')
        ->call('addVariant');

    expect($component->html())->toContain('The new variant sku field is required.')
        ->and($component->html())->toContain('wire:loading')
        ->and($component->html())->not->toMatch('/\son(?:click|change|submit)\s*=/');
});

test('the category manager has a table, an empty state, and visible validation', function () {
    ['site' => $site] = shopAdminUiSite();

    $empty = shopAdminUiHtml('shop.category-manager', ['siteId' => $site->id]);
    expect($empty)->toMatch('/no categor/i');

    $component = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->set('newName', '')
        ->call('addCategory');

    expect($component->html())->toContain('The new name field is required.')
        ->and($component->html())->toContain('wire:loading')
        ->and($component->html())->not->toMatch('/\son(?:click|change|submit)\s*=/');

    Category::factory()->for($site)->create(['name' => 'Bouquets']);
    $filled = shopAdminUiHtml('shop.category-manager', ['siteId' => $site->id]);

    expect($filled)->toContain('Bouquets')
        ->and($filled)->toContain('data-depth="1"')
        ->and($filled)->not->toContain('placeholder="Meta title"');
});

test('the ai seed panel shows validation in the rendered HTML', function () {
    $user = User::factory()->admin()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    test()->actingAs($user);

    $component = Livewire::test('shop.ai-seed-panel', ['siteId' => $site->id])
        ->set('categoryId', null)
        ->set('businessType', '')
        ->call('seed');

    $html = $component->html();
    expect($html)->toContain('The category id field is required.')
        ->and($html)->toContain('The business type field is required.')
        ->and($html)->toContain('wire:loading')
        ->and($html)->not->toMatch('/\son(?:click|change|submit)\s*=/');
});

test('the shop hero picker has an empty state, loading on generate, and no native event handlers', function () {
    ['site' => $site] = shopAdminUiSite();

    $html = shopAdminUiHtml('shop.shop-hero-picker', ['siteId' => $site->id]);

    expect($html)->toMatch('/no hero/i')
        ->and($html)->toContain('wire:loading')
        ->and($html)->not->toMatch('/\son(?:click|change|submit|error|mouseover|mouseout)\s*=/');
});

test('the shop hero picker shows only the shop-level hero and no per-category section', function () {
    ['site' => $site] = shopAdminUiSite();
    Category::factory()->for($site)->create(['name' => 'Bouquets']);

    $html = shopAdminUiHtml('shop.shop-hero-picker', ['siteId' => $site->id]);

    expect($html)->toContain('Shop Index Hero')
        ->and($html)->toContain('setShopHeroHeight')
        ->and($html)->toContain('setShopHeroEnabled')
        ->and($html)->toContain('setShopHeroWidth')
        ->and($html)->toContain('setShopHeroHeadline')
        ->and($html)->toContain('setShopHeroTextStyle')
        ->and($html)->toContain('generateShopHero')
        ->and($html)->toContain('Category hero (shared)')
        ->and($html)->toContain('setSharedCategoryHeroHeight')
        ->and($html)->toContain('setSharedCategoryHeroWidth')
        ->and($html)->toContain('setSharedCategoryHeroTextStyle')
        ->and($html)->toContain('resetSharedCategoryHeroTextStyle')
        ->and($html)->toContain('generateSharedCategoryHero')
        ->and($html)->not->toContain('setSharedCategoryHeroHeadline')
        ->and($html)->not->toContain('setSharedCategoryHeroAccentWord')
        ->and($html)->not->toContain('Category Heroes')
        ->and($html)->not->toContain('setCategoryHeroHeight')
        ->and($html)->not->toContain('setCategoryHeroEnabled')
        ->and($html)->not->toContain('setCategoryHeroWidth')
        ->and($html)->not->toContain('setCategoryTextZone')
        ->and($html)->not->toContain('generateCategoryHero')
        ->and($html)->not->toContain('setCategoryBgPositionY');
});

test('staff shop admin views do not use native HTML event-handler attributes', function () {
    $files = [
        resource_path('views/livewire/shop/orders-list.blade.php'),
        resource_path('views/livewire/shop/order-detail.blade.php'),
        resource_path('views/livewire/shop/products-list.blade.php'),
        resource_path('views/livewire/shop/product-editor.blade.php'),
        resource_path('views/livewire/shop/category-manager.blade.php'),
        resource_path('views/livewire/shop/ai-seed-panel.blade.php'),
        resource_path('views/livewire/shop/shop-hero-picker.blade.php'),
        resource_path('views/livewire/shop/shipping-rate-editor.blade.php'),
        resource_path('views/livewire/shop/fulfilment-editor.blade.php'),
    ];

    foreach ($files as $file) {
        expect($file)->toBeFile();
        expect((string) file_get_contents($file))->not->toMatch('/\son(?:click|change|submit|error|mouseover|mouseout)\s*=/');
    }
});
