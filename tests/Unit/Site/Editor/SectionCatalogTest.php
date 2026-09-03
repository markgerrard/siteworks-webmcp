<?php

use App\Models\Site;
use App\Services\Site\Editor\SectionCatalog;
use App\Services\Site\SectionSchema;

it('catalogues every site_sections type exactly once', function () {
    expect(array_keys(config('section_catalog')))->toEqualCanonicalizing(array_keys(config('site_sections')));
});

it('produces a complete, valid default payload for every addable type', function () {
    $catalog = app(SectionCatalog::class);
    $schema = app(SectionSchema::class);
    $site = Site::factory()->create();
    foreach (array_keys(config('section_catalog')) as $type) {
        if ($catalog->isInjectedOnly($type)) {
            expect(fn () => $catalog->defaultPayload($type, $site))->toThrow(InvalidArgumentException::class);
            continue;
        }
        $payload = $catalog->defaultPayload($type, $site);
        expect($payload['type'])->toBe($type);
        // every schema field (required or not) that has a value in the payload validates
        foreach (config("site_sections.{$type}.fields") as $path => $rule) {
            if (str_contains($path, '*')) { expect(data_get($payload, explode('.*', $path)[0]))->toBeArray("{$type}.{$path} repeatable default"); continue; }
            if (($rule['required'] ?? false) === true) { expect(data_get($payload, $path))->not->toBeNull("{$type}.{$path} required default"); }
            if (data_get($payload, $path) !== null) { expect($schema->validateField($type, $path, data_get($payload, $path)))->toBe([], "{$type}.{$path}"); }
        }
        foreach (['item_ids', 'pair_ids'] as $ref) { if (array_key_exists($ref, $catalog->referenceFields($type))) { expect($payload[$ref])->toBe([]); } }
    }
});

it('enforces page-type restrictions and singletons', function () {
    $catalog = app(SectionCatalog::class);
    expect($catalog->allowedOn('project_gallery', 'home'))->toBeFalse()
        ->and($catalog->allowedOn('project_gallery', 'projects'))->toBeTrue()
        ->and($catalog->isSingleton('hero'))->toBeTrue();
});

it('rejects cross-site references', function () {
    $catalog = app(SectionCatalog::class);
    $site = Site::factory()->create();
    $other = Site::factory()->create();
    $foreign = \App\Models\ProjectItem::factory()->for($other)->create();
    expect($catalog->validateReferences($site, ['type' => 'project_gallery', 'item_ids' => [$foreign->id]]))->not->toBe([]);
});

it('tolerates unset and numeric-string references like the renderer does', function () {
    $catalog = app(SectionCatalog::class);
    $site = Site::factory()->create();
    $own = \App\Models\ProjectItem::factory()->for($site)->create();
    expect($catalog->validateReferences($site, ['type' => 'project_gallery', 'item_ids' => [(string) $own->id, null]]))->toBe([]);
    $type = collect(array_keys(config('section_catalog')))->first(fn ($t) => array_search('site_media', $catalog->referenceFields($t), true) !== false);
    $field = array_search('site_media', $catalog->referenceFields($type), true);
    expect($catalog->validateReferences($site, ['type' => $type, $field => null]))->toBe([])
        ->and($catalog->validateReferences($site, ['type' => $type, $field => 'https://example.com/x.jpg']))->toBe([])
        ->and($catalog->validateReferences($site, ['type' => $type, $field => 'not-a-url']))->not->toBe([]);
});
