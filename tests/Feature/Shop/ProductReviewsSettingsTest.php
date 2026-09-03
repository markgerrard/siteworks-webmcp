<?php

use App\Enums\AgentRole;
use App\Models\Client;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\User;
use App\Services\Site\PublicPageCache;
use App\Support\Shop\ProductReviewSettings;
use Livewire\Livewire;

test('setKnob persists review knobs and busts the public page cache', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id, 'shop_enabled' => true]);
    $this->actingAs($user);
    $before = app(PublicPageCache::class)->generation($site);

    Livewire::test('shop.product-reviews-settings', ['siteId' => $site->id])
        ->call('setKnob', 'enabled', true)
        ->call('setKnob', 'label', 'Customer feedback')
        ->call('setKnob', 'show_on_cards', false)
        ->call('setKnob', 'min_reviews_for_card', 3)
        ->call('setKnob', 'public_form', true)
        ->call('setKnob', 'moderate', false)
        ->assertHasNoErrors();

    $settings = ProductReviewSettings::fromSite($site->fresh());
    expect($settings->toArray())->toBe([
        'enabled' => true,
        'label' => 'Customer feedback',
        'public_form' => true,
        'moderate' => false,
        'show_on_cards' => false,
        'min_reviews_for_card' => 3,
    ])->and(app(PublicPageCache::class)->generation($site))->toBeGreaterThan($before);
});

test('setKnob rejects a blank label and a min count below one', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id, 'shop_enabled' => true]);
    $this->actingAs($user);

    Livewire::test('shop.product-reviews-settings', ['siteId' => $site->id])
        ->call('setKnob', 'label', '   ')
        ->assertHasErrors('label');

    Livewire::test('shop.product-reviews-settings', ['siteId' => $site->id])
        ->call('setKnob', 'min_reviews_for_card', 0)
        ->assertHasErrors('min_reviews_for_card');

    expect(ProductReviewSettings::fromSite($site->fresh())->enabled)->toBeFalse();
});

test('a client of the site can set knobs and an outsider is denied', function () {
    $tenant = Client::factory()->create();
    $client = User::factory()->create(['client_id' => $tenant->id, 'role' => null]);
    $stranger = User::factory()->create(['client_id' => Client::factory()->create()->id, 'role' => null]);
    $site = Site::factory()->create(['client_id' => $tenant->id, 'shop_enabled' => true]);

    Livewire::actingAs($client)
        ->test('shop.product-reviews-settings', ['siteId' => $site->id])
        ->call('setKnob', 'enabled', true)
        ->assertHasNoErrors();

    expect(ProductReviewSettings::fromSite($site->fresh())->enabled)->toBeTrue();

    Livewire::actingAs($stranger)
        ->test('shop.product-reviews-settings', ['siteId' => $site->id])
        ->assertForbidden();
});

test('Design Storefront mounts the product reviews knobs on agents and clients', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $tenant = Client::factory()->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $agent->id,
        'client_id' => $tenant->id,
        'preview_domain' => 'reviews-knobs',
        'preview_brand' => 'a',
    ]);
    GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    Preview::factory()->for($site)->create(['slug' => 'reviews-knobs']);
    $client = User::factory()->create(['role' => null, 'client_id' => $tenant->id, 'last_login_at' => now()]);

    $this->actingAs($agent)
        ->get(route('sites.section', ['site' => $site, 'section' => 'design']))
        ->assertOk()
        ->assertSeeLivewire('shop.product-reviews-settings')
        ->assertSee('Product reviews');

    $this->actingAs($client)
        ->get(route('client.portal.design', $site))
        ->assertOk()
        ->assertSeeLivewire('shop.product-reviews-settings');
});
