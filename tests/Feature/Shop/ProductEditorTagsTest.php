<?php

use App\Enums\Shop\ProductStatus;
use App\Models\Client;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;

function productEditorTagsSite(array $siteAttrs = []): array
{
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(array_merge([
        'created_by_user_id' => $user->id,
        'shop_enabled' => true,
        'product_tags' => [
            ['slug' => 'same-day', 'label' => 'Same day', 'show_as_badge' => true, 'tone' => 'accent'],
            ['slug' => 'seasonal', 'label' => 'Seasonal', 'show_as_badge' => true, 'tone' => 'warning'],
            ['slug' => 'gift', 'label' => 'Gift', 'show_as_badge' => false, 'tone' => 'neutral'],
        ],
    ], $siteAttrs));
    $product = Product::factory()->for($site)->create(['name' => 'Item']);
    test()->actingAs($user);

    return compact('user', 'site', 'product');
}

it('saves selected vocabulary tags through the revision-locked editor save', function () {
    ['site' => $site, 'product' => $product] = productEditorTagsSite();

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->call('toggleTag', 'seasonal')
        ->call('toggleTag', 'gift')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->fresh()->tags)->toBe(['seasonal', 'gift'])
        ->and((int) $product->fresh()->revision)->toBe(1);
});

it('refuses a sixth tag and a stale revision', function () {
    ['site' => $site, 'product' => $product] = productEditorTagsSite();
    $site->update(['product_tags' => [
        ['slug' => 'a', 'label' => 'A', 'show_as_badge' => false, 'tone' => 'neutral'],
        ['slug' => 'b', 'label' => 'B', 'show_as_badge' => false, 'tone' => 'neutral'],
        ['slug' => 'c', 'label' => 'C', 'show_as_badge' => false, 'tone' => 'neutral'],
        ['slug' => 'd', 'label' => 'D', 'show_as_badge' => false, 'tone' => 'neutral'],
        ['slug' => 'e', 'label' => 'E', 'show_as_badge' => false, 'tone' => 'neutral'],
        ['slug' => 'f', 'label' => 'F', 'show_as_badge' => false, 'tone' => 'neutral'],
    ]]);

    $component = Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id]);
    foreach (['a', 'b', 'c', 'd', 'e'] as $slug) {
        $component->call('toggleTag', $slug);
    }
    $component->call('toggleTag', 'f')->assertHasErrors('selectedTags');

    $product->update(['revision' => 4]);
    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('revision', 0)
        ->call('toggleTag', 'a')
        ->call('save')
        ->assertHasErrors('revision');
});

it('lets a client of the site save tags', function () {
    $tenant = Client::factory()->create();
    $client = User::factory()->create(['client_id' => $tenant->id, 'role' => null]);
    $site = Site::factory()->create([
        'client_id' => $tenant->id,
        'shop_enabled' => true,
        'product_tags' => [
            ['slug' => 'seasonal', 'label' => 'Seasonal', 'show_as_badge' => true, 'tone' => 'warning'],
        ],
    ]);
    $product = Product::factory()->for($site)->create();

    Livewire::actingAs($client)
        ->test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->call('toggleTag', 'seasonal')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->fresh()->tags)->toBe(['seasonal']);
});

it('surfaces a foreign category as a category field error rather than a tags error', function () {
    ['site' => $site, 'product' => $product] = productEditorTagsSite();
    $foreign = Category::factory()->create();

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('primaryCategoryId', $foreign->id)
        ->call('save')
        ->assertHasErrors('primaryCategoryId')
        ->assertHasNoErrors('selectedTags');
});

it('saves name and price when a product still carries a slug removed from the vocabulary', function () {
    ['site' => $site, 'product' => $product] = productEditorTagsSite();
    $product->update(['tags' => ['gift', 'seasonal'], 'name' => 'Item', 'price_from' => false]);
    $site->update(['product_tags' => [
        ['slug' => 'seasonal', 'label' => 'Seasonal', 'show_as_badge' => true, 'tone' => 'warning'],
    ]]);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->assertSet('selectedTags', ['seasonal'])
        ->set('name', 'Renamed item')
        ->set('priceFrom', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($product->fresh()->name)->toBe('Renamed item')
        ->and($product->fresh()->price_from)->toBeTrue()
        ->and($product->fresh()->tags)->toBe(['seasonal']);
});

it('stamps published_at the first time a product is published', function () {
    ['site' => $site, 'product' => $product] = productEditorTagsSite();
    expect($product->published_at)->toBeNull();

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('status', ProductStatus::Published->value)
        ->call('save');

    expect($product->fresh()->published_at)->not->toBeNull();
});
