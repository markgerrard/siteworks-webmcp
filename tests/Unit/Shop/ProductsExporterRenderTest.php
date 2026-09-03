<?php

use App\Services\Shop\ProductsExporter;
use App\Services\Shop\StockService;
use Illuminate\Support\Collection;

/**
 * @return Collection<int, array<string, mixed>>
 */
function exporterFixture(): Collection
{
    return collect([
        [
            'name' => 'Scarlet Rose',
            'slug' => 'scarlet-rose',
            'status' => 'published',
            'categories' => [
                ['slug' => 'bouquets', 'name' => 'Bouquets', 'is_primary' => true],
            ],
            'images' => ['https://cdn.example/rose-1.jpg', 'https://cdn.example/rose-2.jpg'],
            'customer_inputs' => [['key' => 'note', 'label' => 'Card message', 'type' => 'text']],
            'variants' => [
                ['sku' => 'ROSE-STEM', 'label' => 'Stem', 'price_pence' => 1250, 'on_hand' => 7],
                ['sku' => 'ROSE-BUNCH', 'label' => 'Bunch', 'price_pence' => 3200, 'on_hand' => null],
            ],
        ],
        [
            'name' => 'No Variant Item',
            'slug' => 'no-variant-item',
            'status' => 'draft',
            'categories' => [],
            'images' => [],
            'customer_inputs' => [],
            'variants' => [],
        ],
    ]);
}

function exporter(): ProductsExporter
{
    return new ProductsExporter(new StockService);
}

it('renders the legacy 8-column csv exactly, per variant, with a blank row for a variant-less product', function () {
    $csv = exporter()->render(exporterFixture(), 'csv');

    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $csv);
    rewind($handle);
    $rows = [];
    while (($row = fgetcsv($handle, escape: '')) !== false) {
        $rows[] = $row;
    }
    fclose($handle);

    expect($rows[0])->toBe(['name', 'slug', 'sku', 'variant label', 'price', 'on hand', 'status', 'categories'])
        ->and($rows)->toHaveCount(4)
        ->and($rows[1])->toBe(['Scarlet Rose', 'scarlet-rose', 'ROSE-STEM', 'Stem', '12.50', '7', 'published', 'Bouquets'])
        ->and($rows[2])->toBe(['Scarlet Rose', 'scarlet-rose', 'ROSE-BUNCH', 'Bunch', '32.00', '', 'published', 'Bouquets'])
        ->and($rows[3])->toBe(['No Variant Item', 'no-variant-item', '', '', '', '', 'draft', '']);
});

it('prefixes a formula-like csv cell so it is not read as a formula', function () {
    $products = collect([[
        'name' => '=HYPERLINK("x")',
        'slug' => 'formula',
        'status' => 'published',
        'categories' => [],
        'images' => [],
        'customer_inputs' => [],
        'variants' => [['sku' => 'F-1', 'label' => null, 'price_pence' => 100, 'on_hand' => 0]],
    ]]);

    $csv = exporter()->render($products, 'csv');

    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $csv);
    rewind($handle);
    $rows = [];
    while (($row = fgetcsv($handle, escape: '')) !== false) {
        $rows[] = $row;
    }
    fclose($handle);

    expect($rows[1][0])->toBe('\'=HYPERLINK("x")');
});

it('renders json as a structured array carrying sku, price, category, images, and customer_inputs', function () {
    $json = exporter()->render(exporterFixture(), 'json');
    $decoded = json_decode($json, true);

    expect($decoded)->toHaveCount(2);

    $rose = $decoded[0];
    expect($rose['name'])->toBe('Scarlet Rose')
        ->and($rose['slug'])->toBe('scarlet-rose')
        ->and($rose['categories'][0]['slug'])->toBe('bouquets')
        ->and($rose['images'])->toContain('https://cdn.example/rose-1.jpg')
        ->and($rose['customer_inputs'][0]['key'])->toBe('note')
        ->and($rose['variants'][0]['sku'])->toBe('ROSE-STEM')
        ->and($rose['variants'][0]['price_pence'])->toBe(1250)
        ->and($rose['variants'][0]['on_hand'])->toBe(7)
        ->and($rose['variants'][1]['on_hand'])->toBeNull();
});

it('renders a markdown table with one row per product carrying sku, price, and category slug', function () {
    $md = exporter()->render(exporterFixture(), 'md');

    expect($md)->toStartWith('| Name | Slug | Status | Categories | SKUs | Price | On Hand | Images | Custom Inputs |')
        ->and(substr_count($md, "\n"))->toBeGreaterThanOrEqual(3)
        ->and($md)->toContain('Scarlet Rose')
        ->and($md)->toContain('scarlet-rose')
        ->and($md)->toContain('bouquets')
        ->and($md)->toContain('ROSE-STEM')
        ->and($md)->toContain('12.50')
        ->and($md)->toContain('No Variant Item');
});

it('maps format to mime and extension', function () {
    $exporter = exporter();

    expect($exporter->mime('csv'))->toBe('text/csv')
        ->and($exporter->mime('md'))->toBe('text/markdown')
        ->and($exporter->mime('json'))->toBe('application/json')
        ->and($exporter->extension('csv'))->toBe('csv')
        ->and($exporter->extension('md'))->toBe('md')
        ->and($exporter->extension('json'))->toBe('json');
});
