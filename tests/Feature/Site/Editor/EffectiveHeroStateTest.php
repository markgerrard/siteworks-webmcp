<?php

use App\Enums\HeroVersionSource;
use App\Enums\PageKind;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\HeroVideoVersion;
use App\Models\LogoConcept;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteDraftAssetSelection;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\Editor\DraftAssetSelections;
use App\Services\Site\Editor\Operations\GetEffectiveHeroStateOperation;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\PageRenderer;
use App\Services\Site\PublicPageCache;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EditorSeeds;

beforeEach(function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
    Storage::fake('s3');
});

/**
 * @return array{
 *     published_revision_id: int|null,
 *     draft_revision_id: int|null,
 *     page_content_data: mixed,
 *     published_revision_content: mixed,
 *     draft_revision_content: mixed,
 *     home_hero_video_enabled: bool,
 *     active_hero_ids: list<int>,
 *     selected_logo_ids: list<int>,
 *     active_video_ids: list<int>,
 *     draft_selections: list<array<string, mixed>>,
 *     previews: list<array{id: int, snapshot: mixed}>,
 *     public_cache_generation: int
 * }
 */
function heroStateLivePins(Site $site, GeneratedPage $page): array
{
    $page = $page->fresh();
    $page->loadMissing(['publishedRevision', 'draftRevision']);

    return [
        'published_revision_id' => $page->published_revision_id,
        'draft_revision_id' => $page->draft_revision_id,
        'page_content_data' => $page->content_data,
        'published_revision_content' => $page->publishedRevision?->content_data,
        'draft_revision_content' => $page->draftRevision?->content_data,
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
        'active_video_ids' => HeroVideoVersion::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id')
            ->all(),
        'draft_selections' => SiteDraftAssetSelection::query()
            ->where('site_id', $site->id)
            ->orderBy('id')
            ->get()
            ->map(fn (SiteDraftAssetSelection $row): array => [
                'id' => $row->id,
                'family' => $row->family,
                'page_type' => $row->page_type,
                'slot' => $row->slot,
                'version_id' => $row->version_id,
                'mode' => $row->mode,
                'placement' => $row->placement,
            ])
            ->all(),
        'previews' => Preview::query()
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

function expectHeroStateLivePinsUnchanged(Site $site, GeneratedPage $page, array $before): void
{
    expect(heroStateLivePins($site, $page))->toBe($before);
}

function runEffectiveHeroState($actor, Site $site, array $input = []): OperationResult
{
    return EditorSeeds::run($actor, $site, 'get_effective_hero_state', $input);
}

/**
 * Write `$fields` onto the published revision's hero section and `$legacyFields`
 * onto GeneratedPage::content_data. The two must differ so a reader of only
 * the legacy column cannot pass section_field / placeholder.
 *
 * @param  array<string, mixed>  $fields
 * @param  array<string, mixed>  $legacyFields
 */
function putHeroSection(GeneratedPage $page, array $fields, ?array $legacyFields = null): void
{
    $legacyFields ??= [
        'background_image' => 'https://cdn.example/legacy-content-data-must-not-win.jpg',
        'placeholder' => false,
    ];

    $page->loadMissing('publishedRevision');
    $content = $page->publishedRevision?->content_data ?? $page->content_data ?? ['sections' => []];
    $publishedSections = is_array($content['sections'] ?? null) ? $content['sections'] : [];
    $legacySections = $publishedSections;
    foreach ($publishedSections as $index => $section) {
        if (($section['type'] ?? null) === 'hero') {
            $publishedSections[$index] = array_merge($section, $fields);
            $legacySections[$index] = array_merge($section, $legacyFields);
        }
    }
    $publishedContent = $content;
    $publishedContent['sections'] = $publishedSections;
    $legacyContent = $content;
    $legacyContent['sections'] = $legacySections;
    $page->publishedRevision?->update(['content_data' => $publishedContent]);
    $page->update(['content_data' => $legacyContent]);
}

function pinPublishedHome(Site $site, GeneratedPage $page): void
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
    SiteDraft::query()->create([
        'site_id' => $site->id,
        'composition' => $composition,
        'updated_at' => now(),
    ]);
}

it('is a read-only site-addressed operation that declares its own approval boundary', function () {
    $operation = app(GetEffectiveHeroStateOperation::class);
    $declaredOn = (new ReflectionMethod($operation, 'requiresApproval'))->getDeclaringClass()->getName();

    expect($operation->readOnly())->toBeTrue()
        ->and($operation->address())->toBe('site')
        ->and($operation->wrapInAdminChange())->toBeFalse()
        ->and($operation->requiresApproval())->toBeFalse()
        ->and($declaredOn)->toBe(GetEffectiveHeroStateOperation::class);
});

it('reports video_mode_active and names the shadowed image', function () {
    [$actor, $site, $page] = EditorSeeds::homeWithHero();
    $before = heroStateLivePins($site, $page);

    $image = HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/shadowed-hero-83.jpg',
        'source' => HeroVersionSource::UserUpload,
        'placement' => ['text_zone' => 'bottom-right', 'bg_position_y' => 83],
    ]);
    $videoKey = 'dev-previews/'.$site->id.'/hero-home-video.mp4';
    Storage::disk('s3')->put($videoKey, 'hero-video-bytes');
    $video = HeroVideoVersion::factory()->for($site)->active()->create([
        's3_key' => $videoKey,
    ]);
    $site->update([
        'home_hero_video_enabled' => true,
        'home_hero_video_path' => $videoKey,
    ]);
    $before['home_hero_video_enabled'] = true;
    $before['active_hero_ids'] = [$image->id];
    $before['active_video_ids'] = [$video->id];

    $result = runEffectiveHeroState($actor, $site->fresh(), ['page_id' => $page->id]);

    expect($result->ok)->toBeTrue()
        ->and($result->data['published'])->toBe([
            'mode' => 'video',
            'source' => HeroVersionSource::UserUpload->value,
            'version_id' => $video->id,
            'url' => Storage::disk('s3')->url($videoKey),
            'reason' => 'video_mode_active',
            'placement' => ['text_zone' => 'bottom-right', 'bg_position_y' => 83],
            'image_version_id' => $image->id,
            'image_url' => 'https://cdn.example/shadowed-hero-83.jpg',
        ])
        ->and($result->data['draft']['reason'])->toBe('video_mode_active')
        ->and($result->data['differs'])->toBeFalse();

    expectHeroStateLivePinsUnchanged($site, $page, $before);
});

