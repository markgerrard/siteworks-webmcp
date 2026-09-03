<?php

use App\Services\Site\PageLayoutRegistry;
use App\Services\Site\SectionSchema;

test('variant resolves to the registered variant enum for a file-backed family', function () {
    $rules = app(SectionSchema::class)->resolveField('services', 'variant');
    expect($rules['type'])->toBe('enum')
        ->and($rules['values'])->toContain('featured-ledger', 'numbered-rows', 'classic');
});

test('variant validates fail-closed', function () {
    $schema = app(SectionSchema::class);
    expect($schema->validateField('services', 'variant', 'featured-ledger'))->toBe([])
        ->and($schema->validateField('services', 'variant', null))->toBe([])
        ->and($schema->validateField('services', 'variant', 'nope'))->not->toBe([]);
});

test('inline registry families without a site_sections schema resolve their variant enum', function () {
    $schema = app(SectionSchema::class);
    $rules = $schema->resolveField('reviews_summary', 'variant');

    expect($rules)->not->toBeNull()
        ->and($rules['type'])->toBe('enum')
        ->and($rules['nullable'])->toBeTrue()
        ->and($rules['values'])->toEqualCanonicalizing(['grid', 'carousel'])
        ->and($schema->validateField('reviews_summary', 'variant', 'grid'))->toBe([])
        ->and($schema->validateField('reviews_summary', 'variant', null))->toBe([])
        ->and($schema->validateField('reviews_summary', 'variant', 'nope'))->not->toBe([]);
});

test('a family with no registered variants has no variant field', function () {
    $schema = app(SectionSchema::class);
    expect($schema->resolveField('faqs', 'variant'))->toBeNull()
        ->and($schema->validateField('faqs', 'variant', 'classic'))->not->toBe([])
        ->and($schema->validateField('faqs', 'variant', null))->not->toBe([]);
});

test('the enum never offers a token the registry treats as dead', function () {
    $schema = app(SectionSchema::class);
    $registry = app(PageLayoutRegistry::class);
    $families = array_unique([
        ...array_keys(config('site_sections')),
        ...array_keys(PageLayoutRegistry::INLINE_VARIANT_FAMILIES),
        ...PageLayoutRegistry::FILE_BACKED_FAMILIES,
    ]);

    foreach ($families as $family) {
        foreach ($schema->variantOptionsFor($family) as $variant) {
            expect($registry->isDeadPersistedVariant($family, $variant))
                ->toBeFalse("{$family}.{$variant}");
        }
    }
});

test('lead_form never offers variant tokens, even with variant blades on disk', function () {
    $dir = resource_path('views/site/sections/variants/lead_form');
    @mkdir($dir, 0775, true);
    file_put_contents($dir.'/zz-schema-probe.blade.php', '');
    try {
        expect(app(SectionSchema::class)->variantOptionsFor('lead_form'))->toBe([])
            ->and(SectionSchema::PICKER_EXCLUDED_FAMILIES)->toBe(['lead_form']);
    } finally {
        @unlink($dir.'/zz-schema-probe.blade.php');
    }
});

test('a raw variant write to a lead_form section is rejected as an unknown field', function () {
    expect(app(SectionSchema::class)->resolveField('lead_form', 'variant'))->toBeNull();
});

test('team and statistics sections have registered schemas and resolve fields and variants', function () {
    $schema = app(SectionSchema::class);

    expect($schema->isKnownSectionType('team'))->toBeTrue()
        ->and($schema->isKnownSectionType('statistics'))->toBeTrue();

    expect($schema->resolveField('team', 'title'))->toBe(['type' => 'plain', 'max' => 120])
        ->and($schema->resolveField('team', 'members.0.name'))->toBe(['type' => 'plain', 'max' => 80])
        ->and($schema->resolveField('team', 'variant'))->toMatchArray(['type' => 'enum', 'values' => ['classic'], 'nullable' => true]);

    expect($schema->resolveField('statistics', 'title'))->toBe(['type' => 'plain', 'max' => 120])
        ->and($schema->resolveField('statistics', 'items.0.value'))->toBe(['type' => 'plain', 'max' => 32])
        ->and($schema->resolveField('statistics', 'variant'))->toMatchArray(['type' => 'enum', 'values' => ['classic', 'adjacent'], 'nullable' => true]);
});
