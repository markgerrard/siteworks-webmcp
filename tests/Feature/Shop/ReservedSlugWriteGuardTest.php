<?php

use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Models\User;
use App\Services\Shop\CategoryTreeException;
use App\Services\Shop\CategoryTreeService;
use App\Services\Shop\ShopDraftWriter;
use App\Support\Shop\ShopUrls;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\CommerceReads;

test('creating a category named Cart auto-derives a non-reserved slug', function () {
    $site = Site::factory()->create();

    $category = app(CategoryTreeService::class)->create($site, 'Cart');

    expect($category->slug)->not->toBe('cart')
        ->and(ShopUrls::isReservedSlug($category->slug))->toBeFalse()
        ->and($category->slug)->toBe('cart-2')
        ->and($category->path)->toBe('cart-2');
});

test('the category model auto-derives a non-reserved slug from the name Cart', function () {
    $site = Site::factory()->create();

    $category = Category::query()->create([
        'site_id' => $site->id,
        'name' => 'Cart',
        'sort_order' => 1,
    ]);

    expect($category->slug)->toBe('cart-2')
        ->and($category->path)->toBe('cart-2')
        ->and(ShopUrls::isReservedSlug($category->slug))->toBeFalse();
});

test('an explicit category rename to cart is refused by the tree service', function () {
    $site = Site::factory()->create();
    $category = app(CategoryTreeService::class)->create($site, 'Cakes');

    try {
        app(CategoryTreeService::class)->rename($category, 'Cakes', 'cart');
        expect(false)->toBeTrue('expected CategoryTreeException');
    } catch (CategoryTreeException $e) {
        expect($e->errorCode)->toBe('validation');
    }

    expect($category->fresh()->slug)->toBe('cakes');
});

test('the category manager refuses an explicit rename to cart', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $category = app(CategoryTreeService::class)->create($site, 'Cakes');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('rename', $category->id, 'Cakes', 'cart')
        ->assertHasErrors(['slug']);

    expect($category->fresh()->slug)->toBe('cakes');
});

test('the category manager auto-derives a non-reserved slug when adding Cart', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->set('newName', 'Cart')
        ->call('addCategory')
        ->assertHasNoErrors();

    expect(Category::query()->where('site_id', $site->id)->value('slug'))->toBe('cart-2');
});

test('manage_category auto-derives a non-reserved slug from the name Cart', function () {
    CommerceReads::enableFlags();
    [$actor, $site] = CommerceReads::shopSite();

    $result = CommerceReads::run($actor, $site, 'manage_category', [
        'catalogue_revision' => 0,
        'action' => 'upsert',
        'name' => 'Cart',
    ]);

    expect($result->ok)->toBeTrue()
        ->and($result->data['slug'])->toBe('cart-2')
        ->and(ShopUrls::isReservedSlug($result->data['slug']))->toBeFalse();
});

test('manage_category refuses an explicit reserved slug as a validation outcome', function () {
    CommerceReads::enableFlags();
    [$actor, $site] = CommerceReads::shopSite();

    $result = CommerceReads::run($actor, $site, 'manage_category', [
        'catalogue_revision' => 0,
        'action' => 'upsert',
        'name' => 'Cakes',
        'slug' => 'cart',
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($result->error['fields']['slug'] ?? null)->not->toBeNull()
        ->and(Category::query()->where('site_id', $site->id)->where('slug', 'cart')->exists())->toBeFalse();
});

test('the product editor refuses saving the reserved slug checkout', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $product = Product::factory()->for($site)->create(['slug' => 'kept-slug']);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('slug', 'checkout')
        ->call('save')
        ->assertHasErrors(['slug']);

    expect($product->fresh()->slug)->toBe('kept-slug');
});

test('ShopDraftWriter createDraft refuses a reserved slug', function () {
    $site = Site::factory()->create();

    expect(fn () => app(ShopDraftWriter::class)->createDraft($site, [
        'name' => 'Checkout',
        'slug' => 'checkout',
        'variants' => [
            ['sku' => 'CHK-1', 'price_cents' => 100],
        ],
    ]))->toThrow(ValidationException::class);

    expect(Product::query()->where('site_id', $site->id)->exists())->toBeFalse();
});

test('ShopDraftWriter saveFromEditor refuses a reserved slug', function () {
    $site = Site::factory()->create();
    $product = Product::factory()->for($site)->create(['slug' => 'kept-slug', 'name' => 'Kept']);

    expect(fn () => app(ShopDraftWriter::class)->saveFromEditor($site, $product, [
        'name' => 'Kept',
        'description' => null,
        'tax_class_id' => null,
        'slug' => 'checkout',
    ]))->toThrow(ValidationException::class);

    expect($product->fresh()->slug)->toBe('kept-slug');
});