it('reports hero_version_active, not section_field, when an active version shadows the section image', function () {
    [$actor, $site, $page] = EditorSeeds::homeWithHero();
    putHeroSection($page, ['background_image' => 'https://cdn.example/section-field-must-not-win.jpg']);
    $image = HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/active-version-wins-29.jpg',
        'source' => HeroVersionSource::FacebookImport,
        'placement' => ['text_zone' => 'middle-center', 'bg_position_y' => 29],
    ]);
    $before = heroStateLivePins($site, $page->fresh());

    $result = runEffectiveHeroState($actor, $site, ['page_id' => $page->id]);

    expect($result->ok)->toBeTrue()
        ->and($result->data['published'])->toBe([
            'mode' => 'image',
            'source' => HeroVersionSource::FacebookImport->value,
            'version_id' => $image->id,
            'url' => 'https://cdn.example/active-version-wins-29.jpg',
            'reason' => 'hero_version_active',
            'placement' => ['text_zone' => 'middle-center', 'bg_position_y' => 29],
            'image_version_id' => $image->id,
            'image_url' => 'https://cdn.example/active-version-wins-29.jpg',
        ])
        ->and($result->data['published']['reason'])->not->toBe('section_field')
        ->and($result->data['published']['url'])->not->toBe('https://cdn.example/section-field-must-not-win.jpg')
        ->and($result->data['differs'])->toBeFalse();

    expectHeroStateLivePinsUnchanged($site, $page, $before);
});

it('reports scene_active when a configured scene outranks the image', function () {
    [$actor, $site, $page] = EditorSeeds::homeWithHero();
    $image = HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/scene-shadowed-image.jpg',
        'source' => HeroVersionSource::UserUpload,
        'placement' => ['text_zone' => 'top-left', 'bg_position_y' => 11],
    ]);
    $slide = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/scene-slide.jpg',
    ]);
    $site->update([
        'home_hero_scene' => [
            'kind' => 'image',
            'slides' => [[
                'asset_type' => 'hero_version',
                'asset_id' => $slide->id,
                'heading' => 'Authored scene',
            ]],
            'transitions' => [],
        ],
    ]);
    $before = heroStateLivePins($site, $page);

    $result = runEffectiveHeroState($actor, $site->fresh(), ['page_type' => 'home']);

    expect($result->ok)->toBeTrue()
        ->and($result->data['published']['mode'])->toBe('scene')
        ->and($result->data['published']['source'])->toBe(HeroVersionSource::UserUpload->value)
        ->and($result->data['published']['reason'])->toBe('scene_active')
        ->and($result->data['published']['version_id'])->toBe($image->id)
        ->and($result->data['published']['image_version_id'])->toBe($image->id)
        ->and($result->data['published']['image_url'])->toBe('https://cdn.example/scene-shadowed-image.jpg')
        ->and($result->data['published']['url'])->toBeNull()
        ->and($result->data['differs'])->toBeFalse();

    expectHeroStateLivePinsUnchanged($site, $page, $before);
});

