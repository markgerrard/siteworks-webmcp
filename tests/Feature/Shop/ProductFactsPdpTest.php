<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Support\Shop\ProductFacts;
use Tests\Support\ProductFactsFixtures;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('zero groups or all-empty values omit the tab strip and keep description as today', function (string $vertical) {
    $fixture = ProductFactsFixtures::make($vertical);
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    $host = $site->custom_domain;

    $site->update(['product_fact_groups' => null]);
    ProductFactsFixtures::pdpSnapshot($site, $product, $product->facts ?? []);
    $html = $this->get('http://'.$host.'/products/'.$product->slug)->assertOk()->getContent();
    expect($html)->not->toContain('role="tablist"')
        ->and($html)->toContain('mb-6')
        ->and($html)->toContain($product->description);

    $groups = ProductFacts::presetGroups($vertical);
    $site->update(['product_fact_groups' => $groups]);
    ProductFactsFixtures::pdpSnapshot($site, $product, []);
    $html = $this->get('http://'.$host.'/products/'.$product->slug)->assertOk()->getContent();
    expect($html)->not->toContain('role="tablist"')
        ->and($html)->toContain($product->description);
})->with(ProductFactsFixtures::verticalDataset());

test('one filled group and all filled groups render a tab strip skipping empties', function (string $vertical) {
    $fixture = ProductFactsFixtures::make($vertical);
    $site = $fixture['site'];
    $full = $fixture['products'][0];
    $partial = $fixture['products'][1];
    $host = $site->custom_domain;
    $groups = ProductFacts::groups($site->product_fact_groups);

    ProductFactsFixtures::pdpSnapshot($site, $full, $full->facts);
    $html = $this->get('http://'.$host.'/products/'.$full->slug)->assertOk()->getContent();
    $tabs = ProductFacts::visibleTabs($groups, $full->facts);
    expect($html)->toContain('role="tablist"')
        ->and($html)->toContain('Description')
        ->and($html)->toContain('aria-selected')
        ->and($html)->toContain('md:hidden');
    foreach ($tabs as $tab) {
        expect(html_entity_decode($html, ENT_QUOTES))->toContain($tab['label']);
    }

    ProductFactsFixtures::pdpSnapshot($site, $partial, $partial->facts);
    $html = $this->get('http://'.$host.'/products/'.$partial->slug)->assertOk()->getContent();
    $visible = ProductFacts::visibleTabs($groups, $partial->facts);
    $hidden = collect($groups)->reject(fn (array $group): bool => collect($visible)->contains(fn (array $tab): bool => $tab['slug'] === $group['slug']));
    expect($html)->toContain('role="tablist"');
    $decoded = html_entity_decode($html, ENT_QUOTES);
    foreach ($visible as $tab) {
        expect($decoded)->toContain($tab['label']);
    }
    foreach ($hidden as $group) {
        expect($decoded)->not->toContain('>'.$group['label'].'<');
    }
})->with(ProductFactsFixtures::verticalDataset());

test('under md the facts markup is an accordion with Description open', function (string $vertical) {
    $fixture = ProductFactsFixtures::make($vertical);
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    ProductFactsFixtures::pdpSnapshot($site, $product, $product->facts);
    $html = $this->get('http://'.$site->custom_domain.'/products/'.$product->slug)->assertOk()->getContent();

    expect($html)->toContain('<details open>')
        ->and($html)->toContain('<summary')
        ->and($html)->toContain('Description')
        ->and($html)->toContain('md:hidden');
})->with(ProductFactsFixtures::verticalDataset());
