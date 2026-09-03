<?php

use App\Enums\Shop\ProductStatus;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopDraft;
use App\Models\Shop\TaxClass;
use App\Models\Shop\VariantStock;
use App\Services\Shop\ProductImportContract;
use App\Services\Site\Editor\Operations\ImportProductsOperation;
use App\Support\Shop\ProductFacts;
use Database\Seeders\Shop\TaxClassSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
    $this->seed(TaxClassSeeder::class);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function importInput(array $overrides = []): array
{
    $product = $overrides['product'] ?? [
        'name' => 'Almond Croissant',
        'slug' => 'almond-croissant',
        'primary_category_slug' => 'candles',
        'variants' => [['sku' => 'AC-1', 'price_pence' => 800, 'label' => 'Each']],
    ];
    unset($overrides['product']);

    $format = $overrides['format'] ?? 'json';
    $data = $overrides['data'] ?? json_encode([$product], JSON_THROW_ON_ERROR);

    return array_merge([
        'schema_version' => ProductImportContract::SCHEMA_VERSION,
        'format' => $format,
        'data' => $data,
        'expected_revision' => 0,
        'dry_run' => false,
        'idempotency_key' => (string) Str::uuid(),
    ], $overrides);
}

it('is a shop-addressed write with staff and client roles mirroring draft_product', function () {
    $operation = app(ImportProductsOperation::class);
    $draft = app(\App\Services\Site\Editor\Operations\ShopDraftProductOperation::class);

    expect($operation->name())->toBe('import_products')
        ->and($operation->address())->toBe('shop')
        ->and($operation->readOnly())->toBeFalse()
        ->and($operation->wrapInAdminChange())->toBeFalse()
        ->and($operation->managesOwnRevision())->toBeTrue()
        ->and($draft->managesOwnRevision())->toBeFalse()
        ->and(\App\Services\Site\Editor\ExpectedRevision::requiresBase($operation))->toBeFalse()
        ->and(\App\Services\Site\Editor\ExpectedRevision::requiresBase($draft))->toBeTrue()
        ->and($operation->requiresApproval())->toBe($draft->requiresApproval())
        ->and($operation->allowedRoles())->toEqualCanonicalizing(['staff', 'client']);
});

it('creates a draft from canonical json and never publishes', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $result = CommerceReads::run($actor, $site, 'import_products', importInput());

    $product = Product::query()->where('site_id', $site->id)->where('slug', 'almond-croissant')->first();

    expect($result->ok)->toBeTrue()
        ->and($result->data['schema_version'])->toBe(1)
        ->and($result->data['created'])->toBe(1)
        ->and($result->data['failed'])->toBe(0)
        ->and($result->data['publishable'])->toBeFalse()
        ->and($result->data['new_revision'])->toBe(1)
        ->and($result->data['results'][0]['status'])->toBe('created')
        ->and($result->data['results'][0]['slug'])->toBe('almond-croissant')
        ->and($result->toArray()['receipt']['publishable'])->toBeFalse()
        ->and($product)->not->toBeNull()
        ->and($product->status)->toBe(ProductStatus::Draft)
        ->and($product->variants()->first()->price_cents)->toBe(800);
});

it('creates from csv and markdown adapters', function (string $format, string $data) {
    [$actor, $site] = CommerceReads::shopSite();

    $result = CommerceReads::run($actor, $site, 'import_products', importInput([
        'format' => $format,
        'data' => $data,
    ]));

    expect($result->ok)->toBeTrue()
        ->and($result->data['created'])->toBe(1)
        ->and(Product::query()->where('site_id', $site->id)->where('slug', 'almond-croissant')->exists())->toBeTrue();
})->with([
    'csv' => ['csv', ProductImportContract::csvExample()],
    'md' => ['md', ProductImportContract::mdExample()],
]);

