<?php

use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Services\Shop\ProductImportParser;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\Operations\DescribeImportProductsOperation;
use App\Services\Site\Editor\Operations\ImportProductsOperation;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
});

/**
 * @return array{0: \App\Models\User, 1: \App\Models\Site, 2: int}
 */
function roundTripCatalogue(): array
{
    [$actor, $site, $category] = CommerceReads::shopSite();
    $extra = Category::factory()->for($site)->create(['slug' => 'gifts', 'name' => 'Gifts']);

    $rose = Product::factory()->for($site)->create(['name' => 'Scarlet Rose', 'slug' => 'scarlet-rose']);
    $rose->categories()->attach($category->id, ['is_primary' => true]);
    $rose->categories()->attach($extra->id, ['is_primary' => false]);
    ProductVariant::factory()->for($rose)->create(['sku' => 'ROSE-STEM', 'label' => 'Stem', 'price_cents' => 1250]);
    ProductVariant::factory()->for($rose)->create(['sku' => 'ROSE-BUNCH', 'label' => 'Bunch', 'price_cents' => 3200]);

    $lily = Product::factory()->for($site)->create(['name' => 'White Lily', 'slug' => 'white-lily']);
    $lily->categories()->attach($category->id, ['is_primary' => true]);
    ProductVariant::factory()->for($lily)->create(['sku' => 'LILY-1', 'label' => 'Stem', 'price_cents' => 800]);

    return [$actor, $site, 2];
}

it('round-trips export_products bytes through import_products dry_run for csv, md, and json including a multi-variant product', function (string $format) {
    [$actor, $site, $count] = roundTripCatalogue();

    $exported = CommerceReads::run($actor, $site, 'export_products', ['format' => $format]);
    expect($exported->ok)->toBeTrue();
    $bytes = $this->actingAs($actor)->get($exported->data['download_url'])->getContent();

    $parsed = ProductImportParser::parse($format, $bytes);
    expect($parsed)->toHaveCount($count)
        ->and(collect($parsed)->firstWhere('slug', 'scarlet-rose')['variants'])->toHaveCount(2);

    $imported = CommerceReads::run($actor, $site, 'import_products', [
        'schema_version' => 1,
        'format' => $format,
        'data' => $bytes,
        'expected_revision' => 0,
        'dry_run' => true,
    ]);

    // Re-importing the site's own export names products it already has: every row
    // matches, nothing is created, nothing is rejected.
    expect($imported->ok)->toBeTrue()
        ->and($imported->data['failed'])->toBe(0)
        ->and($imported->data['created'])->toBe(0)
        ->and($imported->data['matched'])->toBe($count)
        ->and(collect($imported->data['results'])->pluck('status')->unique()->all())->toBe(['matched'])
        ->and(Product::query()->where('site_id', $site->id)->count())->toBe($count);
})->with(['csv', 'md', 'json']);

it('discovers import_products and describe_import_products so Front 2 can register them after schemas.json regen', function () {
    $registry = app(OperationRegistry::class);
    $committed = json_decode(
        (string) file_get_contents(resource_path('js/site-editor/webmcp/schemas.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $fileOps = array_keys($committed['operations'] ?? []);

    expect($registry->has('import_products'))->toBeTrue()
        ->and($registry->has('describe_import_products'))->toBeTrue()
        ->and(app(ImportProductsOperation::class)->readOnly())->toBeFalse()
        ->and(app(DescribeImportProductsOperation::class)->readOnly())->toBeTrue()
        ->and(app(ImportProductsOperation::class)->address())->toBe('shop')
        ->and(app(DescribeImportProductsOperation::class)->address())->toBe('shop')
        ->and(app(ImportProductsOperation::class)->inputSchema()['properties'])->toHaveKeys(['schema_version', 'format', 'data']);

    $missingFromFile = array_values(array_diff(
        ['describe_import_products', 'import_products'],
        $fileOps,
    ));
    // schemas.json regenerated at merge — both ops now present, so nothing is missing.
    expect($missingFromFile)->toBe([]);
});
