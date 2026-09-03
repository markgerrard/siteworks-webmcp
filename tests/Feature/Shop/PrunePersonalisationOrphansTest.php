<?php

use App\Models\Shop\Cart;
use App\Models\Shop\CartItem;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('orphan cart images older than 14 days are deleted and referenced files are kept', function () {
    Storage::fake(config('filesystems.default'));
    $disk = Storage::disk(config('filesystems.default'));
    $site = Site::factory()->create();
    $product = Product::factory()->for($site)->create();
    $variant = ProductVariant::factory()->for($product)->create();

    $stale = Cart::create([
        'site_id' => $site->id,
        'session_cookie_id' => 'stale',
        'last_active_at' => now()->subDays(20),
    ]);
    $fresh = Cart::create([
        'site_id' => $site->id,
        'session_cookie_id' => 'fresh',
        'last_active_at' => now(),
    ]);

    $stalePath = 'sites/'.$site->id.'/personalisation/cart-'.$stale->id.'/old.jpg';
    $freshPath = 'sites/'.$site->id.'/personalisation/cart-'.$fresh->id.'/new.jpg';
    $staleOrphanPath = 'sites/'.$site->id.'/personalisation/cart-'.$stale->id.'/orphan.jpg';
    $inFlightPath = 'sites/'.$site->id.'/personalisation/cart-'.$fresh->id.'/in-flight.jpg';
    $disk->put($stalePath, 'img');
    $disk->put($freshPath, 'img');
    $disk->put($staleOrphanPath, 'img');
    $disk->put($inFlightPath, 'img');
    touch($disk->path($stalePath), now()->subDays(20)->timestamp);

    CartItem::create([
        'cart_id' => $stale->id,
        'variant_id' => $variant->id,
        'qty' => 1,
        'unit_price_cents' => 100,
        'personalisation' => [
            'photo' => ['label' => 'Photo', 'kind' => 'image', 'value' => [['path' => $stalePath, 'name' => 'old.jpg', 'bytes' => 3, 'mime' => 'image/jpeg']]],
        ],
        'personalisation_hash' => 'abc',
    ]);
    CartItem::create([
        'cart_id' => $fresh->id,
        'variant_id' => $variant->id,
        'qty' => 1,
        'unit_price_cents' => 100,
        'personalisation' => [
            'photo' => ['label' => 'Photo', 'kind' => 'image', 'value' => [['path' => $freshPath, 'name' => 'new.jpg', 'bytes' => 3, 'mime' => 'image/jpeg']]],
        ],
        'personalisation_hash' => 'def',
    ]);

    DB::table('shop_carts')->where('id', $stale->id)->update(['updated_at' => now()->subDays(20)]);

    $this->artisan('shop:prune-personalisation-orphans', ['--days' => 10, '--dry-run' => true])
        ->expectsOutputToContain('Would delete 1 orphaned personalisation image(s)')
        ->assertSuccessful();

    expect($disk->exists($staleOrphanPath))->toBeTrue();

    $this->artisan('shop:prune-personalisation-orphans', ['--days' => 10])->assertSuccessful();

    expect($disk->exists($stalePath))->toBeTrue()
        ->and($disk->exists($freshPath))->toBeTrue()
        ->and($disk->exists($staleOrphanPath))->toBeFalse()
        ->and($disk->exists($inFlightPath))->toBeTrue();
});
