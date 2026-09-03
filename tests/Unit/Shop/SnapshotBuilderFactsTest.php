<?php

use App\Jobs\Shop\RebuildShopSnapshot;
use App\Services\Shop\SnapshotBuilder;
use App\Support\Shop\ProductFacts;
use Illuminate\Support\Facades\Bus;
use Tests\Support\ProductFactsFixtures;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('show_on_card facts are included on the snapshot card and omitted when nothing is flagged', function (string $vertical) {
    $fixture = ProductFactsFixtures::make($vertical);
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    $builder = app(SnapshotBuilder::class);
    $json = $builder->build($site->id);
    $card = $json['products'][$product->slug]['product_card'];
    $line = ProductFacts::cardLine(ProductFacts::groups($site->product_fact_groups), $product->facts ?? []);

    if ($line === null) {
        expect($card)->not->toHaveKey('facts_line');
    } else {
        expect($card['facts_line'])->toBe($line);
    }
    expect($json['products'][$product->slug]['product_detail']['facts'])->toEqualCanonicalizing($product->facts);
})->with(ProductFactsFixtures::verticalDataset());

test('saving facts through the writer dispatches a snapshot rebuild', function () {
    Bus::fake();
    $fixture = ProductFactsFixtures::empty();
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    $written = app(\App\Services\Shop\ShopDraftWriter::class)->saveFromEditor($site, $product, [
        'name' => $product->name,
        'description' => $product->description,
        'tax_class_id' => null,
        'facts' => ['notes' => ['text' => 'updated']],
        'revision' => (int) $product->revision,
    ], $fixture['user']->id);
    ($written['deferred'])();

    Bus::assertDispatched(RebuildShopSnapshot::class, fn (RebuildShopSnapshot $job): bool => $job->siteId === $site->id);
});

test('zero-group snapshots omit orphan facts from the card payload and product detail', function () {
    $fixture = ProductFactsFixtures::empty();
    $product = $fixture['products'][0];
    $product->update([
        'facts' => [
            'removed-tab' => ['text' => 'ORPHAN-FACT-TEXT-MUST-NOT-LEAK'],
        ],
    ]);

    $json = app(SnapshotBuilder::class)->build($fixture['site']->id);
    $row = $json['products'][$product->slug];

    expect($row['product_detail'])->not->toHaveKey('facts')
        ->and($row['product_card'])->not->toHaveKey('facts_line');
});

test('product detail facts include only slugs from the site\'s current groups', function () {
    $fixture = ProductFactsFixtures::empty();
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    $site->update([
        'product_fact_groups' => [
            ['slug' => 'notes', 'label' => 'Notes', 'kind' => 'text', 'show_on_card' => false, 'schema' => null],
        ],
    ]);
    $product->update([
        'facts' => [
            'notes' => ['text' => 'Keep me.'],
            'removed-tab' => ['text' => 'drop me'],
        ],
    ]);

    $json = app(SnapshotBuilder::class)->build($site->id);

    expect($json['products'][$product->slug]['product_detail']['facts'])->toBe([
        'notes' => ['text' => 'Keep me.'],
    ]);
});
