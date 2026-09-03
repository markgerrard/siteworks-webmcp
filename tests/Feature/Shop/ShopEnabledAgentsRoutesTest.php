<?php

use App\Enums\AgentRole;
use App\Enums\Shop\OrderStatus;
use App\Enums\Shop\ProductStatus;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Shop\Category;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
    $this->withoutVite();
});

/**
 * @return array{0: Site, 1: User, 2: Product, 3: Order}
 */
function shopFlagAgentsCommerce(bool $shopEnabled): array
{
    $actor = User::factory()->staff(AgentRole::Admin)->create();
    $site = Site::factory()
        ->{$shopEnabled ? 'shopEnabled' : 'shopDisabled'}()
        ->create([
            'created_by_user_id' => $actor->id,
            'preview_domain' => 'flag-agents-'.uniqid(),
            'preview_brand' => 'a',
        ]);
    GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    Preview::factory()->for($site)->create(['slug' => 'flag-agents-'.uniqid()]);
    Category::factory()->for($site)->create(['slug' => 'flag-cat', 'name' => 'Flag Cat']);
    CommerceReads::giveShop($site);
    $product = Product::factory()->for($site)->create(['status' => ProductStatus::Draft]);
    $order = Order::create([
        'site_id' => $site->id,
        'number' => 'FLG-AG-1',
        'email' => 'buyer@example.com',
        'name' => 'Buyer',
        'status' => OrderStatus::Paid->value,
        'refund_status' => 'none',
        'subtotal_cents' => 900,
        'shipping_cents' => 0,
        'tax_cents' => 0,
        'shipping_tax_cents' => 0,
        'total_cents' => 900,
        'tax_country_code' => 'GB',
        'shipping_address_json' => [],
        'shipping_method_label' => 'Std',
        'placed_at' => now(),
    ]);

    return [$site, $actor, $product, $order];
}

test('direct agents commerce routes 404 when the flag is off and 200 when on', function () {
    [$offSite, $offAdmin, $offProduct, $offOrder] = shopFlagAgentsCommerce(shopEnabled: false);
    [$onSite, $onAdmin, $onProduct, $onOrder] = shopFlagAgentsCommerce(shopEnabled: true);

    $offPaths = [
        route('shop.admin.products.edit', ['site' => $offSite, 'product' => $offProduct->id]),
        route('site.editor.list-products', $offSite),
        route('site.editor.get-product', $offSite),
        route('sites.section', ['site' => $offSite, 'section' => 'ops']),
    ];
    foreach ($offPaths as $path) {
        $this->actingAs($offAdmin)->get($path)->assertNotFound();
    }
    $this->actingAs($offAdmin)
        ->post(route('site.editor.draft-product', $offSite), [])
        ->assertNotFound();
    $this->actingAs($offAdmin)
        ->post(route('site.editor.update-draft-product', $offSite), [])
        ->assertNotFound();
    $this->actingAs($offAdmin)
        ->post(route('site.editor.set-product-image', $offSite), [])
        ->assertNotFound();

    // Fulfilment is owed after payment: an off site that has taken an order keeps its order detail
    // and Orders section; an off site with no orders has neither.
    $this->actingAs($offAdmin)
        ->get(route('shop.admin.orders.show', ['site' => $offSite, 'order' => $offOrder->id]))
        ->assertOk();
    $this->actingAs($offAdmin)
        ->get(route('sites.shop.orders', $offSite))
        ->assertOk();
    $offOrder->delete();
    $this->actingAs($offAdmin)
        ->get(route('sites.shop.orders', $offSite))
        ->assertNotFound();

    $this->actingAs($onAdmin)
        ->get(route('shop.admin.products.edit', ['site' => $onSite, 'product' => $onProduct->id]))
        ->assertOk();
    $this->actingAs($onAdmin)
        ->get(route('shop.admin.orders.show', ['site' => $onSite, 'order' => $onOrder->id]))
        ->assertOk();
    $this->actingAs($onAdmin)
        ->get(route('site.editor.list-products', $onSite))
        ->assertOk();
    $this->actingAs($onAdmin)
        ->get(route('sites.section', ['site' => $onSite, 'section' => 'ops']))
        ->assertOk();
});
