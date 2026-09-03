<?php

use App\Enums\AgentRole;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Client;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('an agent can add rename reorder and remove fact groups', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    $component = Livewire::actingAs($agent)
        ->test('shop.product-fact-groups', ['siteId' => $site->id])
        ->set('newLabel', 'Notes')
        ->set('newKind', 'text')
        ->call('addGroup')
        ->set('newLabel', 'Specs')
        ->set('newKind', 'pairs')
        ->call('addGroup');

    $groups = $site->fresh()->product_fact_groups;
    expect($groups)->toHaveCount(2)
        ->and($groups[0]['slug'])->toBe('notes')
        ->and($groups[1]['slug'])->toBe('specs');

    $component->call('setGroupField', 0, 'label', 'More notes')
        ->call('setGroupField', 1, 'show_on_card', true)
        ->call('setGroupField', 1, 'schema', 'size')
        ->call('moveGroup', 1, 'up');

    $groups = $site->fresh()->product_fact_groups;
    expect($groups[0]['slug'])->toBe('specs')
        ->and($groups[0]['show_on_card'])->toBeTrue()
        ->and($groups[0]['schema'])->toBe('size')
        ->and($groups[1]['label'])->toBe('More notes');

    $component->call('removeGroup', 0);
    expect($site->fresh()->product_fact_groups)->toHaveCount(1)
        ->and($site->fresh()->product_fact_groups[0]['slug'])->toBe('notes');
});

test('changing a group kind converts stored values both ways and a later product save keeps them', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    Livewire::actingAs($agent)
        ->test('shop.product-fact-groups', ['siteId' => $site->id])
        ->set('newLabel', 'Notes')
        ->set('newKind', 'text')
        ->call('addGroup');
    $product = Product::factory()->for($site)->create([
        'facts' => ['notes' => ['text' => "Handle with care.\nKeep dry."]],
    ]);

    Livewire::actingAs($agent)
        ->test('shop.product-fact-groups', ['siteId' => $site->id])
        ->call('setGroupField', 0, 'kind', 'pairs');

    expect($site->fresh()->product_fact_groups[0]['kind'])->toBe('pairs')
        ->and($product->fresh()->facts['notes'])->toBe([
            'pairs' => [['label' => '', 'value' => "Handle with care.\nKeep dry."]],
        ]);

    $this->actingAs($agent);
    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('name', $product->name.' edited')
        ->call('save');

    expect($product->fresh()->facts['notes']['pairs'][0]['value'])->toBe("Handle with care.\nKeep dry.");

    Livewire::actingAs($agent)
        ->test('shop.product-fact-groups', ['siteId' => $site->id])
        ->call('setGroupField', 0, 'kind', 'text');

    expect($site->fresh()->product_fact_groups[0]['kind'])->toBe('text')
        ->and($product->fresh()->facts['notes'])->toBe([
            'text' => "Handle with care.\nKeep dry.",
        ]);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->set('description', 'Still here')
        ->call('save');

    expect($product->fresh()->facts['notes']['text'])->toBe("Handle with care.\nKeep dry.");
});

test('setGroupField ignores a bogus kind without persisting', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('shop.product-fact-groups', ['siteId' => $site->id])
        ->set('newLabel', 'Notes')
        ->call('addGroup')
        ->call('setGroupField', 0, 'kind', 'table');

    expect($site->fresh()->product_fact_groups[0]['kind'])->toBe('text');
});

test('applying a preset replaces groups after confirmation and never touches product values', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $product = Product::factory()->for($site)->create([
        'facts' => ['notes' => ['text' => 'kept']],
    ]);

    $component = Livewire::actingAs($agent)
        ->test('shop.product-fact-groups', ['siteId' => $site->id])
        ->set('newLabel', 'Notes')
        ->call('addGroup')
        ->call('applyPreset', 'generic-specifications');

    expect($site->fresh()->product_fact_groups)->toHaveCount(1)
        ->and($component->get('pendingPreset'))->toBe('generic-specifications');

    $component->call('confirmApplyPreset');

    expect(collect($site->fresh()->product_fact_groups)->pluck('slug')->all())->toBe(['specifications', 'details'])
        ->and($product->fresh()->facts)->toBe(['notes' => ['text' => 'kept']]);
});

