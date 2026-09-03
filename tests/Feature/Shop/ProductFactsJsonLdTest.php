<?php

use App\Support\Shop\ProductFacts;
use Tests\Support\ProductFactsFixtures;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('nutrition material size and ingredients map into Product JSON-LD only when values exist', function () {
    $fixture = ProductFactsFixtures::bakery();
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    ProductFactsFixtures::pdpSnapshot($site, $product, $product->facts);

    $html = $this->get('http://'.$site->custom_domain.'/products/'.$product->slug)->assertOk()->getContent();
    preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);
    $productLd = collect($matches[1])->map(fn (string $raw) => json_decode($raw, true))->firstWhere('@type', 'Product');

    expect($productLd['nutrition']['@type'])->toBe('NutritionInformation')
        ->and($productLd['nutrition']['calories'])->toBe('320 kcal')
        ->and($productLd['nutrition']['fatContent'])->toBe('12 g')
        ->and($productLd['nutrition']['carbohydrateContent'])->toBe('45 g')
        ->and($productLd['nutrition']['proteinContent'])->toBe('6 g')
        ->and($productLd['nutrition']['sugarContent'])->toBe('22 g')
        ->and($productLd['nutrition']['sodiumContent'])->toBe('0.4 g')
        ->and($productLd['nutrition']['servingSize'])->toBe('1 slice');

    $additional = $productLd['additionalProperty'] ?? [];
    if (isset($additional['@type'])) {
        $additional = [$additional];
    }
    expect(collect($additional)->pluck('name')->all())->toContain('Ingredients')
        ->and(collect($additional)->pluck('@type')->unique()->all())->toBe(['PropertyValue']);
});

test('unknown nutrition pair labels become additionalProperty', function () {
    $groups = ProductFacts::validateGroups([
        ['slug' => 'info', 'label' => 'Info', 'kind' => 'pairs', 'show_on_card' => false, 'schema' => 'nutrition'],
    ]);
    $payload = ProductFacts::applyJsonLd(['@type' => 'Product'], $groups, [
        'info' => ['pairs' => [
            ['label' => 'Calories', 'value' => '10'],
            ['label' => 'Fibre', 'value' => '2 g'],
        ]],
    ]);

    expect($payload['nutrition']['calories'])->toBe('10')
        ->and($payload['additionalProperty'])->toMatchArray([
            '@type' => 'PropertyValue',
            'name' => 'Fibre',
            'value' => '2 g',
        ]);
});

test('material and size groups map to Product properties', function () {
    $groups = ProductFacts::validateGroups([
        ['slug' => 'mat', 'label' => 'Materials', 'kind' => 'text', 'show_on_card' => false, 'schema' => 'material'],
        ['slug' => 'dim', 'label' => 'Size', 'kind' => 'pairs', 'show_on_card' => false, 'schema' => 'size'],
    ]);
    $payload = ProductFacts::applyJsonLd(['@type' => 'Product'], $groups, [
        'mat' => ['text' => 'Oak'],
        'dim' => ['pairs' => [['label' => 'Width', 'value' => '12 cm']]],
    ]);

    expect($payload['material'])->toBe('Oak')
        ->and($payload['size'])->toBe('12 cm');
});

test('schema-less groups emit additionalProperty', function () {
    $groups = ProductFacts::validateGroups([
        ['slug' => 'specs', 'label' => 'Specifications', 'kind' => 'pairs', 'show_on_card' => false, 'schema' => null],
        ['slug' => 'care', 'label' => 'Care', 'kind' => 'text', 'show_on_card' => false, 'schema' => null],
    ]);
    $payload = ProductFacts::applyJsonLd(['@type' => 'Product'], $groups, [
        'specs' => ['pairs' => [['label' => 'Width', 'value' => '12 cm']]],
        'care' => ['text' => 'Keep cool.'],
    ]);

    expect($payload['additionalProperty'])->toEqualCanonicalizing([
        ['@type' => 'PropertyValue', 'name' => 'Width', 'value' => '12 cm'],
        ['@type' => 'PropertyValue', 'name' => 'Care', 'value' => 'Keep cool.'],
    ]);
});

test('JSON-LD is unchanged when the product has no fact values', function () {
    $fixture = ProductFactsFixtures::bakery();
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    ProductFactsFixtures::pdpSnapshot($site, $product, []);

    $html = $this->get('http://'.$site->custom_domain.'/products/'.$product->slug)->assertOk()->getContent();
    preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);
    $productLd = collect($matches[1])->map(fn (string $raw) => json_decode($raw, true))->firstWhere('@type', 'Product');

    expect($productLd)->not->toHaveKey('nutrition')
        ->and($productLd)->not->toHaveKey('additionalProperty')
        ->and($productLd)->not->toHaveKey('material')
        ->and($productLd)->not->toHaveKey('size');
});
