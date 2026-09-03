<?php

use App\Support\Shop\ProductFacts;
use Tests\Support\ProductFactsFixtures;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('cards show a muted facts line only when a group is flagged and has a pair', function (string $vertical) {
    $fixture = ProductFactsFixtures::make($vertical);
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    $groups = ProductFacts::groups($site->product_fact_groups);
    $line = ProductFacts::cardLine($groups, $product->facts ?? []);

    $card = [
        'slug' => $product->slug,
        'name' => $product->name,
        'price_display' => '£25.00',
    ];
    if ($line !== null) {
        $card['facts_line'] = $line;
    }

    $html = view('shop.partials.product-card', [
        'product' => [
            'slug' => $product->slug,
            'in_stock_any' => true,
            'image_urls' => null,
            'variants' => [['id' => 1]],
            'product_card' => $card,
        ],
        'site' => $site,
    ])->render();

    if ($line === null) {
        expect($html)->not->toContain('color: var(--color-text-muted)')
            ->and($html)->toContain($product->name);
    } else {
        expect($html)->toContain($line)
            ->and($html)->toContain('color: var(--color-text-muted)');
    }

    $off = $groups;
    foreach ($off as $i => $group) {
        $off[$i]['show_on_card'] = false;
    }
    expect(ProductFacts::cardLine($off, $product->facts ?? []))->toBeNull();
    $htmlOff = view('shop.partials.product-card', [
        'product' => [
            'slug' => $product->slug,
            'in_stock_any' => true,
            'image_urls' => null,
            'variants' => [['id' => 1]],
            'product_card' => ['slug' => $product->slug, 'name' => $product->name, 'price_display' => '£25.00'],
        ],
        'site' => $site,
    ])->render();
    expect($htmlOff)->not->toContain('facts_line')
        ->and($htmlOff)->toContain($product->name);
})->with(ProductFactsFixtures::verticalDataset());
