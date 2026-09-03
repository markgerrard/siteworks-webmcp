<?php

use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use App\Services\Site\PublicPageCache;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

function shopIndexBlocksPanelSite(?User $user = null, array $siteAttrs = []): array
{
    $user ??= User::factory()->staff()->create();
    $site = Site::factory()->create(array_merge([
        'created_by_user_id' => $user->id,
        'shop_enabled' => true,
    ], $siteAttrs));
    test()->actingAs($user);

    return compact('user', 'site');
}

it('adds a shop index block, busts the public page cache, and persists source limit layout heading', function () {
    ['site' => $site] = shopIndexBlocksPanelSite();
    Cache::flush();
    config(['site.public_cache_enabled' => true]);
    $before = app(PublicPageCache::class)->generation($site);

    Livewire::test('shop.shop-index-blocks-settings', ['siteId' => $site->id])
        ->set('newHeading', 'Gift picks')
        ->set('newSource', 'tag:gift')
        ->set('newLimit', 8)
        ->set('newLayout', 'carousel')
        ->call('addBlock')
        ->assertHasNoErrors();

    $blocks = $site->fresh()->shop_index_blocks;
    expect($blocks)->toHaveCount(1)
        ->and($blocks[0])->toMatchArray([
            'source' => 'tag:gift',
            'limit' => 8,
            'layout' => 'carousel',
            'heading' => 'Gift picks',
        ])
        ->and(app(PublicPageCache::class)->generation($site))->toBeGreaterThan($before);
});

it('removes and reorders shop index blocks', function () {
    ['site' => $site] = shopIndexBlocksPanelSite();
    $site->update(['shop_index_blocks' => [
        ['source' => 'newest', 'limit' => 4, 'layout' => 'grid', 'heading' => 'First'],
        ['source' => 'featured', 'limit' => 4, 'layout' => 'grid', 'heading' => 'Second'],
    ]]);

    Livewire::test('shop.shop-index-blocks-settings', ['siteId' => $site->id])
        ->call('moveBlock', 1, 0)
        ->assertHasNoErrors();

    expect(array_column($site->fresh()->shop_index_blocks, 'heading'))->toBe(['Second', 'First']);

    Livewire::test('shop.shop-index-blocks-settings', ['siteId' => $site->id])
        ->call('removeBlock', 0)
        ->assertHasNoErrors();

    expect(array_column($site->fresh()->shop_index_blocks, 'heading'))->toBe(['First']);
});

it('rejects an invalid source and a ninth block', function () {
    ['site' => $site] = shopIndexBlocksPanelSite();
    $existing = [];
    for ($i = 1; $i <= 8; $i++) {
        $existing[] = ['source' => 'newest', 'limit' => 4, 'layout' => 'grid', 'heading' => 'Block '.$i];
    }
    $site->update(['shop_index_blocks' => $existing]);

    Livewire::test('shop.shop-index-blocks-settings', ['siteId' => $site->id])
        ->set('newHeading', 'Overflow')
        ->set('newSource', 'newest')
        ->call('addBlock')
        ->assertHasErrors('newHeading');

    expect($site->fresh()->shop_index_blocks)->toHaveCount(8);

    $site->update(['shop_index_blocks' => []]);

    Livewire::test('shop.shop-index-blocks-settings', ['siteId' => $site->id])
        ->set('newHeading', 'Picks')
        ->set('newSource', 'not-a-source')
        ->call('addBlock')
        ->assertHasErrors('newSource');
});

it('refuses a stale shop index blocks panel save instead of silently overwriting', function () {
    ['site' => $site] = shopIndexBlocksPanelSite();

    $first = Livewire::test('shop.shop-index-blocks-settings', ['siteId' => $site->id]);
    $second = Livewire::test('shop.shop-index-blocks-settings', ['siteId' => $site->id]);

    $first->set('newHeading', 'First win')->set('newSource', 'newest')->call('addBlock')->assertHasNoErrors();
    $second->set('newHeading', 'Lost update')->set('newSource', 'newest')->call('addBlock')->assertHasErrors();

    expect(array_column($site->fresh()->shop_index_blocks, 'heading'))->toBe(['First win']);
});

it('lets a client of the site manage blocks and refuses a client of another site', function () {
    $tenant = Client::factory()->create();
    $otherTenant = Client::factory()->create();
    $client = User::factory()->create(['client_id' => $tenant->id, 'role' => null]);
    $stranger = User::factory()->create(['client_id' => $otherTenant->id, 'role' => null]);
    $site = Site::factory()->create(['client_id' => $tenant->id, 'shop_enabled' => true]);

    Livewire::actingAs($client)
        ->test('shop.shop-index-blocks-settings', ['siteId' => $site->id])
        ->set('newHeading', 'Client picks')
        ->set('newSource', 'newest')
        ->call('addBlock')
        ->assertHasNoErrors();

    expect($site->fresh()->shop_index_blocks[0]['heading'])->toBe('Client picks');

    Livewire::actingAs($stranger)
        ->test('shop.shop-index-blocks-settings', ['siteId' => $site->id])
        ->assertForbidden();
});

it('mounts the shop index blocks panel on agents storefront and the client design storefront pill', function () {
    ['user' => $user, 'site' => $site] = shopIndexBlocksPanelSite();

    $this->actingAs($user)
        ->get(route('sites.shop.storefront', $site))
        ->assertOk()
        ->assertSeeLivewire('shop.shop-index-blocks-settings')
        ->assertSee('Shop index blocks');

    $tenant = Client::factory()->create();
    $client = User::factory()->create(['client_id' => $tenant->id, 'role' => null, 'last_login_at' => now()]);
    $clientSite = Site::factory()->create(['client_id' => $tenant->id, 'shop_enabled' => true]);

    $this->actingAs($client)
        ->get(route('client.portal.design', $clientSite))
        ->assertOk()
        ->assertSeeLivewire('shop.shop-index-blocks-settings');
});