it('reports the published scene when an enabled pathless video coexists with a configured scene, matching the public renderer', function () {
    [$actor, $site, $page] = EditorSeeds::homeWithHero();
    $this->withoutVite();
    pinPublishedHome($site, $page);

    $image = HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/pathless-video-shadowed-image.jpg',
        'source' => HeroVersionSource::UserUpload,
        'placement' => ['text_zone' => 'middle-left', 'bg_position_y' => 17],
    ]);
    $slide = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/pathless-video-scene-slide.jpg',
    ]);
    $canonicalKey = 'dev-previews/'.$site->id.'/hero-home-video.mp4';
    Storage::disk('s3')->put($canonicalKey, 'canonical-hero-video-bytes');
    HeroVideoVersion::factory()->for($site)->active()->create([
        's3_key' => $canonicalKey,
    ]);
    $site->update([
        'home_hero_video_enabled' => true,
        'home_hero_video_path' => null,
        'home_hero_scene' => [
            'kind' => 'image',
            'slides' => [[
                'asset_type' => 'hero_version',
                'asset_id' => $slide->id,
                'heading' => 'Pathless video must not beat this scene',
            ]],
            'transitions' => [],
            'motion' => 'ken_burns',
        ],
    ]);
    $before = heroStateLivePins($site->fresh(), $page);
    $videoUrl = Storage::disk('s3')->url($canonicalKey);

    $result = runEffectiveHeroState($actor, $site->fresh(), ['page_id' => $page->id]);
    $publicHtml = app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public', useDraftAssets: false);

    $rendererShowsScene = str_contains($publicHtml, $slide->url)
        && str_contains($publicHtml, 'scene-kb')
        && str_contains($publicHtml, 'Pathless video must not beat this scene');
    $rendererShowsCanonicalVideo = str_contains($publicHtml, $videoUrl);

    expect($rendererShowsScene)->toBeTrue()
        ->and($rendererShowsCanonicalVideo)->toBeFalse()
        ->and($result->ok)->toBeTrue()
        ->and($result->data['published']['mode'])->toBe($rendererShowsScene ? 'scene' : 'video')
        ->and($result->data['published']['reason'])->toBe($rendererShowsScene ? 'scene_active' : 'video_mode_active')
        ->and($result->data['published']['url'])->toBeNull()
        ->and($result->data['published']['image_version_id'])->toBe($image->id)
        ->and($result->data['draft']['mode'])->toBe('video')
        ->and($result->data['draft']['reason'])->toBe('video_mode_active')
        ->and($result->data['draft']['url'])->toBe($videoUrl)
        ->and($result->data['differs'])->toBeTrue();

    expectHeroStateLivePinsUnchanged($site, $page, $before);
});

it('reports draft_selection on the draft half and hero_version_active on published when they differ', function () {
    [$actor, $site, $page] = EditorSeeds::homeWithHero();
    $publishedImage = HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/published-hero-41.jpg',
        'source' => HeroVersionSource::AiGenerated,
        'placement' => ['text_zone' => 'middle-left', 'bg_position_y' => 41],
    ]);
    $draftImage = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/draft-hero-67.jpg',
        'source' => HeroVersionSource::UserUpload,
        'placement' => ['text_zone' => 'bottom-left', 'bg_position_y' => 67],
    ]);
    app(DraftAssetSelections::class)->setHero(
        $site,
        'home',
        'hero',
        $draftImage,
        $actor->id,
        ['text_zone' => 'top-right', 'bg_position_y' => 91],
    );
    $before = heroStateLivePins($site, $page);

    $result = runEffectiveHeroState($actor, $site, ['page_id' => $page->id, 'slot' => 'hero']);

    expect($result->ok)->toBeTrue()
        ->and($result->data['published'])->toMatchArray([
            'mode' => 'image',
            'source' => HeroVersionSource::AiGenerated->value,
            'version_id' => $publishedImage->id,
            'url' => 'https://cdn.example/published-hero-41.jpg',
            'reason' => 'hero_version_active',
            'placement' => ['text_zone' => 'middle-left', 'bg_position_y' => 41],
            'image_version_id' => $publishedImage->id,
            'image_url' => 'https://cdn.example/published-hero-41.jpg',
        ])
        ->and($result->data['draft'])->toMatchArray([
            'mode' => 'image',
            'source' => HeroVersionSource::UserUpload->value,
            'version_id' => $draftImage->id,
            'url' => 'https://cdn.example/draft-hero-67.jpg',
            'reason' => 'draft_selection',
            'placement' => ['text_zone' => 'top-right', 'bg_position_y' => 91],
            'image_version_id' => $draftImage->id,
            'image_url' => 'https://cdn.example/draft-hero-67.jpg',
        ])
        ->and($result->data['differs'])->toBeTrue()
        ->and($draftImage->fresh()->is_active)->toBeFalse()
        ->and($publishedImage->fresh()->is_active)->toBeTrue();

    expectHeroStateLivePinsUnchanged($site, $page, $before);
});