it('persists variant label, weight, stock, extra categories, tags, tax class, customer_inputs and facts', function () {
    [$actor, $site, $category] = CommerceReads::shopSite();
    $extra = Category::factory()->for($site)->create(['slug' => 'breakfast', 'name' => 'Breakfast']);
    $site->update([
        'product_tags' => [
            ['slug' => 'seasonal', 'label' => 'Seasonal', 'show_as_badge' => true, 'tone' => 'warning'],
        ],
        'product_fact_groups' => ProductFacts::presetGroups('generic-specifications'),
    ]);

    $result = CommerceReads::run($actor, $site, 'import_products', importInput([
        'product' => [
            'name' => 'Almond Croissant',
            'slug' => 'almond-croissant',
            'description' => 'Frangipane filled.',
            'primary_category_slug' => 'candles',
            'extra_category_slugs' => ['breakfast'],
            'tags' => ['seasonal'],
            'tax_class_code' => 'standard',
            'variants' => [[
                'sku' => 'AC-1',
                'price_pence' => 800,
                'label' => 'Each',
                'weight_grams' => 95,
                'stock' => 4,
            ]],
            'customer_inputs' => [[
                'kind' => 'text',
                'slug' => 'note',
                'label' => 'Card message',
                'required' => false,
                'help' => '',
            ]],
            'facts' => ['details' => ['text' => 'Baked daily.']],
        ],
    ]));

    $product = Product::query()->where('site_id', $site->id)->first();
    $variant = $product->variants()->first();

    expect($result->ok)->toBeTrue()
        ->and($product->description)->toBe('Frangipane filled.')
        ->and($product->tags)->toBe(['seasonal'])
        ->and($product->tax_class_id)->toBe(TaxClass::query()->where('code', 'standard')->value('id'))
        ->and($product->customer_inputs[0]['slug'])->toBe('note')
        ->and($product->facts['details']['text'])->toBe('Baked daily.')
        ->and($variant->label)->toBe('Each')
        ->and($variant->weight_grams)->toBe(95)
        ->and((int) VariantStock::query()->where('variant_id', $variant->id)->value('on_hand'))->toBe(4)
        ->and($product->categories()->wherePivot('is_primary', true)->first()->id)->toBe($category->id)
        ->and($product->categories()->wherePivot('is_primary', false)->first()->id)->toBe($extra->id);
});

it('parses a decimal pound string from csv into integer pence', function () {
    [$actor, $site] = CommerceReads::shopSite();
    $csv = ProductImportContract::csvExample();

    $result = CommerceReads::run($actor, $site, 'import_products', importInput([
        'format' => 'csv',
        'data' => $csv,
    ]));

    $prices = Product::query()->where('site_id', $site->id)->first()->variants()->orderBy('sku')->pluck('price_cents')->all();

    expect($result->ok)->toBeTrue()
        ->and($prices)->toBe([800, 4200]);
});

it('rejects csv and non-canonical json status published with published_not_accepted', function (string $format, string $data) {
    [$actor, $site] = CommerceReads::shopSite();

    $result = CommerceReads::run($actor, $site, 'import_products', importInput([
        'format' => $format,
        'data' => $data,
    ]));

    expect($result->ok)->toBeTrue()
        ->and($result->data['created'])->toBe(0)
        ->and($result->data['failed'])->toBe(1)
        ->and($result->data['results'][0]['status'])->toBe('rejected')
        ->and($result->data['results'][0]['errors'])->toContain('published_not_accepted')
        ->and(Product::query()->where('site_id', $site->id)->exists())->toBeFalse();
})->with([
    'canonical-status' => ['json', json_encode([
        [
            'name' => 'Live Thing',
            'slug' => 'live-thing',
            'primary_category_slug' => 'candles',
            'status' => 'published',
            'variants' => [['sku' => 'LIVE-1', 'price_pence' => 100]],
        ],
    ], JSON_THROW_ON_ERROR)],
    'csv-status' => ['csv', implode("\n", [
        'name,slug,sku,variant label,price,on hand,status,categories',
        'Live Thing,live-thing,LIVE-1,Each,1.00,1,published,Candles',
        '',
    ])],
]);

it('rejects a canonical published key per row and still creates valid neighbours', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $result = CommerceReads::run($actor, $site, 'import_products', importInput([
        'data' => json_encode([
            [
                'name' => 'Live Thing',
                'primary_category_slug' => 'candles',
                'published' => true,
                'variants' => [['sku' => 'LIVE-1', 'price_pence' => 100]],
            ],
            [
                'name' => 'Draft Thing',
                'slug' => 'draft-thing',
                'primary_category_slug' => 'candles',
                'variants' => [['sku' => 'DRFT-1', 'price_pence' => 200]],
            ],
        ], JSON_THROW_ON_ERROR),
    ]));

    expect($result->ok)->toBeTrue()
        ->and($result->data['created'])->toBe(1)
        ->and($result->data['failed'])->toBe(1)
        ->and($result->data['results'][0]['status'])->toBe('rejected')
        ->and($result->data['results'][0]['errors'])->toContain('published_not_accepted')
        ->and($result->data['results'][1]['status'])->toBe('created')
        ->and(Product::query()->where('site_id', $site->id)->count())->toBe(1)
        ->and(Product::query()->where('slug', 'draft-thing')->exists())->toBeTrue();
});

