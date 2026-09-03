<?php

use App\Models\Site;
use App\Models\User;
use App\Services\Site\PublicPageCache;
use Livewire\Livewire;

test('storefront listing knobs persist page size and default sort', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cache = app(PublicPageCache::class);
    $generation = $cache->generation($site);

    Livewire::test('shop.listing-knobs', ['siteId' => $site->id])
        ->set('pageSize', '24')
        ->set('defaultSort', 'newest')
        ->call('save');

    $site->refresh();
    expect($site->shop_page_size)->toBe(24)
        ->and($site->shop_default_sort)->toBe('newest')
        ->and($cache->generation($site))->toBe($generation + 2);
});

test('storefront listing knobs reject an unknown page size', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cache = app(PublicPageCache::class);
    $generation = $cache->generation($site);

    Livewire::test('shop.listing-knobs', ['siteId' => $site->id])
        ->set('pageSize', '13')
        ->call('save')
        ->assertHasErrors(['pageSize']);

    expect($site->fresh()->shop_page_size)->toBeNull()
        ->and($cache->generation($site))->toBe($generation);
});

test('clearing page size restores the unpaged default', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $user->id,
        'shop_page_size' => 12,
        'shop_default_sort' => 'newest',
    ]);
    $this->actingAs($user);
    $cache = app(PublicPageCache::class);
    $generation = $cache->generation($site);

    Livewire::test('shop.listing-knobs', ['siteId' => $site->id])
        ->set('pageSize', '')
        ->set('defaultSort', 'featured')
        ->call('save');

    $site->refresh();
    expect($site->shop_page_size)->toBeNull()
        ->and($site->shop_default_sort)->toBeNull()
        ->and($cache->generation($site))->toBe($generation + 2);
});