test('applying a preset to a zero-group store does not wait for confirmation', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('shop.product-fact-groups', ['siteId' => $site->id])
        ->call('applyPreset', 'generic-specifications');

    expect(collect($site->fresh()->product_fact_groups)->pluck('slug')->all())->toBe(['specifications', 'details']);
});

test('removing a group warns with the product count and does not delete values', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    Livewire::actingAs($agent)
        ->test('shop.product-fact-groups', ['siteId' => $site->id])
        ->set('newLabel', 'Notes')
        ->call('addGroup');
    Product::factory()->for($site)->create(['facts' => ['notes' => ['text' => 'one']]]);
    Product::factory()->for($site)->create(['facts' => ['notes' => ['text' => 'two']]]);

    $html = Livewire::actingAs($agent)
        ->test('shop.product-fact-groups', ['siteId' => $site->id])
        ->assertSee('2 products have values in this tab')
        ->call('removeGroup', 0)
        ->html();

    expect($site->fresh()->product_fact_groups)->toBeNull()
        ->and(Product::query()->where('site_id', $site->id)->pluck('facts')->all())->toBe([
            ['notes' => ['text' => 'one']],
            ['notes' => ['text' => 'two']],
        ]);
    expect($html)->not->toContain('2 products have values in this tab');
});

test('writes bust the public page cache and dispatch a snapshot rebuild', function () {
    config(['site.public_cache_enabled' => true]);
    Bus::fake();
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $counterKey = "site:{$site->id}:pubcache_counter";
    $before = (int) cache()->get($counterKey, 0);

    Livewire::actingAs($agent)
        ->test('shop.product-fact-groups', ['siteId' => $site->id])
        ->set('newLabel', 'Notes')
        ->call('addGroup');

    expect((int) cache()->get($counterKey, 0))->toBeGreaterThan($before);
    Bus::assertDispatched(RebuildShopSnapshot::class, fn (RebuildShopSnapshot $job): bool => $job->siteId === $site->id);
});

test('a client of the site can edit fact groups and an outsider is denied', function () {
    $tenant = Client::factory()->create();
    $other = Client::factory()->create();
    $site = Site::factory()->create(['client_id' => $tenant->id]);
    $client = User::factory()->create(['role' => null, 'client_id' => $tenant->id]);
    $outsider = User::factory()->create(['role' => null, 'client_id' => $other->id]);

    $component = Livewire::actingAs($client)
        ->test('shop.product-fact-groups', ['siteId' => $site->id])
        ->set('newLabel', 'Notes')
        ->call('addGroup');

    expect($site->fresh()->product_fact_groups[0]['slug'])->toBe('notes');

    $this->actingAs($outsider);
    $component->set('newLabel', 'Hijack')->call('addGroup')->assertForbidden();

    expect($site->fresh()->product_fact_groups)->toHaveCount(1);
});

test('agents design and client design pages mount the product facts panel', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $tenant = Client::factory()->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $agent->id,
        'client_id' => $tenant->id,
        'preview_domain' => 'facts-panel',
        'preview_brand' => 'a',
    ]);
    GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    Preview::factory()->for($site)->create(['slug' => 'facts-panel']);
    $client = User::factory()->create(['role' => null, 'client_id' => $tenant->id, 'last_login_at' => now()]);

    $this->actingAs($agent)
        ->get(route('sites.section', ['site' => $site, 'section' => 'design']))
        ->assertOk()
        ->assertSeeLivewire('shop.product-fact-groups')
        ->assertSee('Product facts')
        ->assertSee('Storefront');

    $this->actingAs($client)
        ->get(route('client.portal.design', $site))
        ->assertOk()
        ->assertSeeLivewire('shop.product-fact-groups')
        ->assertSee('Product facts');
});
