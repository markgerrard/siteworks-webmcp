<?php

use App\Services\Shop\ProductImportContract;
use App\Services\Shop\ProductImportParser;
use App\Services\Site\Editor\CommerceOperations;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\Operations\DescribeImportProductsOperation;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
});

it('is a shop-addressed read with staff and client roles', function () {
    $operation = app(DescribeImportProductsOperation::class);

    expect($operation->name())->toBe('describe_import_products')
        ->and($operation->address())->toBe('shop')
        ->and($operation->readOnly())->toBeTrue()
        ->and($operation->allowedRoles())->toEqualCanonicalizing(['staff', 'client']);
});

it('returns schema_version 1, field lists, rules, limits, and per-format examples', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $result = CommerceReads::run($actor, $site, 'describe_import_products');

    expect($result->ok)->toBeTrue()
        ->and($result->data['schema_version'])->toBe(ProductImportContract::SCHEMA_VERSION)
        ->and($result->data['schema_version'])->toBe(1)
        ->and($result->data['mandatory_fields'])->toEqualCanonicalizing(['name', 'primary_category_slug', 'variants'])
        ->and($result->data['optional_fields'])->toEqualCanonicalizing([
            'slug', 'description', 'extra_category_slugs', 'tags', 'tax_class_code', 'customer_inputs', 'facts',
        ])
        ->and($result->data['rules'])->toContain('Imported products are always drafts. published, or status "published" on canonical JSON, CSV, MD, or export JSON, is rejected with published_not_accepted.')
        ->and($result->data['rules'])->toContain('primary_category_slug must already exist; categories are never auto-created.')
        ->and($result->data['limits']['max_products'])->toBe(200)
        ->and($result->data['limits']['max_bytes'])->toBe(262144)
        ->and($result->data['formats']['csv']['columns'])->toBe(ProductImportParser::CSV_COLUMNS)
        ->and($result->data['formats']['csv']['example'])->toBeString()->not->toBeEmpty()
        ->and($result->data['formats']['json']['example'])->toBeString()->not->toBeEmpty()
        ->and($result->data['formats']['md']['example'])->toBeString()->not->toBeEmpty();
});

it('uses examples that parse into at least one canonical product per format', function () {
    [$actor, $site] = CommerceReads::shopSite();
    $examples = CommerceReads::run($actor, $site, 'describe_import_products')->data['formats'];

    foreach (['csv', 'json', 'md'] as $format) {
        $parsed = ProductImportParser::parse($format, $examples[$format]['example']);
        expect($parsed)->not->toBeEmpty()
            ->and($parsed[0]['name'])->not->toBe('')
            ->and($parsed[0]['variants'])->not->toBeEmpty()
            ->and($parsed[0]['errors'] ?? [])->toBe([]);
    }
});

it('is on the sandbox exposure set and the client SANDBOX allowlist', function () {
    expect(config('editor.exposure.sets.sandbox'))->toContain('describe_import_products')
        ->and(config('editor.exposure.sets.internal'))->toContain('describe_import_products')
        ->and(CommerceOperations::SANDBOX)->toContain('describe_import_products')
        ->and(app(OperationRegistry::class)->has('describe_import_products'))->toBeTrue();
});
