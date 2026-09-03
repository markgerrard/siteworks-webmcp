<?php

use App\Models\Shop\Product;
use App\Services\Shop\ProductImportContract;
use App\Services\Shop\ProductImporter;
use Database\Seeders\Shop\TaxClassSeeder;
use Illuminate\Support\Str;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
    $this->seed(TaxClassSeeder::class);
});

/**
 * @param  list<array<string, mixed>>  $products
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function matchingImport(array $products, array $overrides = []): array
{
    return array_merge([
        'schema_version' => ProductImportContract::SCHEMA_VERSION,
        'format' => 'json',
        'data' => json_encode($products, JSON_THROW_ON_ERROR),
        'expected_revision' => 0,
        'dry_run' => false,
        'idempotency_key' => (string) Str::uuid(),
    ], $overrides);
}

/**
 * @return array{0: \App\Models\User, 1: \App\Models\Site, 2: Product}
 */
function matchingSiteWithTart(): array
{
    [$actor, $site] = CommerceReads::shopSite();
    $existing = Product::factory()->for($site)->create(['name' => 'Fig & Walnut Tart', 'slug' => 'fig-walnut-tart']);

    return [$actor, $site, $existing];
}

it('normalises names so case, punctuation and spacing do not make a new product', function () {
    expect(ProductImporter::normaliseName('Fig & Walnut Tart'))
        ->toBe(ProductImporter::normaliseName('fig and walnut tart'))
        ->toBe(ProductImporter::normaliseName('FIG &  WALNUT-TART'))
        ->and(ProductImporter::normaliseName('Fig Tart'))->not->toBe(ProductImporter::normaliseName('Fig & Walnut Tart'));
});

it('reports a name match as matched in the dry run and never creates it on commit', function () {
    [$actor, $site, $existing] = matchingSiteWithTart();
    $rows = [
        ['name' => 'fig and walnut tart', 'primary_category_slug' => 'candles', 'variants' => [['sku' => 'FWT-2', 'price_pence' => 550]]],
        ['name' => 'Pumpkin Spice Loaf', 'primary_category_slug' => 'candles', 'variants' => [['sku' => 'PSL-1', 'price_pence' => 650]]],
    ];

    $preview = CommerceReads::run($actor, $site, 'import_products', matchingImport($rows, ['dry_run' => true]));

    expect($preview->ok)->toBeTrue()
        ->and($preview->data['created'])->toBe(1)
        ->and($preview->data['matched'])->toBe(1)
        ->and($preview->data['failed'])->toBe(0)
        ->and($preview->data['results'][0])->toMatchArray([
            'status' => 'matched',
            'slug' => 'fig-walnut-tart',
            'product_id' => $existing->id,
            'name' => 'fig and walnut tart',
            'warnings' => ['matches_existing', 'missing_description'],
        ]);

    $commit = CommerceReads::run($actor, $site, 'import_products', matchingImport($rows, ['plan_token' => $preview->data['plan_token']]));

    expect($commit->ok)->toBeTrue()
        ->and($commit->data['created'])->toBe(1)
        ->and($commit->data['matched'])->toBe(1)
        ->and($commit->data['results'][0]['status'])->toBe('matched')
        ->and($commit->data['results'][1]['status'])->toBe('created')
        ->and(Product::query()->where('site_id', $site->id)->where('slug', 'like', 'fig-walnut-tart%')->count())->toBe(1)
        ->and(Product::query()->where('site_id', $site->id)->count())->toBe(2);
});

it('creates a matching row only when told to, and still notes the match on the draft', function () {
    [$actor, $site] = matchingSiteWithTart();
    $row = ['name' => 'Fig & Walnut Tart', 'primary_category_slug' => 'candles', 'variants' => [['sku' => 'FWT-2', 'price_pence' => 550]]];

    $forced = CommerceReads::run($actor, $site, 'import_products', matchingImport([$row], ['force_create' => true]));

    expect($forced->ok)->toBeTrue()
        ->and($forced->data['results'][0]['status'])->toBe('created')
        ->and($forced->data['results'][0]['slug'])->toBe('fig-walnut-tart-2')
        ->and($forced->data['results'][0]['warnings'])->toContain('matches_existing')
        ->and(Product::query()->where('site_id', $site->id)->where('slug', 'fig-walnut-tart-2')->value('review_notes'))->toContain('matches_existing');

    $distinct = CommerceReads::run($actor, $site, 'import_products', matchingImport(
        [[...$row, 'slug' => 'fig-walnut-tart-large', 'variants' => [['sku' => 'FWT-3', 'price_pence' => 950]]]],
        ['expected_revision' => $forced->data['new_revision']],
    ));

    expect($distinct->ok)->toBeTrue()
        ->and($distinct->data['results'][0])->toMatchArray(['status' => 'created', 'slug' => 'fig-walnut-tart-large'])
        ->and($distinct->data['results'][0]['warnings'])->toContain('matches_existing');
});

it('reports a taken slug in the dry run instead of discovering it on commit', function () {
    [$actor, $site] = matchingSiteWithTart();

    $preview = CommerceReads::run($actor, $site, 'import_products', matchingImport([
        ['name' => 'Something Else', 'slug' => 'fig-walnut-tart', 'primary_category_slug' => 'candles', 'variants' => [['sku' => 'SE-1', 'price_pence' => 100]]],
    ], ['dry_run' => true]));

    expect($preview->ok)->toBeTrue()
        ->and($preview->data['results'][0])->toMatchArray(['status' => 'rejected', 'errors' => ['slug_taken']]);
});

it('changes the plan token when force_create changes', function () {
    [$actor, $site] = matchingSiteWithTart();
    $row = ['name' => 'Fig & Walnut Tart', 'primary_category_slug' => 'candles', 'variants' => [['sku' => 'FWT-2', 'price_pence' => 550]]];

    $plain = CommerceReads::run($actor, $site, 'import_products', matchingImport([$row], ['dry_run' => true]));
    $forced = CommerceReads::run($actor, $site, 'import_products', matchingImport([$row], ['dry_run' => true, 'force_create' => true]));

    expect($plain->data['plan_token'])->not->toBe($forced->data['plan_token']);
});
