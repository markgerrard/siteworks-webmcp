<?php

use App\Models\HeroVersion;
use App\Models\HeroVideoVersion;
use App\Models\LogoConcept;
use App\Models\Preview;
use App\Models\Site;
use App\Models\SiteMedia;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\OperationSchemas;
use App\Services\Site\PublicPageCache;
use Tests\Support\EditorSeeds;

beforeEach(function () {
    $this->withoutVite();
});

/**
 * The media must be created before each write test so the oracle reloads a row
 * the implementation did not hand back.
 */
function updateAssetMetadataMedia(Site $site, array $attributes = []): SiteMedia
{
    return SiteMedia::factory()->for($site)->create($attributes);
}

it('writes alt_text and metadata to the live row and reports each change on the receipt', function () {
    [$user, $site] = EditorSeeds::site();
    EditorSeeds::exposeAsInternal($site);
    $media = updateAssetMetadataMedia($site, [
        'alt_text' => 'Legacy alt',
        'metadata' => [
            'caption' => 'Legacy caption',
            'attribution' => 'Legacy attribution',
            'role' => 'decorative',
            'focal_point' => ['y' => 10],
        ],
    ]);

    $result = EditorSeeds::run($user, $site, 'update_asset_metadata', [
        'media_id' => $media->id,
        'alt_text' => 'The tide after sunset',
        'caption' => 'A lighthouse at dusk',
        'attribution' => 'Photo: Solent Studio',
        'role' => 'informative',
        'focal_point' => ['y' => 80],
        'composition_revision' => 0,
    ]);

    expect($result->ok)->toBeTrue();

    // Independent oracle: reload the row from the database, never read the object we wrote.
    $fresh = SiteMedia::findOrFail($media->id);
    expect($fresh->alt_text)->toBe('The tide after sunset');
    expect($fresh->metadata['caption'])->toBe('A lighthouse at dusk');
    expect($fresh->metadata['attribution'])->toBe('Photo: Solent Studio');
    expect($fresh->metadata['role'])->toBe('informative');
    expect($fresh->metadata['focal_point'])->toBe(['y' => 80]);

    // A no-op implementation would return ok with the values discarded; the receipt must
    // name the change so an agent can act on it.
    $receipt = $result->toArray()['receipt'];
    $mediaEntries = collect($receipt['changed'])
        ->filter(fn (array $entry): bool => str_starts_with($entry['path'], "site_media.{$media->id}"))
        ->values()
        ->all();

    expect($mediaEntries)->toBe([
        [
            'scope' => 'site',
            'page_id' => null,
            'stored_index' => null,
            'section_id' => null,
            'field_path' => null,
            'path' => "site_media.{$media->id}.metadata.caption",
            'before' => 'Legacy caption',
            'after' => 'A lighthouse at dusk',
            'kind' => 'set',
            'truncated' => false,
        ],
        [
            'scope' => 'site',
            'page_id' => null,
            'stored_index' => null,
            'section_id' => null,
            'field_path' => null,
            'path' => "site_media.{$media->id}.metadata.attribution",
            'before' => 'Legacy attribution',
            'after' => 'Photo: Solent Studio',
            'kind' => 'set',
            'truncated' => false,
        ],
        [
            'scope' => 'site',
            'page_id' => null,
            'stored_index' => null,
            'section_id' => null,
            'field_path' => null,
            'path' => "site_media.{$media->id}.metadata.role",
            'before' => 'decorative',
            'after' => 'informative',
            'kind' => 'set',
            'truncated' => false,
        ],
        [
            'scope' => 'site',
            'page_id' => null,
            'stored_index' => null,
            'section_id' => null,
            'field_path' => null,
            'path' => "site_media.{$media->id}.metadata.focal_point",
            'before' => ['y' => 10],
            'after' => ['y' => 80],
            'kind' => 'set',
            'truncated' => false,
        ],
        [
            'scope' => 'site',
            'page_id' => null,
            'stored_index' => null,
            'section_id' => null,
            'field_path' => null,
            'path' => "site_media.{$media->id}.alt_text",
            'before' => 'Legacy alt',
            'after' => 'The tide after sunset',
            'kind' => 'set',
            'truncated' => false,
        ],
    ]);

    expect($receipt['effective'])->toBe([
        'media_id' => $media->id,
        'alt_text' => 'The tide after sunset',
        'caption' => 'A lighthouse at dusk',
        'attribution' => 'Photo: Solent Studio',
        'role' => 'informative',
        'focal_point' => ['y' => 80],
    ]);
});

