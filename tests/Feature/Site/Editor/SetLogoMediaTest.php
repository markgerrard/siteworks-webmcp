<?php

use App\Enums\HeroVersionSource;
use App\Enums\LogoConceptSource;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\HeroVideoVersion;
use App\Models\LogoConcept;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteDraftAssetSelection;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Models\SiteMedia;
use App\Services\Site\Editor\DraftAssetSelections;
use App\Services\Site\PageRenderer;
use App\Services\Site\PublicPageCache;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EditorSeeds;

beforeEach(function () {
    config(['editor.operations.enabled' => true, 'editor.agent_tools.enabled' => true]);
    Storage::fake('s3');
});

/**
 * @return array{
 *     published_revision_id: int|null,
 *     home_hero_video_enabled: bool,
 *     active_hero_ids: list<int>,
 *     selected_logo_ids: list<int>,
 *     selected_flags: array<int, bool>,
 *     active_video_ids: list<int>,
 *     preview_snapshots: list<array{id: int, snapshot: mixed}>,
 *     public_cache_generation: int
 * }
 */
function setLogoMediaLivePins(Site $site, GeneratedPage $page): array
{
    $page = $page->fresh();

    return [
        'published_revision_id' => $page->published_revision_id,
        'home_hero_video_enabled' => (bool) $site->fresh()->home_hero_video_enabled,
        'active_hero_ids' => HeroVersion::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id')
            ->all(),
        'selected_logo_ids' => LogoConcept::query()
            ->where('site_id', $site->id)
            ->where('is_selected', true)
            ->orderBy('id')
            ->pluck('id')
            ->all(),
        'selected_flags' => LogoConcept::query()
            ->where('site_id', $site->id)
            ->orderBy('id')
            ->pluck('is_selected', 'id')
            ->all(),
        'active_video_ids' => HeroVideoVersion::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id')
            ->all(),
        'preview_snapshots' => Preview::query()
            ->where('site_id', $site->id)
            ->orderBy('id')
            ->get()
            ->map(fn (Preview $row): array => [
                'id' => $row->id,
                'snapshot' => $row->snapshot,
            ])
            ->all(),
        'public_cache_generation' => app(PublicPageCache::class)->generation($site),
    ];
}

function setLogoMediaPinPublishedHome(Site $site, GeneratedPage $page): SiteVersion
{
    $composition = [
        'nav' => ['items' => []],
        'footer' => ['columns' => [], 'show_credit' => true],
        'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
        'homepage_page_id' => $page->id,
    ];
    $version = SiteVersion::query()->create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => $composition,
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $page->published_revision_id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::query()->create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);
    SiteDraft::query()->updateOrCreate(
        ['site_id' => $site->id],
        ['composition' => $composition, 'updated_at' => now()],
    );

    return $version;
}