it('rejects missing name, unknown category, bad sku and bad price per row while committing valid rows', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $result = CommerceReads::run($actor, $site, 'import_products', importInput([
        'data' => json_encode([
            ['primary_category_slug' => 'candles', 'variants' => [['sku' => 'OK-1', 'price_pence' => 100]]],
            ['name' => 'No Cat', 'primary_category_slug' => 'nope', 'variants' => [['sku' => 'NC-1', 'price_pence' => 100]]],
            ['name' => 'Bad Sku', 'primary_category_slug' => 'candles', 'variants' => [['sku' => 'bad sku', 'price_pence' => 100]]],
            ['name' => 'Bad Price', 'primary_category_slug' => 'candles', 'variants' => [['sku' => 'BP-1', 'price_pence' => 0]]],
            ['name' => 'Dup Sku', 'primary_category_slug' => 'candles', 'variants' => [
                ['sku' => 'DUP-1', 'price_pence' => 100],
                ['sku' => 'DUP-1', 'price_pence' => 200],
            ]],
            ['name' => 'Keeper', 'slug' => 'keeper', 'primary_category_slug' => 'candles', 'variants' => [['sku' => 'KEEP-1', 'price_pence' => 150]]],
        ], JSON_THROW_ON_ERROR),
    ]));

    $codes = collect($result->data['results'])->map(fn (array $row): array => [$row['status'], $row['errors'] ?? []])->all();

    expect($result->ok)->toBeTrue()
        ->and($result->data['created'])->toBe(1)
        ->and($result->data['failed'])->toBe(5)
        ->and($codes[0][1])->toContain('missing_name')
        ->and($codes[1][1])->toContain('category_not_found')
        ->and($codes[2][1])->toContain('bad_sku')
        ->and($codes[3][1])->toContain('bad_price')
        ->and($codes[4][1])->toContain('duplicate_sku')
        ->and($codes[5][0])->toBe('created')
        ->and(Product::query()->where('site_id', $site->id)->count())->toBe(1);
});

it('rolls back only the failing write via savepoint so a colliding slug does not lose a valid neighbour', function () {
    [$actor, $site] = CommerceReads::shopSite();
    Product::factory()->for($site)->create(['slug' => 'taken', 'name' => 'Existing']);

    $result = CommerceReads::run($actor, $site, 'import_products', importInput([
        'data' => json_encode([
            ['name' => 'Taken', 'slug' => 'taken', 'primary_category_slug' => 'candles', 'variants' => [['sku' => 'TKN-1', 'price_pence' => 100]]],
            ['name' => 'Fresh', 'slug' => 'fresh', 'primary_category_slug' => 'candles', 'variants' => [['sku' => 'FRSH-1', 'price_pence' => 200]]],
        ], JSON_THROW_ON_ERROR),
    ]));

    expect($result->ok)->toBeTrue()
        ->and($result->data['created'])->toBe(1)
        ->and($result->data['failed'])->toBe(1)
        ->and($result->data['results'][0]['errors'])->toContain('slug_taken')
        ->and(Product::query()->where('site_id', $site->id)->where('slug', 'fresh')->exists())->toBeTrue()
        ->and(Product::query()->where('site_id', $site->id)->where('name', 'Taken')->exists())->toBeFalse();
});

it('returns revision_conflict and writes nothing when expected_revision is stale', function () {
    [$actor, $site] = CommerceReads::shopSite();
    ShopDraft::query()->insertOrIgnore([[
        'site_id' => $site->id,
        'catalogue_revision' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]]);
    ShopDraft::query()->where('site_id', $site->id)->update(['catalogue_revision' => 3]);

    $result = CommerceReads::run($actor, $site, 'import_products', importInput([
        'expected_revision' => 0,
    ]));

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('revision_conflict')
        ->and($result->error['current_catalogue_revision'] ?? null)->toBe(3)
        ->and(Product::query()->where('site_id', $site->id)->exists())->toBeFalse();
});

