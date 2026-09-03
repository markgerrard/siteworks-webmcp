<?php

use App\Models\Shop\Product;
use App\Services\Shop\ProductImportContract;
use Database\Seeders\Shop\TaxClassSeeder;
use Illuminate\Support\Str;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
    $this->seed(TaxClassSeeder::class);
});

/**
 * @param  list<array<string, mixed>>  $products
 * @return array<string, mixed>
 */
function reviewNotesImport(array $products, bool $dryRun = false): array
{
    return [
        'schema_version' => ProductImportContract::SCHEMA_VERSION,
        'format' => 'json',
        'data' => json_encode($products, JSON_THROW_ON_ERROR),
        'expected_revision' => 0,
        'dry_run' => $dryRun,
        'idempotency_key' => (string) Str::uuid(),
    ];
}

it('persists a warned row\'s notes on the product and leaves a clean row null', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $result = CommerceReads::run($actor, $site, 'import_products', reviewNotesImport([
        [
            'name' => 'Bare Candle',
            'slug' => 'bare-candle',
            'primary_category_slug' => 'candles',
            'extra_category_slugs' => ['candles'],
            'variants' => [
                ['sku' => 'BC-S', 'price_pence' => 800],
                ['sku' => 'BC-L', 'price_pence' => 1200, 'label' => 'Large'],
            ],
        ],
        [
            'name' => 'Described Candle',
            'slug' => 'described-candle',
            'description' => 'Hand poured.',
            'primary_category_slug' => 'candles',
            'variants' => [['sku' => 'DC-1', 'price_pence' => 900, 'label' => 'Each']],
        ],
    ]));

    $warned = Product::query()->where('site_id', $site->id)->where('slug', 'bare-candle')->firstOrFail();
    $clean = Product::query()->where('site_id', $site->id)->where('slug', 'described-candle')->firstOrFail();

    expect($result->ok)->toBeTrue()
        ->and($result->data['created'])->toBe(2)
        ->and($warned->review_notes)->toBe(['duplicate_category', 'missing_description', 'missing_variant_label'])
        ->and($clean->review_notes)->toBeNull()
        ->and($result->data['results'][0])->toMatchArray([
            'status' => 'created',
            'name' => 'Bare Candle',
            'category' => 'candles',
            'price_pence' => 800,
            'warnings' => ['duplicate_category', 'missing_description', 'missing_variant_label'],
        ])
        ->and($result->data['results'][1]['warnings'])->toBe([]);
});

it('reports warnings on a dry run without writing anything', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $result = CommerceReads::run($actor, $site, 'import_products', reviewNotesImport([[
        'name' => 'Bare Candle',
        'primary_category_slug' => 'candles',
        'variants' => [['sku' => 'BC-1', 'price_pence' => 800]],
    ]], dryRun: true));

    expect($result->ok)->toBeTrue()
        ->and($result->data['results'][0]['warnings'])->toBe(['missing_description'])
        ->and(Product::query()->where('site_id', $site->id)->count())->toBe(0);
});

it('names a rejected row so the receipt reader can recognise it', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $result = CommerceReads::run($actor, $site, 'import_products', reviewNotesImport([[
        'name' => 'Lost Candle',
        'primary_category_slug' => 'nowhere',
        'variants' => [['sku' => 'LC-1', 'price_pence' => 800]],
    ]]));

    expect($result->data['results'][0])->toMatchArray([
        'status' => 'rejected',
        'name' => 'Lost Candle',
        'category' => null,
        'price_pence' => 800,
        'errors' => ['category_not_found'],
    ]);
});

it('drafts a row with no readable price at no price and notes price_missing, never inventing one', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $json = CommerceReads::run($actor, $site, 'import_products', reviewNotesImport([[
        'name' => 'Ask-price Candle',
        'slug' => 'ask-price-candle',
        'description' => 'Priced on request.',
        'primary_category_slug' => 'candles',
        'variants' => [['sku' => 'APC-1', 'price_pence' => null, 'label' => 'Each']],
    ]]));

    $csv = implode("\n", [
        'name,slug,sku,variant label,price,on hand,status,categories',
        'Smudged Candle,smudged-candle,SMC-1,Each,?,,draft,Candles',
        'Quoted Candle,quoted-candle,QC-1,Each,ask us,,draft,Candles',
        'Broken Candle,broken-candle,BRC-1,Each,5.5.5,,draft,Candles',
    ])."\n";
    $revision = (int) \App\Models\Shop\ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision');
    $fromCsv = CommerceReads::run($actor, $site, 'import_products', [
        ...reviewNotesImport([]),
        'format' => 'csv',
        'data' => $csv,
        'expected_revision' => $revision,
    ]);

    $asked = Product::query()->where('site_id', $site->id)->where('slug', 'ask-price-candle')->firstOrFail();
    $smudged = Product::query()->where('site_id', $site->id)->where('slug', 'smudged-candle')->firstOrFail();

    expect($json->ok)->toBeTrue()
        ->and($json->data['results'][0])->toMatchArray(['status' => 'created', 'price_pence' => null, 'warnings' => ['price_missing']])
        ->and($asked->review_notes)->toBe(['price_missing'])
        ->and($asked->variants()->value('price_cents'))->toBe(0)
        ->and($fromCsv->ok)->toBeTrue()
        ->and($fromCsv->data['created'])->toBe(2)
        ->and($fromCsv->data['failed'])->toBe(1)
        ->and($fromCsv->data['results'][0]['warnings'])->toContain('price_missing')
        ->and($fromCsv->data['results'][1]['warnings'])->toContain('price_missing')
        ->and($fromCsv->data['results'][2])->toMatchArray(['status' => 'rejected', 'errors' => ['bad_price']])
        ->and($smudged->review_notes)->toContain('price_missing')
        ->and(\App\Support\Shop\ProductReviewNotes::joined($smudged->review_notes))->toContain('No price');
});