it('creates an unselected uploaded logo concept, writes only the draft selection, and leaves the published logo unchanged', function () {
    [$user, $site, $page] = EditorSeeds::homeWithHero();
    setLogoMediaPinPublishedHome($site, $page);
    $publishedPath = 'logos/published-selected-v2.png';
    $unselectedPath = 'logos/unselected-uploaded-v7.png';
    $mediaKey = 'site-media/set-logo-media-candidate.png';
    $published = LogoConcept::factory()->for($site)->selected()->create([
        'source' => LogoConceptSource::Generated,
        'version' => 2,
        'path' => $publishedPath,
    ]);
    $unselected = LogoConcept::factory()->for($site)->create([
        'source' => LogoConceptSource::Uploaded,
        'version' => 7,
        'path' => $unselectedPath,
    ]);
    $media = SiteMedia::factory()->for($site)->create(['s3_key' => $mediaKey]);
    HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'source' => HeroVersionSource::AiGenerated,
    ]);
    HeroVideoVersion::factory()->for($site)->active()->create();
    Preview::factory()->for($site)->create(['snapshot' => ['marker' => 'set-logo-media-unchanged']]);

    $idsBefore = LogoConcept::query()->where('site_id', $site->id)->orderBy('id')->pluck('id')->all();
    $expectedVersion = (int) LogoConcept::query()->where('site_id', $site->id)->max('version') + 1;
    $liveBefore = setLogoMediaLivePins($site, $page);
    $compositionRevision = (int) SiteDraft::query()->where('site_id', $site->id)->value('admin_revision');

    expect($expectedVersion)->toBe(8)
        ->and($idsBefore)->toBe([$published->id, $unselected->id]);

    $result = EditorSeeds::run($user, $site, 'set_logo_media', [
        'media_id' => $media->id,
        'composition_revision' => $compositionRevision,
    ]);

    $newIds = LogoConcept::query()
        ->where('site_id', $site->id)
        ->whereNotIn('id', $idsBefore)
        ->orderBy('id')
        ->pluck('id')
        ->all();
    expect($newIds)->toHaveCount(1);
    $newId = $newIds[0];
    $created = LogoConcept::query()->findOrFail($newId);

    expect($result->ok)->toBeTrue()
        ->and($result->data)->toBe([
            'logo_concept_id' => $newId,
            'media_id' => $media->id,
        ])
        ->and($created->source)->toBe(LogoConceptSource::Uploaded)
        ->and($created->is_selected)->toBeFalse()
        ->and($created->path)->toBe($mediaKey)
        ->and($created->version)->toBe($expectedVersion)
        ->and($created->site_id)->toBe($site->id);

    expect(LogoConcept::query()->where('site_id', $site->id)->whereIn('id', $idsBefore)->orderBy('id')->pluck('is_selected', 'id')->all())
        ->toBe($liveBefore['selected_flags']);
    expect($published->fresh()->is_selected)->toBeTrue()
        ->and($unselected->fresh()->is_selected)->toBeFalse();

    expect(app(DraftAssetSelections::class)->logoFor($site)?->id)->toBe($newId);
    expect(SiteDraftAssetSelection::query()->where('site_id', $site->id)->where('family', 'logo')->value('version_id'))
        ->toBe($newId);

    $inserts = collect($result->receipt?->changed ?? [])
        ->where('kind', 'insert')
        ->where('path', "logo_concepts.{$newId}")
        ->values()
        ->all();
    expect($inserts)->toBe([[
        'scope' => 'site',
        'page_id' => null,
        'stored_index' => null,
        'section_id' => null,
        'field_path' => null,
        'path' => "logo_concepts.{$newId}",
        'before' => null,
        'after' => [
            'logo_concept_id' => $newId,
            'media_id' => $media->id,
        ],
        'kind' => 'insert',
        'truncated' => false,
    ]]);

    $liveAfter = setLogoMediaLivePins($site, $page);
    expect($liveAfter['published_revision_id'])->toBe($liveBefore['published_revision_id'])
        ->and($liveAfter['home_hero_video_enabled'])->toBe($liveBefore['home_hero_video_enabled'])
        ->and($liveAfter['active_hero_ids'])->toBe($liveBefore['active_hero_ids'])
        ->and($liveAfter['selected_logo_ids'])->toBe($liveBefore['selected_logo_ids'])
        ->and($liveAfter['active_video_ids'])->toBe($liveBefore['active_video_ids'])
        ->and($liveAfter['preview_snapshots'])->toBe($liveBefore['preview_snapshots'])
        ->and($liveAfter['public_cache_generation'])->toBe($liveBefore['public_cache_generation']);

    $renderer = app(PageRenderer::class);
    $publicHtml = $renderer->render($site, $page->id, mode: 'public');
    $draftHtml = $renderer->render($site, $page->id, mode: 'admin-edit', useDraftAssets: true);

    expect($publicHtml)->toContain($publishedPath)->not->toContain($mediaKey)
        ->and($draftHtml)->toContain($mediaKey)->not->toContain($publishedPath);
});

it('returns not_found for a media_id that belongs to another site', function () {
    [$user, $site] = EditorSeeds::homeWithHero();
    $foreign = SiteMedia::factory()->create(['s3_key' => 'other-site/foreign-logo.png']);
    $idsBefore = LogoConcept::query()->where('site_id', $site->id)->orderBy('id')->pluck('id')->all();

    $result = EditorSeeds::run($user, $site, 'set_logo_media', [
        'media_id' => $foreign->id,
        'composition_revision' => (int) (SiteDraft::query()->where('site_id', $site->id)->value('admin_revision') ?? 0),
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('not_found')
        ->and($result->error['message'])->toBe('Media not found.')
        ->and(LogoConcept::query()->where('site_id', $site->id)->orderBy('id')->pluck('id')->all())->toBe($idsBefore)
        ->and(SiteDraftAssetSelection::query()->where('site_id', $site->id)->exists())->toBeFalse();
});
