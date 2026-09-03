<?php

use App\Services\Site\Editor\CommerceOperations;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\ToolExposure;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
});

/**
 * @return list<string>
 */
function skillExposureNames(): array
{
    return [
        'skill_import_catalogue_from_source',
        'skill_add_product_with_imagery',
        'skill_export_catalogue',
    ];
}

it('puts all three skill ops on sandbox, internal, and the client SANDBOX allowlist', function () {
    $sandbox = config('editor.exposure.sets.sandbox');
    $internal = config('editor.exposure.sets.internal');

    foreach (skillExposureNames() as $name) {
        expect($sandbox)->toContain($name)
            ->and($internal)->toContain($name)
            ->and(CommerceOperations::SANDBOX)->toContain($name)
            ->and(app(OperationRegistry::class)->has($name))->toBeTrue();
    }
});

it('puts only skill_export_catalogue on portal_base, keeping the other two shop-page scoped', function () {
    $portalBase = config('editor.exposure.sets.portal_base');

    expect($portalBase)->toContain('skill_export_catalogue')
        ->and(CommerceOperations::PORTAL_BASE)->toContain('skill_export_catalogue')
        ->and($portalBase)->not->toContain('skill_import_catalogue_from_source')
        ->and($portalBase)->not->toContain('skill_add_product_with_imagery')
        ->and(CommerceOperations::PORTAL_BASE)->not->toContain('skill_import_catalogue_from_source')
        ->and(CommerceOperations::PORTAL_BASE)->not->toContain('skill_add_product_with_imagery');
});

it('does not widen any other exposure set with skill ops', function () {
    expect(array_keys(config('editor.exposure.sets')))->toEqualCanonicalizing([
        'sandbox',
        'commerce',
        'portal_base',
        'internal',
    ]);

    expect(config('editor.exposure.sets.commerce'))->toEqualCanonicalizing([
        'list_products',
        'get_product',
        'draft_product',
        'update_draft_product',
        'set_product_image',
        'manage_category',
        'draft_category_content',
        'upload_image',
    ]);

    foreach (skillExposureNames() as $name) {
        expect(config('editor.exposure.sets.commerce'))->not->toContain($name);
    }
});

it('exposes the skill ops for a staff agent on a shop sandbox site', function () {
    [, $site] = CommerceReads::shopSite();
    $exposure = app(ToolExposure::class);

    foreach (skillExposureNames() as $name) {
        expect($exposure->exposes($site, $name))->toBeTrue();
    }
});
