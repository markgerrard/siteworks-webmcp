<?php

use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Client;
use App\Models\Shop\Product;
use App\Models\Shop\ShopDraft;
use App\Models\Site;
use App\Models\User;
use App\Support\Shop\AutoTagConfig;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

function tagsPanelSite(?User $user = null, array $siteAttrs = []): array
{
    $user ??= User::factory()->staff()->create();
    $site = Site::factory()->create(array_merge([
        'created_by_user_id' => $user->id,
        'shop_enabled' => true,
    ], $siteAttrs));
    test()->actingAs($user);

    return compact('user', 'site');
}

it('adds a vocabulary tag and dispatches a snapshot rebuild', function () {
    ['site' => $site] = tagsPanelSite();
    Bus::fake();

    Livewire::test('shop.tags-badges-settings', ['siteId' => $site->id])
        ->set('newLabel', 'Same day')
        ->set('newTone', 'accent')
        ->set('newShowAsBadge', true)
        ->call('addTag')
        ->assertHasNoErrors();

    $tags = $site->fresh()->product_tags;
    expect($tags)->toHaveCount(1)
        ->and($tags[0])->toMatchArray([
            'slug' => 'same-day',
            'label' => 'Same day',
            'show_as_badge' => true,
            'tone' => 'accent',
        ]);
    Bus::assertDispatched(RebuildShopSnapshot::class, fn (RebuildShopSnapshot $job): bool => $job->siteId === $site->id);
});

it('rejects a 41st tag and a duplicate slug', function () {
    ['site' => $site] = tagsPanelSite();
    $existing = [];
    for ($i = 1; $i <= 40; $i++) {
        $existing[] = ['slug' => 'tag-'.$i, 'label' => 'Tag '.$i, 'show_as_badge' => false, 'tone' => 'neutral'];
    }
    $site->update(['product_tags' => $existing]);

    Livewire::test('shop.tags-badges-settings', ['siteId' => $site->id])
        ->set('newLabel', 'Overflow')
        ->call('addTag')
        ->assertHasErrors('newLabel');

    expect($site->fresh()->product_tags)->toHaveCount(40);

    $site->update(['product_tags' => [
        ['slug' => 'same-day', 'label' => 'Same day', 'show_as_badge' => true, 'tone' => 'accent'],
    ]]);

    Livewire::test('shop.tags-badges-settings', ['siteId' => $site->id])
        ->set('newLabel', 'Same day')
        ->call('addTag')
        ->assertHasErrors('newLabel');
});

it('saves auto-rule toggles with thresholds and label text', function () {
    ['site' => $site] = tagsPanelSite();
    Bus::fake();

    Livewire::test('shop.tags-badges-settings', ['siteId' => $site->id])
        ->set('autoRules.new.enabled', true)
        ->set('autoRules.new.label', 'Just in')
        ->set('autoRules.new.params.days', 7)
        ->set('autoRules.low_stock.enabled', true)
        ->set('autoRules.low_stock.params.threshold', 3)
        ->call('saveAutoRules')
        ->assertHasNoErrors();

    $saved = AutoTagConfig::normalize($site->fresh()->auto_tags);
    expect($saved['new']['enabled'])->toBeTrue()
        ->and($saved['new']['label'])->toBe('Just in')
        ->and($saved['new']['params']['days'])->toBe(7)
        ->and($saved['low-stock']['enabled'])->toBeTrue()
        ->and($saved['low-stock']['params']['threshold'])->toBe(3)
        ->and($saved['best-seller']['enabled'])->toBeFalse();
    Bus::assertDispatched(RebuildShopSnapshot::class);
});

it('lets a client of the site manage tags and refuses a client of another site', function () {
    $tenant = Client::factory()->create();
    $otherTenant = Client::factory()->create();
    $client = User::factory()->create(['client_id' => $tenant->id, 'role' => null]);
    $stranger = User::factory()->create(['client_id' => $otherTenant->id, 'role' => null]);
    $site = Site::factory()->create(['client_id' => $tenant->id, 'shop_enabled' => true]);

    Livewire::actingAs($client)
        ->test('shop.tags-badges-settings', ['siteId' => $site->id])
        ->set('newLabel', 'Seasonal')
        ->call('addTag')
        ->assertHasNoErrors();

    expect($site->fresh()->product_tags[0]['slug'])->toBe('seasonal');

    Livewire::actingAs($stranger)
        ->test('shop.tags-badges-settings', ['siteId' => $site->id])
        ->assertForbidden();
});

it('returns an inline validation error when a vocabulary write is rejected', function () {
    ['site' => $site] = tagsPanelSite();

    Livewire::test('shop.tags-badges-settings', ['siteId' => $site->id])
        ->set('autoRules.new.tone', 'rainbow')
        ->call('saveAutoRules')
        ->assertHasErrors();
});

it('sweeps a removed vocabulary slug from every product on the site', function () {
    ['site' => $site] = tagsPanelSite();
    $site->update(['product_tags' => [
        ['slug' => 'gift', 'label' => 'Gift', 'show_as_badge' => false, 'tone' => 'neutral'],
        ['slug' => 'seasonal', 'label' => 'Seasonal', 'show_as_badge' => true, 'tone' => 'warning'],
    ]]);
    $kept = Product::factory()->for($site)->create(['tags' => ['gift', 'seasonal']]);
    $cleared = Product::factory()->for($site)->create(['tags' => ['gift']]);
    $untouched = Product::factory()->for($site)->create(['tags' => ['seasonal']]);
    $foreign = Product::factory()->for(Site::factory()->create(['shop_enabled' => true]))->create(['tags' => ['gift']]);
    Bus::fake();

    Livewire::test('shop.tags-badges-settings', ['siteId' => $site->id])
        ->call('removeTag', 0)
        ->assertHasNoErrors();

    expect(array_column($site->fresh()->product_tags, 'slug'))->toBe(['seasonal'])
        ->and($kept->fresh()->tags)->toBe(['seasonal'])
        ->and($cleared->fresh()->tags)->toBe([])
        ->and($untouched->fresh()->tags)->toBe(['seasonal'])
        ->and($foreign->fresh()->tags)->toBe(['gift'])
        ->and((int) $kept->fresh()->revision)->toBe(1)
        ->and((int) $cleared->fresh()->revision)->toBe(1)
        ->and((int) $untouched->fresh()->revision)->toBe(0)
        ->and((int) ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision'))->toBe(1);
    Bus::assertDispatched(RebuildShopSnapshot::class, fn (RebuildShopSnapshot $job): bool => $job->siteId === $site->id);
});

it('refuses a stale tags panel save instead of silently overwriting', function () {
    ['site' => $site] = tagsPanelSite();

    $first = Livewire::test('shop.tags-badges-settings', ['siteId' => $site->id]);
    $second = Livewire::test('shop.tags-badges-settings', ['siteId' => $site->id]);

    $first->set('newLabel', 'First win')->call('addTag')->assertHasNoErrors();
    $second->set('newLabel', 'Lost update')->call('addTag')->assertHasErrors();

    expect(array_column($site->fresh()->product_tags, 'slug'))->toBe(['first-win']);
});

it('mounts the tags panel on the agents storefront page', function () {
    ['user' => $user, 'site' => $site] = tagsPanelSite();

    $this->actingAs($user)
        ->get(route('sites.shop.storefront', $site))
        ->assertOk()
        ->assertSeeLivewire('shop.tags-badges-settings')
        ->assertSee('Tags & badges');
});
