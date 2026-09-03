<?php

use App\Models\Client;
use App\Models\User;
use App\Support\Shop\ProductFacts;
use Livewire\Livewire;
use Tests\Support\ProductFactsFixtures;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('facts save round-trips per site group order', function (string $vertical) {
    $fixture = ProductFactsFixtures::make($vertical);
    $this->actingAs($fixture['user']);
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    $groups = ProductFacts::groups($site->product_fact_groups);
    $first = $groups[0];

    $component = Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->assertSee('Facts')
        ->assertSee($first['label']);

    if ($first['kind'] === 'text') {
        $component->set('factValues.'.$first['slug'].'.text', 'Updated copy for this tab.')->call('save');
        expect($product->fresh()->facts[$first['slug']]['text'])->toBe('Updated copy for this tab.');
    } else {
        $component
            ->set('factValues.'.$first['slug'].'.pairs.0.label', 'Width')
            ->set('factValues.'.$first['slug'].'.pairs.0.value', '12')
            ->call('save');
        expect($product->fresh()->facts[$first['slug']]['pairs'][0])->toMatchArray([
            'label' => 'Width',
            'value' => '12',
        ]);
    }
})->with(ProductFactsFixtures::verticalDataset());

test('zero fact groups hides the Facts section', function () {
    $fixture = ProductFactsFixtures::empty();
    $this->actingAs($fixture['user']);

    Livewire::test('shop.product-editor', [
        'siteId' => $fixture['site']->id,
        'productId' => $fixture['products'][0]->id,
    ])->assertDontSee('Facts');
});

test('a stale facts save shows the revision conflict and does not overwrite', function (string $vertical) {
    $fixture = ProductFactsFixtures::make($vertical);
    $this->actingAs($fixture['user']);
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    $original = $product->facts;
    $groups = ProductFacts::groups($site->product_fact_groups);
    $first = $groups[0];

    $component = Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id]);
    if ($first['kind'] === 'text') {
        $component->set('factValues.'.$first['slug'].'.text', 'From this tab');
    } else {
        $component->set('factValues.'.$first['slug'].'.pairs.0.value', 'From this tab');
    }

    $product->update([
        'revision' => (int) $product->revision + 1,
        'facts' => $original,
    ]);

    $component->call('save')
        ->assertHasErrors('revision')
        ->assertSee('This product was changed elsewhere — reload to see the latest.');

    expect($product->fresh()->facts)->toEqualCanonicalizing($original);
})->with(ProductFactsFixtures::verticalDataset());

test('a client of the site can save facts', function (string $vertical) {
    $fixture = ProductFactsFixtures::make($vertical);
    $tenant = Client::factory()->create();
    $fixture['site']->update(['client_id' => $tenant->id]);
    $client = User::factory()->create([
        'role' => null,
        'client_id' => $tenant->id,
        'last_login_at' => now(),
    ]);
    $product = $fixture['products'][0];
    $groups = ProductFacts::groups($fixture['site']->product_fact_groups);
    $textGroup = collect($groups)->firstWhere('kind', 'text');

    Livewire::actingAs($client)
        ->test('shop.product-editor', [
            'siteId' => $fixture['site']->id,
            'productId' => $product->id,
            'listRoute' => 'client.portal.shop.products',
        ])
        ->set('factValues.'.$textGroup['slug'].'.text', 'Client-edited copy.')
        ->call('save');

    expect($product->fresh()->facts[$textGroup['slug']]['text'])->toBe('Client-edited copy.');
})->with(ProductFactsFixtures::verticalDataset());
