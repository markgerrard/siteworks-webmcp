<?php

use App\Enums\Shop\ProductStatus;
use App\Models\Shop\Product;
use App\Models\Shop\ShopDraft;
use App\Models\Site;
use App\Models\User;
use App\Services\Shop\ProductImportContract;
use App\Services\Shop\ProductImporter;
use Illuminate\Support\Facades\Storage;

/**
 * The sample price list is what the agent reads; it types the rows up as CSV
 * for import_products. These tests do that typing the way the tool contract
 * asks for it — names verbatim, unreadable prices left unreadable — and check
 * the import behaves as the protocol promises.
 */
beforeEach(function () {
    config()->set('demo.enabled', true);
    config()->set('demo.site_host', 'localhost');
    config()->set('demo.user_email', 'demo@camino.example');
    config()->set('demo.user_password', 'webmcp-demo');
    config()->set('app.url', 'http://app.localhost:8090');
    config()->set('filesystems.media', 'public');
    config()->set('filesystems.media_private', 'local');
    Storage::fake('s3');
    Storage::fake('public');
    Storage::fake('local');
    $this->artisan('demo:seed')->assertSuccessful();
});

/**
 * @return list<array{name: string, price: string}>
 */
function demoPriceListRows(): array
{
    $rows = [];
    foreach (file(base_path('demo/sample-inputs/price-list.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^(.*?)\s+\$(\S+)$/u', $line, $m) === 1) {
            $rows[] = ['name' => $m[1], 'price' => $m[2]];
        } elseif (preg_match('/^(.*?)\s+—\s+(.+)$/u', $line, $m) === 1) {
            $rows[] = ['name' => $m[1], 'price' => $m[2]];
        }
    }

    return $rows;
}

function demoPriceListCsv(): string
{
    $lines = ['name,slug,sku,variant label,price,on hand,status,categories'];
    foreach (demoPriceListRows() as $i => $row) {
        $lines[] = sprintf('"%s",,FALL-%d,,%s,,draft,Seasonal — Fall', str_replace('"', '""', $row['name']), $i + 1, $row['price']);
    }

    return implode("\n", $lines)."\n";
}

/**
 * @return array<string, mixed>
 */
function demoImportPriceList(bool $dryRun, string $key = 'price-list'): array
{
    $site = Site::query()->findOrFail(64);
    $user = User::query()->where('email', 'demo@camino.example')->firstOrFail();

    return app(ProductImporter::class)->run($site, [
        'schema_version' => ProductImportContract::SCHEMA_VERSION,
        'format' => 'csv',
        'data' => demoPriceListCsv(),
        'catalogue_revision' => (int) ShopDraft::query()->where('site_id', 64)->value('catalogue_revision'),
        'dry_run' => $dryRun,
        'idempotency_key' => $key,
    ], $user->id, true);
}

it('reads all nine rows off the sample price list, two of them without a usable price', function () {
    $rows = demoPriceListRows();

    expect($rows)->toHaveCount(9)
        ->and(collect($rows)->firstWhere('name', 'Pumpkin Praline Tart')['price'])->toBe('?')
        ->and(collect($rows)->firstWhere('name', 'Celebration Cake')['price'])->toBe('ask us');
});

it('drafts the unpriced rows at no price with a price_missing note instead of rejecting them', function () {
    $receipt = demoImportPriceList(dryRun: false);
    $byName = collect($receipt['results'])->keyBy('name');

    expect($byName['Pumpkin Praline Tart'])->toMatchArray(['status' => 'created', 'price_pence' => null])
        ->and($byName['Pumpkin Praline Tart']['warnings'])->toContain('price_missing')
        ->and($byName['Celebration Cake'])->toMatchArray(['status' => 'created', 'price_pence' => null])
        ->and($byName['Celebration Cake']['warnings'])->toContain('price_missing')
        ->and($byName['Pumpkin Spice Loaf'])->toMatchArray(['status' => 'created', 'price_pence' => 650])
        ->and($byName['Pumpkin Spice Loaf']['warnings'])->not->toContain('price_missing');

    $praline = Product::query()->where('site_id', 64)->where('slug', $byName['Pumpkin Praline Tart']['slug'])->firstOrFail();

    expect($praline->status)->toBe(ProductStatus::Draft)
        ->and($praline->review_notes)->toContain('price_missing')
        ->and($praline->variants()->value('price_cents'))->toBe(0);
});

it('matches Fig & Walnut Tart to the seeded product instead of creating a second one', function () {
    $preview = demoImportPriceList(dryRun: true);
    $fig = collect($preview['results'])->firstWhere('name', 'Fig & Walnut Tart');
    $seeded = Product::query()->where('site_id', 64)->where('slug', 'fig-walnut-tart')->firstOrFail();

    expect($fig)->toMatchArray(['status' => 'matched', 'slug' => 'fig-walnut-tart', 'product_id' => $seeded->id])
        ->and($fig['warnings'])->toContain('matches_existing')
        ->and($preview['matched'])->toBe(1)
        ->and($preview['created'])->toBe(8)
        ->and($preview['failed'])->toBe(0);

    $receipt = demoImportPriceList(dryRun: false);

    expect($receipt['created'])->toBe(8)
        ->and($receipt['matched'])->toBe(1)
        ->and(Product::query()->where('site_id', 64)->where('slug', 'like', 'fig-walnut-tart%')->count())->toBe(1)
        ->and(Product::query()->where('site_id', 64)->where('slug', 'fig-walnut-tart-2')->exists())->toBeFalse();
});

