<?php

use App\Services\Site\Editor\CommerceOperations;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\ToolExposure;
use Tests\Support\CommerceReads;

/**
 * @return list<string>
 */
function portalBaseNames(): array
{
    return [
        'get_site_context',
        'get_brand_system',
        'get_logo_assets',
        'export_products',
        'list_products',
        'get_product',
        'upload_image',
        'skill_export_catalogue',
    ];
}

/**
 * Specialist editor mutations that must stay editor-page-only.
 *
 * @return list<string>
 */
function portalBaseForbiddenSpecialists(): array
{
    return [
        'add_section',
        'edit_field',
        'move_section',
        'remove_section',
        'update_brand_theme',
        'draft_product',
        'manage_category',
        'update_draft_product',
        'set_product_image',
        'draft_category_content',
        'describe_import_products',
        'skill_import_catalogue_from_source',
        'skill_add_product_with_imagery',
        // Client-executable write; excluded from portal_base
        // (advertised-on-injectable-pages path). Shop page sets keep it.
        'import_products',
    ];
}

it('keeps portal_base fully covered by the internal tenant set', function () {
    $portalBase = config('editor.exposure.sets.portal_base');
    $internal = (array) config('editor.exposure.sets.internal');

    expect(array_diff($portalBase, $internal))->toBe([]);
});

it('declares portal_base as a named exposure set of the global read/handoff tools', function () {
    $portalBase = config('editor.exposure.sets.portal_base');

    expect($portalBase)->toBeArray()
        ->and($portalBase)->toEqualCanonicalizing(portalBaseNames())
        ->and(CommerceOperations::PORTAL_BASE)->toEqualCanonicalizing(portalBaseNames())
        ->and(app(ToolExposure::class)->named('portal_base'))->toEqualCanonicalizing(portalBaseNames());
});

it('keeps portal_base a subset of the sandbox tenant set and the client SANDBOX allowlist', function () {
    $portalBase = config('editor.exposure.sets.portal_base');
    $sandbox = (array) config('editor.exposure.sets.sandbox');

    expect($portalBase)->toBeArray()->not->toBeEmpty();

    foreach ($portalBase as $operation) {
        expect($sandbox)->toContain($operation)
            ->and(CommerceOperations::SANDBOX)->toContain($operation);
    }

    expect($portalBase)->not->toEqualCanonicalizing(CommerceOperations::SANDBOX);
});

it('does not put specialist editor or commerce-write ops on portal_base', function () {
    $portalBase = config('editor.exposure.sets.portal_base');

    expect($portalBase)->toBeArray()->not->toBeEmpty();

    foreach (portalBaseForbiddenSpecialists() as $operation) {
        expect($portalBase)->not->toContain($operation);
    }
});

it('names only already-built operations on portal_base — no new ops', function () {
    $portalBase = config('editor.exposure.sets.portal_base');
    $registry = app(OperationRegistry::class);

    expect($portalBase)->toBeArray()->not->toBeEmpty();

    foreach ($portalBase as $operation) {
        expect($registry->has($operation))->toBeTrue();
    }
});

it('still maps an unlisted site onto the sandbox tenant set, not portal_base', function () {
    [, $site] = CommerceReads::shopSite();

    expect(app(ToolExposure::class)->nameFor($site))->toBe('sandbox')
        ->and(config('editor.exposure.default'))->toBe('sandbox');
});
