<?php

use App\Models\Client;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Models\User;
use Tests\Support\CommerceReads;

/**
 * @return array{0: Site, 1: User, 2: Product}
 */
function portalExportSite(): array
{
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);
    $category = Category::factory()->for($site)->create(['name' => 'Bouquets', 'slug' => 'bouquets']);
    $product = Product::factory()->for($site)->published()->create(['name' => 'Client Rose', 'slug' => 'client-rose']);
    $product->categories()->attach($category->id, ['is_primary' => true]);
    ProductVariant::factory()->for($product)->create(['sku' => 'ROSE-1', 'label' => 'Stem', 'price_cents' => 999]);
    CommerceReads::giveShop($site);

    return [$site, $client, $product];
}

it('lets a client export their own catalogue in each format with the right mime', function (string $format, string $mime) {
    [$site, $client] = portalExportSite();

    $response = $this->actingAs($client)
        ->get(route('client.portal.shop.products.export', ['site' => $site, 'format' => $format]));

    $response->assertSuccessful();
    expect($response->headers->get('content-type'))->toContain($mime);
})->with([
    ['csv', 'text/csv'],
    ['md', 'text/markdown'],
    ['json', 'application/json'],
]);

it('carries the known product sku/price/category through every export format', function (string $format) {
    [$site, $client] = portalExportSite();

    $body = $this->actingAs($client)
        ->get(route('client.portal.shop.products.export', ['site' => $site, 'format' => $format]))
        ->assertSuccessful()
        ->streamedContent();

    expect($body)->toContain('ROSE-1')
        ->and($body)->toContain('Client Rose');

    if ($format === 'json') {
        expect($body)->toContain('bouquets')
            ->and($body)->toContain('999');
    } elseif ($format === 'md') {
        expect($body)->toContain('bouquets')
            ->and($body)->toContain('9.99');
    } else {
        expect($body)->toContain('Bouquets')
            ->and($body)->toContain('9.99');
    }
})->with(['csv', 'md', 'json']);

it('403s the portal export route for a client of another tenant', function () {
    [$site] = portalExportSite();
    $otherTenant = Client::factory()->create();
    $stranger = User::factory()->create([
        'client_id' => $otherTenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);

    $this->actingAs($stranger)
        ->get(route('client.portal.shop.products.export', $site))
        ->assertForbidden();
});

it('performs zero writes: no revision bump and no snapshot rebuild from a portal export', function () {
    [$site, $client] = portalExportSite();
    $beforeRevision = \App\Models\Shop\ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision');
    $beforeSnapshotId = ShopSnapshotCurrent::query()->where('site_id', $site->id)->value('snapshot_id');

    $this->actingAs($client)
        ->get(route('client.portal.shop.products.export', ['site' => $site, 'format' => 'json']))
        ->assertSuccessful();

    expect(\App\Models\Shop\ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision'))->toBe($beforeRevision)
        ->and(ShopSnapshotCurrent::query()->where('site_id', $site->id)->value('snapshot_id'))->toBe($beforeSnapshotId);
});
