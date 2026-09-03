<?php

use App\Models\Shop\ShopDraft;
use App\Services\Shop\SnapshotBuilder;
use Database\Seeders\Shop\TaxClassSeeder;
use Livewire\Livewire;
use Tests\Support\CommerceReads;
use Tests\Support\LinePersonalisationFixtures;

beforeEach(function () {
    $this->seed(TaxClassSeeder::class);
    CommerceReads::enableFlags();
});

it('round-trips tags and customer_inputs through editor save, agent update, and snapshot', function () {
    [$actor, $site, $category] = CommerceReads::shopSite();
    $site->update([
        'product_tags' => [
            ['slug' => 'seasonal', 'label' => 'Seasonal', 'show_as_badge' => true, 'tone' => 'warning'],
            ['slug' => 'gift', 'label' => 'Gift', 'show_as_badge' => false, 'tone' => 'neutral'],
        ],
    ]);
    $product = \App\Models\Shop\Product::factory()->for($site)->create([
        'name' => 'Item',
        'slug' => 'item',
    ]);
    $product->categories()->attach($category->id, ['is_primary' => true]);
    $this->actingAs($actor);

    Livewire::test('shop.product-editor', ['siteId' => $site->id, 'productId' => $product->id])
        ->call('toggleTag', 'seasonal')
        ->set('customerInputs', LinePersonalisationFixtures::generic())
        ->call('save')
        ->assertHasNoErrors();

    expect($product->fresh()->tags)->toBe(['seasonal'])
        ->and($product->fresh()->customer_inputs[0]['slug'])->toBe('engraving');

    $write = CommerceReads::run($actor, $site, 'update_draft_product', [
        'catalogue_revision' => (int) ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision'),
        'slug' => 'item',
        'product_revision' => (int) $product->fresh()->revision,
        'tags' => ['gift'],
        'customer_inputs' => LinePersonalisationFixtures::bakery(),
    ]);

    expect($write->ok)->toBeTrue();

    $read = CommerceReads::run($actor, $site, 'get_product', ['slug' => 'item']);
    expect($read->ok)->toBeTrue()
        ->and($read->data['tags'])->toBe(['gift'])
        ->and($read->data['customer_inputs'][0]['slug'])->toBe('message');

    CommerceReads::drainRebuild($site->id);
    $json = app(SnapshotBuilder::class)->build($site->id);

    expect($json['products']['item']['tags'][0]['slug'])->toBe('gift')
        ->and($json['products']['item']['customer_inputs'][0]['slug'])->toBe('message');
});
