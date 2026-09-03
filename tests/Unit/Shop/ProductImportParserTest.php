<?php

use App\Services\Shop\MoneyPence;
use App\Services\Shop\ProductImportParser;
use App\Services\Shop\ProductsExporter;
use App\Services\Shop\StockService;

it('parses a decimal pound string to integer pence without float arithmetic', function () {
    expect(MoneyPence::fromDecimalPounds('8.00'))->toBe(800)
        ->and(MoneyPence::fromDecimalPounds('8'))->toBe(800)
        ->and(MoneyPence::fromDecimalPounds('12.50'))->toBe(1250)
        ->and(MoneyPence::fromDecimalPounds('0.01'))->toBe(1)
        ->and(MoneyPence::fromDecimalPounds('100000.00'))->toBe(10000000);
});

it('rejects non-decimal and out-of-range pound strings', function (string $value) {
    expect(fn () => MoneyPence::fromDecimalPounds($value))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => '',
    'letters' => 'abc',
    'three-dp' => '8.001',
    'negative' => '-1.00',
    'zero' => '0.00',
    'too-big' => '100000.01',
    'comma' => '8,00',
]);

it('groups export csv variant rows into products by slug', function () {
    $csv = <<<'CSV'
name,slug,sku,variant label,price,on hand,status,categories
Almond Croissant,almond-croissant,AC-1,Each,8.00,4,published,Pastries
Almond Croissant,almond-croissant,AC-6,Half dozen,42.00,2,published,Pastries
Pain au Chocolat,pain-au-choc,PAC-1,Each,3.50,10,draft,"Pastries, Viennoiserie"
CSV;

    $parsed = ProductImportParser::parse('csv', $csv);

    expect($parsed)->toHaveCount(2);

    $croissant = $parsed[0];
    expect($croissant['source_row'])->toBe(2)
        ->and($croissant['name'])->toBe('Almond Croissant')
        ->and($croissant['slug'])->toBe('almond-croissant')
        ->and($croissant['primary_category'])->toBe(['by' => 'name', 'value' => 'Pastries'])
        ->and($croissant['extra_categories'])->toBe([])
        ->and($croissant['variants'])->toHaveCount(2)
        ->and($croissant['variants'][0])->toMatchArray([
            'sku' => 'AC-1',
            'label' => 'Each',
            'price_pence' => 800,
            'stock' => 4,
        ])
        ->and($croissant['variants'][1])->toMatchArray([
            'sku' => 'AC-6',
            'label' => 'Half dozen',
            'price_pence' => 4200,
            'stock' => 2,
        ])
        ->and($croissant)->not->toHaveKey('status')
        ->and($croissant)->not->toHaveKey('published')
        ->and($croissant['errors'])->toContain('published_not_accepted');

    $pain = $parsed[1];
    expect($pain['source_row'])->toBe(4)
        ->and($pain['primary_category'])->toBe(['by' => 'name', 'value' => 'Pastries'])
        ->and($pain['extra_categories'])->toBe([['by' => 'name', 'value' => 'Viennoiserie']])
        ->and($pain['variants'][0]['price_pence'])->toBe(350)
        ->and($pain['variants'][0]['stock'])->toBe(10);
});

it('parses export json including multi-variant products and drops status', function () {
    $json = (new ProductsExporter(new StockService))->render(collect([
        [
            'name' => 'Scarlet Rose',
            'slug' => 'scarlet-rose',
            'status' => 'published',
            'categories' => [
                ['slug' => 'bouquets', 'name' => 'Bouquets', 'is_primary' => true],
                ['slug' => 'gifts', 'name' => 'Gifts', 'is_primary' => false],
            ],
            'images' => ['https://cdn.example/rose.jpg'],
            'customer_inputs' => [
                ['kind' => 'text', 'slug' => 'note', 'label' => 'Card message', 'required' => false],
            ],
            'variants' => [
                ['sku' => 'ROSE-STEM', 'label' => 'Stem', 'price_pence' => 1250, 'on_hand' => 7],
                ['sku' => 'ROSE-BUNCH', 'label' => 'Bunch', 'price_pence' => 3200, 'on_hand' => null],
            ],
        ],
    ]), 'json');

    $parsed = ProductImportParser::parse('json', $json);

    expect($parsed)->toHaveCount(1)
        ->and($parsed[0]['name'])->toBe('Scarlet Rose')
        ->and($parsed[0]['slug'])->toBe('scarlet-rose')
        ->and($parsed[0]['primary_category'])->toBe(['by' => 'slug', 'value' => 'bouquets'])
        ->and($parsed[0]['extra_categories'])->toBe([['by' => 'slug', 'value' => 'gifts']])
        ->and($parsed[0]['customer_inputs'][0]['slug'])->toBe('note')
        ->and($parsed[0]['variants'])->toHaveCount(2)
        ->and($parsed[0]['variants'][0]['price_pence'])->toBe(1250)
        ->and($parsed[0]['variants'][0]['stock'])->toBe(7)
        ->and($parsed[0]['variants'][1]['stock'])->toBeNull()
        ->and($parsed[0])->not->toHaveKey('status')
        ->and($parsed[0])->not->toHaveKey('published')
        ->and($parsed[0])->not->toHaveKey('images')
        ->and($parsed[0]['errors'])->toContain('published_not_accepted');
});