it('returns the original receipt when a retry repeats the original idempotency_key and catalogue revision', function () {
    [$actor, $site] = CommerceReads::shopSite();
    $key = 'import-stale-rev-'.Str::uuid();
    $originalRevision = 0;

    $first = CommerceReads::run($actor, $site, 'import_products', importInput([
        'idempotency_key' => $key,
        'expected_revision' => $originalRevision,
    ]));

    expect($first->ok)->toBeTrue()
        ->and($first->data['new_revision'])->toBe(1)
        ->and($first->data['results'][0]['status'])->toBe('created')
        ->and((int) ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision'))->toBe(1);

    $retry = CommerceReads::run($actor, $site, 'import_products', importInput([
        'idempotency_key' => $key,
        'expected_revision' => $originalRevision,
        'product' => [
            'name' => 'Should Not Create',
            'slug' => 'should-not-create-on-retry',
            'primary_category_slug' => 'candles',
            'variants' => [['sku' => 'NOPE-R', 'price_pence' => 100]],
        ],
    ]));

    $staleOtherKey = CommerceReads::run($actor, $site, 'import_products', importInput([
        'idempotency_key' => 'other-'.$key,
        'expected_revision' => $originalRevision,
        'product' => [
            'name' => 'Also Not Created',
            'slug' => 'also-not-created',
            'primary_category_slug' => 'candles',
            'variants' => [['sku' => 'NOPE-S', 'price_pence' => 100]],
        ],
    ]));

    expect($retry->ok)->toBeTrue()
        ->and($retry->data['schema_version'])->toBe($first->data['schema_version'])
        ->and($retry->data['created'])->toBe($first->data['created'])
        ->and($retry->data['failed'])->toBe($first->data['failed'])
        ->and($retry->data['new_revision'])->toBe($first->data['new_revision'])
        ->and($retry->data['idempotency_key'])->toBe($key)
        ->and($retry->data['results'])->toEqual($first->data['results'])
        ->and($staleOtherKey->ok)->toBeFalse()
        ->and($staleOtherKey->error['code'])->toBe('revision_conflict')
        ->and(Product::query()->where('site_id', $site->id)->count())->toBe(1)
        ->and(Product::query()->where('slug', 'should-not-create-on-retry')->exists())->toBeFalse()
        ->and(Product::query()->where('slug', 'also-not-created')->exists())->toBeFalse()
        ->and((int) ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision'))->toBe(1);
});

it('returns the original receipt on an idempotency_key retry and does not duplicate products', function () {
    [$actor, $site] = CommerceReads::shopSite();
    $key = 'import-once-'.Str::uuid();

    $first = CommerceReads::run($actor, $site, 'import_products', importInput(['idempotency_key' => $key]));
    $second = CommerceReads::run($actor, $site, 'import_products', importInput([
        'idempotency_key' => $key,
        'expected_revision' => $first->data['new_revision'],
        'product' => [
            'name' => 'Should Not Create',
            'slug' => 'should-not-create',
            'primary_category_slug' => 'candles',
            'variants' => [['sku' => 'NOPE-1', 'price_pence' => 100]],
        ],
    ]));

    expect($first->ok)->toBeTrue()
        ->and($second->ok)->toBeTrue()
        ->and($second->data['schema_version'])->toBe($first->data['schema_version'])
        ->and($second->data['created'])->toBe($first->data['created'])
        ->and($second->data['failed'])->toBe($first->data['failed'])
        ->and($second->data['new_revision'])->toBe($first->data['new_revision'])
        ->and($second->data['idempotency_key'])->toBe($first->data['idempotency_key'])
        ->and($second->data['results'])->toEqual($first->data['results'])
        ->and(Product::query()->where('site_id', $site->id)->count())->toBe(1)
        ->and(Product::query()->where('slug', 'should-not-create')->exists())->toBeFalse();
});

it('dry_run writes nothing, does not bump revision, and returns a plan_token', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $result = CommerceReads::run($actor, $site, 'import_products', importInput([
        'dry_run' => true,
    ]));

    expect($result->ok)->toBeTrue()
        ->and($result->data['created'])->toBe(1)
        ->and($result->data['results'][0]['status'])->toBe('created')
        ->and($result->data['results'][0])->not->toHaveKey('product_id')
        ->and($result->data['plan_token'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($result->data['new_revision'])->toBe(0)
        ->and(Product::query()->where('site_id', $site->id)->exists())->toBeFalse()
        ->and((int) ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision'))->toBe(0);
});

it('refuses a commit whose plan_token no longer matches the payload or categories', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $dry = CommerceReads::run($actor, $site, 'import_products', importInput([
        'dry_run' => true,
        'idempotency_key' => null,
    ]));
    $token = $dry->data['plan_token'];

    $payloadChanged = CommerceReads::run($actor, $site, 'import_products', importInput([
        'plan_token' => $token,
        'product' => [
            'name' => 'Changed',
            'slug' => 'changed',
            'primary_category_slug' => 'candles',
            'variants' => [['sku' => 'CHG-1', 'price_pence' => 100]],
        ],
    ]));

    Category::query()->where('site_id', $site->id)->where('slug', 'candles')->update(['slug' => 'candles-renamed']);

    $categoryChanged = CommerceReads::run($actor, $site, 'import_products', importInput([
        'plan_token' => $token,
    ]));

    expect($payloadChanged->ok)->toBeFalse()
        ->and($payloadChanged->error['code'])->toBe('plan_stale')
        ->and($categoryChanged->ok)->toBeFalse()
        ->and($categoryChanged->error['code'])->toBe('plan_stale')
        ->and(Product::query()->where('site_id', $site->id)->exists())->toBeFalse();
});

it('rejects an unknown schema_version, oversized payload, and more than 200 products as whole-op errors', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $unknown = CommerceReads::run($actor, $site, 'import_products', importInput([
        'schema_version' => 2,
    ]));

    $tooBig = CommerceReads::run($actor, $site, 'import_products', importInput([
        'data' => str_repeat('a', ProductImportContract::MAX_BYTES + 1),
    ]));

    $products = [];
    for ($i = 0; $i < 201; $i++) {
        $products[] = [
            'name' => 'P'.$i,
            'primary_category_slug' => 'candles',
            'variants' => [['sku' => 'SKU-'.$i, 'price_pence' => 100]],
        ];
    }
    $tooMany = CommerceReads::run($actor, $site, 'import_products', importInput([
        'data' => json_encode($products, JSON_THROW_ON_ERROR),
    ]));

    expect($unknown->ok)->toBeFalse()
        ->and($unknown->error['code'])->toBe('validation')
        ->and($unknown->error['message'])->toContain('schema_version')
        ->and($tooBig->ok)->toBeFalse()
        ->and($tooBig->error['code'])->toBe('validation')
        ->and($tooMany->ok)->toBeFalse()
        ->and($tooMany->error['code'])->toBe('validation')
        ->and(Product::query()->where('site_id', $site->id)->exists())->toBeFalse();
});

it('requires idempotency_key on commit and increments catalogue revision once for a batch', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $missing = CommerceReads::run($actor, $site, 'import_products', importInput([
        'idempotency_key' => null,
    ]));

    $ok = CommerceReads::run($actor, $site, 'import_products', importInput([
        'data' => json_encode([
            ['name' => 'One', 'slug' => 'one', 'primary_category_slug' => 'candles', 'variants' => [['sku' => 'ONE-1', 'price_pence' => 100]]],
            ['name' => 'Two', 'slug' => 'two', 'primary_category_slug' => 'candles', 'variants' => [['sku' => 'TWO-1', 'price_pence' => 200]]],
        ], JSON_THROW_ON_ERROR),
    ]));

    expect($missing->ok)->toBeFalse()
        ->and($missing->error['code'])->toBe('validation')
        ->and($ok->ok)->toBeTrue()
        ->and($ok->data['created'])->toBe(2)
        ->and($ok->data['new_revision'])->toBe(1)
        ->and((int) ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision'))->toBe(1);
});

it('dispatches exactly one snapshot rebuild after a successful commit and none on dry_run', function () {
    [$actor, $site] = CommerceReads::shopSite();
    Bus::fake();

    CommerceReads::run($actor, $site, 'import_products', importInput(['dry_run' => true]));
    Bus::assertNothingDispatched();

    CommerceReads::run($actor, $site, 'import_products', importInput([
        'data' => json_encode([
            ['name' => 'One', 'slug' => 'one', 'primary_category_slug' => 'candles', 'variants' => [['sku' => 'ONE-1', 'price_pence' => 100]]],
            ['name' => 'Two', 'slug' => 'two', 'primary_category_slug' => 'candles', 'variants' => [['sku' => 'TWO-1', 'price_pence' => 200]]],
        ], JSON_THROW_ON_ERROR),
    ]));

    Bus::assertDispatchedTimes(RebuildShopSnapshot::class, 1);
});

it('lets describe_import_products examples pass import_products dry_run', function () {
    [$actor, $site] = CommerceReads::shopSite();
    $described = CommerceReads::run($actor, $site, 'describe_import_products')->data['formats'];

    foreach (['csv', 'json', 'md'] as $format) {
        $result = CommerceReads::run($actor, $site, 'import_products', importInput([
            'format' => $format,
            'data' => $described[$format]['example'],
            'dry_run' => true,
        ]));

        expect($result->ok)->toBeTrue()
            ->and($result->data['failed'])->toBe(0)
            ->and($result->data['created'])->toBeGreaterThan(0);
    }

    expect(Product::query()->where('site_id', $site->id)->exists())->toBeFalse();
});