it('reports section_field with a null url when the blade would render the section background_image', function () {
    [$actor, $site, $page] = EditorSeeds::homeWithHero();
    putHeroSection($page, ['background_image' => 'https://cdn.example/section-only-background.jpg'], [
        'background_image' => null,
        'placeholder' => false,
    ]);
    $before = heroStateLivePins($site, $page->fresh());

    $result = runEffectiveHeroState($actor, $site, ['page_id' => $page->id]);

    expect($result->ok)->toBeTrue()
        ->and($result->data['published'])->toBe([
            'mode' => 'image',
            'source' => 'section_field',
            'version_id' => null,
            'url' => null,
            'reason' => 'section_field',
            'placement' => [],
            'image_version_id' => null,
            'image_url' => null,
        ])
        ->and($result->data['published']['reason'])->not->toBe('none')
        ->and($result->data['published']['url'])->not->toBe('https://cdn.example/section-only-background.jpg')
        ->and($result->data['differs'])->toBeFalse();

    expectHeroStateLivePinsUnchanged($site, $page, $before);
});

it('reports shared_service_hero for a shared-source service page', function () {
    [$actor, $site] = EditorSeeds::homeWithHero();
    $content = ['sections' => [
        ['type' => 'hero', 'title' => 'Emergency plumbing'],
    ]];
    $service = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'emergency-plumbing',
        'kind' => PageKind::Service,
        'hero_source' => 'shared',
        'content_data' => $content,
        'sort_order' => 1,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $revision = PageRevision::factory()->for($service, 'page')->create(['content_data' => $content]);
    $service->update(['published_revision_id' => $revision->id]);
    $shared = HeroVersion::factory()->for($site)->active()->create([
        'page_type' => '__shared_service_hero',
        'slot' => 'hero',
        'url' => 'https://cdn.example/shared-service-hero-55.jpg',
        'source' => HeroVersionSource::AiGenerated,
        'placement' => ['text_zone' => 'middle-right', 'bg_position_y' => 55],
    ]);
    $before = heroStateLivePins($site, $service->fresh());

    $result = runEffectiveHeroState($actor, $site, ['page_id' => $service->id]);

    expect($result->ok)->toBeTrue()
        ->and($result->data['published'])->toBe([
            'mode' => 'image',
            'source' => HeroVersionSource::AiGenerated->value,
            'version_id' => $shared->id,
            'url' => 'https://cdn.example/shared-service-hero-55.jpg',
            'reason' => 'shared_service_hero',
            'placement' => ['text_zone' => 'middle-right', 'bg_position_y' => 55],
            'image_version_id' => $shared->id,
            'image_url' => 'https://cdn.example/shared-service-hero-55.jpg',
        ])
        ->and($result->data['differs'])->toBeFalse();

    expectHeroStateLivePinsUnchanged($site, $service, $before);
});

it('reports snapshot_fallback from the latest preview when no version exists', function () {
    [$actor, $site, $page] = EditorSeeds::homeWithHero();
    Preview::factory()->for($site)->create([
        'snapshot' => [
            'hero_images' => [
                'home' => [
                    'url' => 'https://cdn.example/snapshot-home-hero-13.jpg',
                    'placement' => ['text_zone' => 'top-center', 'bg_position_y' => 13],
                ],
            ],
        ],
    ]);
    $before = heroStateLivePins($site, $page);

    $result = runEffectiveHeroState($actor, $site, ['page_id' => $page->id]);

    expect($result->ok)->toBeTrue()
        ->and($result->data['published'])->toBe([
            'mode' => 'image',
            'source' => 'snapshot',
            'version_id' => null,
            'url' => 'https://cdn.example/snapshot-home-hero-13.jpg',
            'reason' => 'snapshot_fallback',
            'placement' => ['text_zone' => 'top-center', 'bg_position_y' => 13],
            'image_version_id' => null,
            'image_url' => 'https://cdn.example/snapshot-home-hero-13.jpg',
        ])
        ->and($result->data['differs'])->toBeFalse();

    expectHeroStateLivePinsUnchanged($site, $page, $before);
});