it('lists an imported product once published even though the price list carried no stock', function () {
    $receipt = demoImportPriceList(dryRun: false);
    $loaf = Product::query()->where('site_id', 64)->where('slug', collect($receipt['results'])->firstWhere('name', 'Pumpkin Spice Loaf')['slug'])->firstOrFail();
    $site = Site::query()->findOrFail(64);

    $written = app(\App\Services\Shop\ShopDraftWriter::class)->setStatusFromEditor($site, $loaf, ProductStatus::Published);
    ($written['deferred'])();

    $page = $this->get('http://localhost/products/'.$loaf->slug)->assertOk()->getContent();
    $collection = $this->get('http://localhost/collections/seasonal-fall')->assertOk()->getContent();

    expect($page)->toContain('>Add to list</button>')
        ->and($page)->not->toContain('Out of stock')
        ->and($collection)->toContain('Pumpkin Spice Loaf')
        ->and($collection)->toContain('data-shop-card-pill')
        ->and($collection)->not->toContain('Out of stock');
});

it('finds catalogue products by name through list_products q on the demo database', function () {
    config([
        'editor.operations.enabled' => true,
        'editor.agent_tools.enabled' => true,
        'editor.agent_tools.roles' => ['staff', 'client'],
        'editor.agent_tools.client_portal_enabled' => true,
    ]);
    $site = Site::query()->findOrFail(64);
    $user = User::query()->where('email', 'demo@camino.example')->firstOrFail();

    $result = app(\App\Services\Site\Editor\EditorOperations::class)->run(
        new \App\Services\Site\Editor\EditorContext($user, $site, \App\Services\Site\Editor\ActorChannel::Webmcp),
        'list_products',
        ['q' => 'tart', 'limit' => 10],
    );

    expect($result->ok)->toBeTrue()
        ->and(collect($result->data['products'])->pluck('slug')->all())
        ->toContain('fig-walnut-tart', 'meyer-lemon-tart', 'chocolate-caramel-tart')
        ->not->toContain('almond-croissant');
});

it('keeps an unpriced import draft off the storefront until a person sets its price', function () {
    $receipt = demoImportPriceList(dryRun: false);
    $site = Site::query()->findOrFail(64);
    $row = collect($receipt['results'])->firstWhere('name', 'Pumpkin Praline Tart');
    $praline = Product::query()->where('site_id', 64)->where('slug', $row['slug'])->firstOrFail();
    $writer = app(\App\Services\Shop\ShopDraftWriter::class);

    expect(fn () => $writer->setStatusFromEditor($site, $praline, ProductStatus::Published))
        ->toThrow(\App\Exceptions\Shop\ProductNotPublishableException::class, 'Set a price before publishing');
    expect(fn () => $writer->saveFromEditor($site, $praline, [
        'name' => $praline->name,
        'description' => (string) $praline->description,
        'tax_class_id' => $praline->tax_class_id,
        'status' => 'published',
    ]))->toThrow(\App\Exceptions\Shop\ProductNotPublishableException::class);

    expect($praline->fresh()->status)->toBe(ProductStatus::Draft)
        ->and($praline->fresh()->review_notes)->toContain('price_missing');
    $this->get('http://localhost/products/'.$praline->slug)->assertNotFound();
    expect($this->get('http://localhost/collections/seasonal-fall')->assertOk()->getContent())->not->toContain('Pumpkin Praline Tart');

    $sku = (string) $praline->variants()->value('sku');
    ($writer->updateDraft($site, $praline, ['variants' => [['sku' => $sku, 'price_cents' => 2800]]])['deferred'])();

    expect($praline->fresh()->review_notes ?? [])->not->toContain('price_missing');

    ($writer->setStatusFromEditor($site, $praline->fresh(), ProductStatus::Published)['deferred'])();

    $page = $this->get('http://localhost/products/'.$praline->slug)->assertOk()->getContent();
    expect($page)->toContain('Pumpkin Praline Tart')
        ->and($page)->toContain('$28');
});

it('gives an imported product the site\'s free-text inputs only, never a placeholder option chooser', function () {
    $receipt = demoImportPriceList(dryRun: false);
    $site = Site::query()->findOrFail(64);
    $loaf = Product::query()->where('site_id', 64)->where('slug', collect($receipt['results'])->firstWhere('name', 'Pumpkin Spice Loaf')['slug'])->firstOrFail();

    expect(collect($site->default_customer_inputs)->pluck('kind')->all())->toContain('choice')
        ->and(collect($loaf->customer_inputs)->pluck('kind')->all())->toBe(['text', 'textarea'])
        ->and(collect($loaf->customer_inputs)->pluck('label')->all())->toBe(['Note', 'Message']);

    (app(\App\Services\Shop\ShopDraftWriter::class)->setStatusFromEditor($site, $loaf, ProductStatus::Published)['deferred'])();

    $page = $this->get('http://localhost/products/'.$loaf->slug)->assertOk()->getContent();

    expect($page)->toContain('Note')
        ->and($page)->toContain('Message')
        ->and($page)->not->toContain('Option')
        ->and($page)->not->toMatch('/<legend[^>]*>\s*Option/')
        ->and($page)->not->toContain('value="One"');
});
