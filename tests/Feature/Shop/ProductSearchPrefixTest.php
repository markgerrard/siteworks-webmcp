<?php

use App\Enums\Shop\ProductStatus;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Services\Shop\ProductSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function prefixSearchSite(): Site
{
    $site = Site::factory()->create();
    Product::factory()->for($site)->create(['name' => 'Meyer Lemon Macarons', 'status' => ProductStatus::Published]);
    Product::factory()->for($site)->create(['name' => 'Salted Caramel Macarons', 'status' => ProductStatus::Published]);
    Product::factory()->for($site)->create(['name' => 'Pear Cardamom Galette', 'status' => ProductStatus::Published]);

    return $site;
}

test('live typing matches word prefixes, not only whole words', function () {
    // 29 Aug: the header search panel fetches on every keystroke; plainto_tsquery
    // returned 0 for "lem" and 8 for "lemon" on the cakery, so the review said
    // "Nothing called 'lem' yet" until the whole word was typed.
    $site = prefixSearchSite();
    $svc = app(ProductSearchService::class);

    expect($svc->search($site->id, 'lem')->pluck('name')->all())->toBe(['Meyer Lemon Macarons'])
        ->and($svc->search($site->id, 'lemon')->pluck('name')->all())->toBe(['Meyer Lemon Macarons'])
        ->and($svc->search($site->id, 'salted car')->pluck('name')->all())->toBe(['Salted Caramel Macarons'])
        ->and($svc->search($site->id, 'macar')->count())->toBe(2)
        ->and($svc->search($site->id, 'zzz')->count())->toBe(0);
});

test('punctuation and tsquery operators in the query are harmless', function () {
    $site = prefixSearchSite();
    $svc = app(ProductSearchService::class);

    expect($svc->search($site->id, "lem'on & (galette) | :* !")->count())->toBeGreaterThanOrEqual(0)
        ->and($svc->search($site->id, '   ')->count())->toBe(0);
});
