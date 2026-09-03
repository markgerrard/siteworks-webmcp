<?php

use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\Product;
use App\Models\User;
use App\Services\Shop\ShopDraftWriter;
use App\Services\Shop\SnapshotBuilder;
use Database\Seeders\Shop\TaxClassSeeder;
use Livewire\Livewire;
use Tests\Support\CommerceReads;
use Tests\Support\LinePersonalisationFixtures;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('product editor round-trips customer inputs and use site defaults', function () {
    $user = User::factory()->staff()->create();
    $site = \App\Models\Site::factory()->create([
        'created_by_user_id' => $user->id,
        'default_customer_inputs' => LinePersonalisationFixtures::generic(),
    ]);
    $this->actingAs($user);
    $product = Product::factory()->for($site)->create(['name' => 'Blank']);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->call('useSiteDefaults')
        ->call('save');

    $product->refresh();
    expect($product->customer_inputs[0]['slug'])->toBe('engraving')
        ->and($product->customer_inputs[1]['kind'])->toBe('choice');
});

test('new products inherit site default customer inputs', function () {
    $site = \App\Models\Site::factory()->create([
        'default_customer_inputs' => LinePersonalisationFixtures::florist(),
    ]);
    $result = app(ShopDraftWriter::class)->createDraft($site, [
        'name' => 'New item',
        'slug' => 'new-item',
        'variants' => [['sku' => 'NEW-1', 'label' => 'Default', 'price_cents' => 1000]],
    ]);

    expect($result['product']->customer_inputs[0]['slug'])->toBe('card-message');
});

test('storefront defaults persist through setKnob', function () {
    $user = User::factory()->staff()->create();
    $site = \App\Models\Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test('shop.storefront-defaults', ['siteId' => $site->id])
        ->call('applyPreset', 'short-text');

    $site->refresh();
    expect($site->default_customer_inputs[0]['slug'])->toBe('note');
});

test('agent draft_product accepts customer_inputs', function () {
    $this->seed(TaxClassSeeder::class);
    CommerceReads::enableFlags();
    [$actor, $site] = CommerceReads::shopSite();

    $result = CommerceReads::run($actor, $site, 'draft_product', CommerceReads::draftProductInput([
        'customer_inputs' => LinePersonalisationFixtures::generic(),
    ]));

    expect($result->ok)->toBeTrue();
    $product = Product::query()->where('site_id', $site->id)->first();
    expect($product->customer_inputs[0]['slug'])->toBe('engraving');
});

test('agent draft_product rejects invalid customer_inputs', function () {
    $this->seed(TaxClassSeeder::class);
    CommerceReads::enableFlags();
    [$actor, $site] = CommerceReads::shopSite();

    $result = CommerceReads::run($actor, $site, 'draft_product', CommerceReads::draftProductInput([
        'customer_inputs' => [
            ['slug' => 'bad slug', 'label' => 'Note', 'kind' => 'text'],
        ],
    ]));

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation');
});

test('products without inputs keep pdp markup free of personalisation fields', function () {
    $site = \App\Models\Site::factory()->create([
        'custom_domain' => 'byte.example',
        'custom_domain_status' => 'active',
    ]);
    $product = Product::factory()->published()->for($site)->create(['slug' => 'plain', 'name' => 'Plain']);
    \App\Models\Shop\ProductVariant::factory()->for($product)->create(['price_cents' => 1000]);
    \App\Models\Shop\VariantStock::create([
        'variant_id' => $product->variants()->first()->id,
        'on_hand' => 5,
    ]);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

    $html = test()->get('http://byte.example/products/plain')->assertOk()->getContent();
    expect($html)->not->toContain('data-customer-input')
        ->and($html)->not->toContain('name="personalisation');
});