it('parses canonical json objects that already use primary_category_slug', function () {
    $json = json_encode([
        [
            'name' => 'Almond Croissant',
            'slug' => 'almond-croissant',
            'description' => 'Frangipane filled.',
            'primary_category_slug' => 'pastries',
            'extra_category_slugs' => ['breakfast'],
            'tags' => ['seasonal'],
            'tax_class_code' => 'standard',
            'variants' => [
                ['sku' => 'AC-1', 'price_pence' => 800, 'label' => 'Each', 'weight_grams' => 95, 'stock' => 4],
            ],
            'facts' => ['details' => ['text' => 'Baked daily.']],
        ],
    ], JSON_THROW_ON_ERROR);

    $parsed = ProductImportParser::parse('json', $json);

    expect($parsed[0]['description'])->toBe('Frangipane filled.')
        ->and($parsed[0]['primary_category'])->toBe(['by' => 'slug', 'value' => 'pastries'])
        ->and($parsed[0]['extra_categories'])->toBe([['by' => 'slug', 'value' => 'breakfast']])
        ->and($parsed[0]['tags'])->toBe(['seasonal'])
        ->and($parsed[0]['tax_class_code'])->toBe('standard')
        ->and($parsed[0]['variants'][0]['weight_grams'])->toBe(95)
        ->and($parsed[0]['facts']['details']['text'])->toBe('Baked daily.');
});

it('parses export markdown zipping comma-joined sku/price/on-hand into variants', function () {
    $md = (new ProductsExporter(new StockService))->render(collect([
        [
            'name' => 'Scarlet Rose',
            'slug' => 'scarlet-rose',
            'status' => 'published',
            'categories' => [
                ['slug' => 'bouquets', 'name' => 'Bouquets', 'is_primary' => true],
                ['slug' => 'gifts', 'name' => 'Gifts', 'is_primary' => false],
            ],
            'images' => [],
            'customer_inputs' => [],
            'variants' => [
                ['sku' => 'ROSE-STEM', 'label' => 'Stem', 'price_pence' => 1250, 'on_hand' => 7],
                ['sku' => 'ROSE-BUNCH', 'label' => 'Bunch', 'price_pence' => 3200, 'on_hand' => 2],
            ],
        ],
    ]), 'md');

    $parsed = ProductImportParser::parse('md', $md);

    expect($parsed)->toHaveCount(1)
        ->and($parsed[0]['name'])->toBe('Scarlet Rose')
        ->and($parsed[0]['primary_category'])->toBe(['by' => 'slug', 'value' => 'bouquets'])
        ->and($parsed[0]['extra_categories'])->toBe([['by' => 'slug', 'value' => 'gifts']])
        ->and($parsed[0]['variants'])->toEqual([
            ['sku' => 'ROSE-STEM', 'label' => null, 'price_pence' => 1250, 'stock' => 7, 'weight_grams' => null],
            ['sku' => 'ROSE-BUNCH', 'label' => null, 'price_pence' => 3200, 'stock' => 2, 'weight_grams' => null],
        ])
        ->and($parsed[0])->not->toHaveKey('status')
        ->and($parsed[0]['errors'])->toContain('published_not_accepted');
});

it('strips the csv formula prefix so a re-imported export name is clean', function () {
    $csv = <<<'CSV'
name,slug,sku,variant label,price,on hand,status,categories
'=HYPERLINK("x"),formula,F-1,Default,1.00,0,draft,Pastries
CSV;

    $parsed = ProductImportParser::parse('csv', $csv);

    expect($parsed[0]['name'])->toBe('=HYPERLINK("x")');
});