it('reports placeholder while still carrying the version url', function () {
    [$actor, $site, $page] = EditorSeeds::homeWithHero();
    $image = HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/placeholder-still-carries-this.jpg',
        'source' => HeroVersionSource::UserUpload,
        'placement' => ['text_zone' => 'bottom-center', 'bg_position_y' => 72],
    ]);
    putHeroSection($page, ['placeholder' => true]);
    $before = heroStateLivePins($site, $page->fresh());

    $result = runEffectiveHeroState($actor, $site, ['page_id' => $page->id]);

    expect($result->ok)->toBeTrue()
        ->and($result->data['published'])->toBe([
            'mode' => 'image',
            'source' => HeroVersionSource::UserUpload->value,
            'version_id' => $image->id,
            'url' => 'https://cdn.example/placeholder-still-carries-this.jpg',
            'reason' => 'placeholder',
            'placement' => ['text_zone' => 'bottom-center', 'bg_position_y' => 72],
            'image_version_id' => $image->id,
            'image_url' => 'https://cdn.example/placeholder-still-carries-this.jpg',
        ])
        ->and($result->data['published']['reason'])->not->toBe('hero_version_active')
        ->and($result->data['differs'])->toBeFalse();

    expectHeroStateLivePinsUnchanged($site, $page, $before);
});

it('reports none when no version, snapshot, section image, video, or scene is present', function () {
    [$actor, $site, $page] = EditorSeeds::homeWithHero();

    $draftContent = $page->content_data ?? ['sections' => []];
    if (isset($draftContent['sections'][1]) && is_array($draftContent['sections'][1])) {
        $draftContent['sections'][1]['title'] = 'Draft-only CTA that must stay put';
    }
    $draftRevision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $draftContent]);
    $page->update(['draft_revision_id' => $draftRevision->id]);

    Preview::factory()->for($site)->create([
        'created_at' => now()->subDay(),
        'snapshot' => [
            'hero_images' => [
                'home' => ['url' => 'https://cdn.example/older-preview-must-not-win.jpg'],
            ],
        ],
    ]);
    Preview::factory()->for($site)->create([
        'created_at' => now(),
        'snapshot' => ['watermark_enabled' => true],
    ]);

    SiteDraftAssetSelection::query()->create([
        'site_id' => $site->id,
        'family' => 'hero',
        'page_type' => 'about',
        'slot' => 'hero',
        'version_id' => 4242,
        'mode' => 'on',
        'placement' => ['text_zone' => 'top-left'],
        'created_by_user_id' => $actor->id,
    ]);
    app(PublicPageCache::class)->invalidate($site);

    $before = heroStateLivePins($site, $page->fresh());
    $expectedNone = [
        'mode' => 'none',
        'source' => 'none',
        'version_id' => null,
        'url' => null,
        'reason' => 'none',
        'placement' => [],
        'image_version_id' => null,
        'image_url' => null,
    ];
    $expectedDraftNone = [
        'mode' => 'none',
        'source' => 'none',
        'version_id' => null,
        'url' => null,
        'reason' => 'none',
        'placement' => [],
        'image_version_id' => null,
        'image_url' => null,
    ];

    $result = runEffectiveHeroState($actor, $site, ['page_id' => $page->id]);

    expect($result->ok)->toBeTrue()
        ->and($result->data['published'])->toBe($expectedNone)
        ->and($result->data['draft'])->toBe($expectedDraftNone)
        ->and($result->data['differs'])->toBeFalse();

    expectHeroStateLivePinsUnchanged($site, $page, $before);
});

it('answers not_found for a page that is not on the site', function () {
    [$actor, $site] = EditorSeeds::homeWithHero();
    [, $foreignSite, $foreignPage] = EditorSeeds::homeWithHero();
    $before = heroStateLivePins($site, GeneratedPage::query()->where('site_id', $site->id)->firstOrFail());

    $result = runEffectiveHeroState($actor, $site, ['page_id' => $foreignPage->id]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('not_found')
        ->and($result->error['message'])->toBe('Page not found.')
        ->and($foreignPage->fresh()->site_id)->toBe($foreignSite->id);

    expectHeroStateLivePinsUnchanged($site, GeneratedPage::query()->where('site_id', $site->id)->firstOrFail(), $before);
});
