<?php

/**
 * Guard: the three global base-read ops must appear in the generated Front 2
 * schemas artefact. The orchestrator regenerates schemas.json at merge —
 * do not hand-edit it. This test is RED until that regen lands.
 */
it('advertises get_site_context, get_brand_system, and get_logo_assets in schemas.json', function () {
    $schemas = json_decode(
        (string) file_get_contents(resource_path('js/site-editor/webmcp/schemas.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $operations = $schemas['operations'] ?? [];

    expect($operations)->toHaveKey('get_site_context')
        ->and($operations)->toHaveKey('get_brand_system')
        ->and($operations)->toHaveKey('get_logo_assets');
});