it('accepts a json wrapper object with a products array', function () {
    $json = json_encode([
        'products' => [
            [
                'name' => 'Tea',
                'primary_category_slug' => 'drinks',
                'variants' => [['sku' => 'TEA-1', 'price_pence' => 250]],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $parsed = ProductImportParser::parse('json', $json);

    expect($parsed)->toHaveCount(1)
        ->and($parsed[0]['name'])->toBe('Tea');
});

it('throws on unparseable json, unknown format, and malformed csv header', function (string $format, string $data) {
    expect(fn () => ProductImportParser::parse($format, $data))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'bad-json' => ['json', '{nope'],
    'unknown-format' => ['xlsx', 'a,b'],
    'csv-wrong-header' => ['csv', "foo,bar\n1,2\n"],
    'empty' => ['json', ''],
]);

it('marks a canonical product that carries published or status as a row-level reject, without applying it', function () {
    $json = json_encode([
        [
            'name' => 'Live Thing',
            'primary_category_slug' => 'pastries',
            'published' => true,
            'variants' => [['sku' => 'LIVE-1', 'price_pence' => 100]],
        ],
    ], JSON_THROW_ON_ERROR);

    $parsed = ProductImportParser::parse('json', $json);

    expect($parsed[0]['errors'])->toContain('published_not_accepted')
        ->and($parsed[0])->not->toHaveKey('published');
});

it('marks canonical json status published the same as csv and non-canonical export json', function (string $format, string $data) {
    $parsed = ProductImportParser::parse($format, $data);

    expect($parsed[0]['errors'])->toContain('published_not_accepted')
        ->and($parsed[0])->not->toHaveKey('status')
        ->and($parsed[0])->not->toHaveKey('published');
})->with([
    'canonical-status' => ['json', json_encode([
        [
            'name' => 'Live Thing',
            'slug' => 'live-thing',
            'primary_category_slug' => 'pastries',
            'status' => 'published',
            'variants' => [['sku' => 'LIVE-1', 'price_pence' => 100]],
        ],
    ], JSON_THROW_ON_ERROR)],
    'csv-status' => ['csv', implode("\n", [
        'name,slug,sku,variant label,price,on hand,status,categories',
        'Live Thing,live-thing,LIVE-1,Each,1.00,1,published,Pastries',
        '',
    ])],
    'export-json-status' => ['json', json_encode([
        [
            'name' => 'Live Thing',
            'slug' => 'live-thing',
            'status' => 'published',
            'categories' => [
                ['slug' => 'pastries', 'name' => 'Pastries', 'is_primary' => true],
            ],
            'variants' => [['sku' => 'LIVE-1', 'label' => 'Each', 'price_pence' => 100]],
        ],
    ], JSON_THROW_ON_ERROR)],
]);

it('parses a price the source cannot state as null and a malformed number as bad_price', function () {
    $csv = implode("\n", [
        'name,slug,sku,variant label,price,on hand,status,categories',
        'Smudged,smudged,SM-1,Each,?,,draft,Candles',
        'Quoted,quoted,QU-1,Each,ask us,,draft,Candles',
        'Blank,blank,BL-1,Each,,,draft,Candles',
        'Broken,broken,BR-1,Each,5.5.5,,draft,Candles',
    ])."\n";

    $parsed = ProductImportParser::parse('csv', $csv);

    expect($parsed[0]['variants'][0]['price_pence'])->toBeNull()
        ->and($parsed[0]['errors'])->toBe([])
        ->and($parsed[1]['variants'][0]['price_pence'])->toBeNull()
        ->and($parsed[1]['errors'])->toBe([])
        ->and($parsed[2]['variants'][0]['price_pence'])->toBeNull()
        ->and($parsed[2]['errors'])->toBe([])
        ->and($parsed[3]['variants'][0]['price_pence'])->toBeNull()
        ->and($parsed[3]['errors'])->toBe(['bad_price']);

    $md = implode("\n", [
        '| Name | Slug | Status | Categories | SKUs | Price | On Hand | Images | Custom Inputs |',
        '|---|---|---|---|---|---|---|---|---|',
        '| Smudged | smudged | draft | candles | SM-1 | ? |  |  |  |',
    ])."\n";
    $json = json_encode([['name' => 'Quoted', 'primary_category_slug' => 'candles', 'variants' => [['sku' => 'QU-1', 'price_pence' => null]]]]);

    expect(ProductImportParser::parse('md', $md)[0]['variants'][0]['price_pence'])->toBeNull()
        ->and(ProductImportParser::parse('json', $json)[0]['variants'][0]['price_pence'])->toBeNull()
        ->and(ProductImportParser::parse('json', $json)[0]['errors'])->toBe([]);
});