it('rejects an unknown key as validation rather than ignoring it', function () {
    [$user, $site] = EditorSeeds::site();
    EditorSeeds::exposeAsInternal($site);
    $media = updateAssetMetadataMedia($site);

    $result = EditorSeeds::run($user, $site, 'update_asset_metadata', [
        'media_id' => $media->id,
        'alt_text' => 'Known',
        'not_a_real_field' => 'silently dropped?',
        'composition_revision' => 0,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($result->error['fields'])->toHaveKey('not_a_real_field');

    expect(SiteMedia::findOrFail($media->id)->alt_text)->toBeNull();
});

it('rejects a media_id from another site as not_found', function () {
    [$user, $site] = EditorSeeds::site();
    EditorSeeds::exposeAsInternal($site);
    $otherSite = Site::factory()->create();
    $foreign = SiteMedia::factory()->for($otherSite)->create(['alt_text' => 'Foreign']);

    $result = EditorSeeds::run($user, $site, 'update_asset_metadata', [
        'media_id' => $foreign->id,
        'alt_text' => 'Not yours',
        'composition_revision' => 0,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('not_found');

    expect(SiteMedia::findOrFail($foreign->id)->alt_text)->toBe('Foreign');
});

it('refuses a focal point for a hero asset as not yet supported', function () {
    [$user, $site] = EditorSeeds::site();
    EditorSeeds::exposeAsInternal($site);
    $media = updateAssetMetadataMedia($site, [
        'url' => 'https://media.siteworks.dev/hero-banner.png',
        'metadata' => ['focal_point' => ['y' => 10]],
    ]);
    HeroVersion::factory()->for($site)->create([
        'url' => 'https://media.siteworks.dev/hero-banner.png',
        'page_type' => 'home',
        'slot' => 'hero',
    ]);

    $result = EditorSeeds::run($user, $site, 'update_asset_metadata', [
        'media_id' => $media->id,
        'alt_text' => 'Should not land',
        'focal_point' => ['y' => 80],
        'composition_revision' => 0,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($result->error['message'])->toBe('This is a hero asset; writing its focal point is not yet supported.');

    // The refusal must write nothing: a wrong implementation that stored the focal point
    // on the media row would leave metadata.focal_point as ['y' => 80].
    $fresh = SiteMedia::findOrFail($media->id);
    expect($fresh->metadata['focal_point'])->toBe(['y' => 10]);
    expect($fresh->alt_text)->toBeNull();
});

it('rejects alt_text longer than 250 characters without writing', function () {
    [$user, $site] = EditorSeeds::site();
    EditorSeeds::exposeAsInternal($site);
    $media = updateAssetMetadataMedia($site, ['alt_text' => 'Original']);

    $result = EditorSeeds::run($user, $site, 'update_asset_metadata', [
        'media_id' => $media->id,
        'alt_text' => str_repeat('a', 251),
        'composition_revision' => 0,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($result->error['fields'])->toHaveKey('alt_text');

    expect(SiteMedia::findOrFail($media->id)->alt_text)->toBe('Original');
});

it('leaves the drafts-law quartet unchanged', function () {
    [$user, $site, $page] = EditorSeeds::homeWithHero();
    EditorSeeds::exposeAsInternal($site);
    $media = updateAssetMetadataMedia($site);

    // Seed a real drafts-law fixture so the "unchanged" assertions have teeth.
    // Two hero versions (one known-active), a selected logo concept, two hero-video
    // versions (one known-active), and the home hero video flag on. Identity — not
    // aggregate counts — is what makes a wrong implementation that deactivates or
    // swaps the active row fail here.
    $activeHero = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'is_active' => true,
    ]);
    HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'is_active' => false,
    ]);
    $selectedLogo = LogoConcept::factory()->for($site)->selected()->create();
    $activeHeroVideo = HeroVideoVersion::factory()->for($site)->active()->create();
    HeroVideoVersion::factory()->for($site)->create();
    $site->update(['home_hero_video_enabled' => true]);
    $site = $site->fresh();

    $preview = Preview::factory()->for($site)->create(['snapshot' => ['marker' => 'unchanged']]);
    $publishedRevisionId = $page->fresh()->published_revision_id;
    $cacheGeneration = app(PublicPageCache::class)->generation($site);

    $result = EditorSeeds::run($user, $site, 'update_asset_metadata', [
        'media_id' => $media->id,
        'alt_text' => 'The only exception',
        'caption' => 'A caption',
        'composition_revision' => 0,
    ]);

    expect($result->ok)->toBeTrue();

    // The drafts-law quartet stays untouched. Assert the IDENTITY of the active
    // row rather than a count, so a swap (same count) or a blanket deactivate
    // (count drops to zero) is caught.
    expect($page->fresh()->published_revision_id)->toBe($publishedRevisionId);
    expect(HeroVersion::query()->where('site_id', $site->id)->where('is_active', true)->pluck('id')->all())
        ->toBe([$activeHero->id]);
    expect(LogoConcept::query()->where('site_id', $site->id)->where('is_selected', true)->pluck('id')->all())
        ->toBe([$selectedLogo->id]);
    expect(HeroVideoVersion::query()->where('site_id', $site->id)->where('is_active', true)->pluck('id')->all())
        ->toBe([$activeHeroVideo->id]);
    expect($site->fresh()->home_hero_video_enabled)->toBeTrue();
    expect(app(PublicPageCache::class)->generation($site))->toBe($cacheGeneration);
    expect($preview->fresh()->snapshot)->toBe(['marker' => 'unchanged']);
});

it('states in the tool description that this exception is not drafted', function () {
    $operation = app(OperationRegistry::class)->get('update_asset_metadata');
    $description = OperationSchemas::description($operation);

    expect($description)->toContain('This change is not drafted')
        ->and($description)->toContain('next uncached public render')
        ->and($description)->not->toContain('immediately');
});

it('records the audit row for the operation', function () {
    [$user, $site] = EditorSeeds::site();
    EditorSeeds::exposeAsInternal($site);
    $media = updateAssetMetadataMedia($site);

    $result = EditorSeeds::run($user, $site, 'update_asset_metadata', [
        'media_id' => $media->id,
        'alt_text' => 'Audited alt',
        'composition_revision' => 0,
    ]);

    expect($result->ok)->toBeTrue();
    $this->assertDatabaseHas('editor_operation_log', [
        'site_id' => $site->id,
        'operation' => 'update_asset_metadata',
        'result_code' => 'ok',
    ]);
});

it('reaches update_asset_metadata through the generic route and writes', function () {
    [$user, $site] = EditorSeeds::site();
    EditorSeeds::exposeAsInternal($site);
    config([
        'editor.operations.enabled' => true,
        'editor.agent_tools.enabled' => true,
        'editor.agent_tools.roles' => ['staff'],
    ]);
    $media = updateAssetMetadataMedia($site);

    $this->actingAs($user)
        ->withHeaders(['X-Editor-Channel' => 'webmcp'])
        ->postJson("/sites/{$site->id}/operations/update_asset_metadata", [
            'media_id' => $media->id,
            'alt_text' => 'Via the generic route',
            'caption' => 'Route caption',
            'composition_revision' => 0,
        ])
        ->assertOk();

    // Independent oracle: the router may be doing anything; the write must be real.
    $fresh = SiteMedia::findOrFail($media->id);
    expect($fresh->alt_text)->toBe('Via the generic route')
        ->and($fresh->metadata['caption'])->toBe('Route caption');
});

it('accepts the expected_revision alias in place of composition_revision through the generic route', function () {
    [$user, $site] = EditorSeeds::site();
    EditorSeeds::exposeAsInternal($site);
    config([
        'editor.operations.enabled' => true,
        'editor.agent_tools.enabled' => true,
        'editor.agent_tools.roles' => ['staff'],
    ]);
    $media = updateAssetMetadataMedia($site);

    $this->actingAs($user)
        ->withHeaders(['X-Editor-Channel' => 'webmcp'])
        ->postJson("/sites/{$site->id}/operations/update_asset_metadata", [
            'media_id' => $media->id,
            'alt_text' => 'Via expected_revision',
            'expected_revision' => 0,
        ])
        ->assertOk();

    expect(SiteMedia::findOrFail($media->id)->alt_text)->toBe('Via expected_revision');
});
