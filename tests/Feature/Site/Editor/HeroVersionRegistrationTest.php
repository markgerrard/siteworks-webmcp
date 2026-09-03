<?php

use App\Enums\HeroVersionSource;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\HeroVideoVersion;
use App\Models\LogoConcept;
use App\Models\Preview;
use App\Models\SiteMedia;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\DraftAssetSelections;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\PublicPageCache;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EditorSeeds;

beforeEach(function () {
    $this->withoutVite();
    Storage::fake(config('filesystems.default'));
    config(['editor.operations.enabled' => true, 'editor.agent_tools.enabled' => true]);
});

it('registers an uploaded hero-family asset as an inactive, drafted hero version', function (string $sectionType, string $pageType) {
    config(["site_sections.{$sectionType}.fields.background_image" => ['type' => 'image']]);
    [$user, $site, $home] = EditorSeeds::homeWithHero();
    $content = $home->publishedRevision->content_data;
    $content['sections'][0]['type'] = $sectionType;
    $page = $pageType === 'home'
        ? $home
        : GeneratedPage::factory()->for($site)->create([
            'page_type' => $pageType,
            'content_data' => $content,
            'status' => PageStatus::Published,
        ]);
    $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
    $page->update(['content_data' => $content, 'published_revision_id' => $revision->id]);
    $page = $page->fresh();
    $preview = Preview::factory()->for($site)->create(['snapshot' => ['marker' => 'unchanged']]);
    $publishedRevisionId = $page->published_revision_id;
    $activeHeroCount = EditorSeeds::activeHeroCount($site);
    $selectedLogoCount = LogoConcept::query()->where('site_id', $site->id)->where('is_selected', true)->count();
    $activeHeroVideoCount = HeroVideoVersion::query()->where('site_id', $site->id)->where('is_active', true)->count();
    $homeHeroVideoEnabled = $site->fresh()->home_hero_video_enabled;
    $cacheGeneration = app(PublicPageCache::class)->generation($site);
    $compositionRevision = (int) SiteDraft::query()->where('site_id', $site->id)->value('admin_revision');

    $result = app(EditorOperations::class)->run(
        new EditorContext($user, $site, ActorChannel::Webmcp),
        'upload_image',
        [
            'data_base64' => EditorSeeds::pngBase64(),
            'composition_revision' => $compositionRevision,
            'page_id' => $page->id,
            'stored_index' => 0,
            'field_path' => 'background_image',
            'revision_base' => $page->draft_revision_id ?? $page->published_revision_id,
            'structure_epoch' => $page->structure_epoch,
        ],
    );

    expect($result->ok)->toBeTrue();
    expect($result->data)->toHaveKeys(['media_id', 'url', 'hero_version_id']);

    $media = SiteMedia::findOrFail($result->data['media_id']);
    $version = HeroVersion::query()->findOrFail($result->data['hero_version_id']);
    expect($version->site_id)->toBe($site->id);
    expect($version->url)->toBe($media->url);
    expect($version->placement)->toBeNull();
    expect($version->page_type)->toBe($pageType);
    expect($version->slot)->toBe('hero');
    expect($version->is_active)->toBeFalse();
    expect($version->source)->toBe(HeroVersionSource::UserUpload);
    expect(app(DraftAssetSelections::class)->heroFor($site, $pageType, 'hero')?->id)->toBe($version->id);

    expect($page->fresh()->published_revision_id)->toBe($publishedRevisionId);
    expect(EditorSeeds::activeHeroCount($site))->toBe($activeHeroCount);
    expect(LogoConcept::query()->where('site_id', $site->id)->where('is_selected', true)->count())->toBe($selectedLogoCount);
    expect(HeroVideoVersion::query()->where('site_id', $site->id)->where('is_active', true)->count())->toBe($activeHeroVideoCount);
    expect($site->fresh()->home_hero_video_enabled)->toBe($homeHeroVideoEnabled);
    expect($preview->fresh()->snapshot)->toBe(['marker' => 'unchanged']);
    expect(app(PublicPageCache::class)->generation($site))->toBe($cacheGeneration);
})->with([
    'hero home page' => ['hero', 'home'],
    'compact hero about page' => ['hero_compact', 'about'],
    'projects hero projects page' => ['projects_hero', 'projects'],
    'project detail hero detail page' => ['project_detail_hero', 'projects/kitchen'],
]);

it('registers nothing for a non-hero image field', function () {
    [$user, $site, $page] = EditorSeeds::homeWithHero();
    $content = $page->publishedRevision->content_data;
    $content['sections'][] = [
        'type' => 'team',
        'items' => [['name' => 'Alex', 'image' => null]],
    ];
    $page->publishedRevision->update(['content_data' => $content]);
    $page->update(['content_data' => $content]);
    $before = HeroVersion::query()->where('site_id', $site->id)->count();

    $result = app(EditorOperations::class)->run(
        new EditorContext($user, $site, ActorChannel::Webmcp),
        'upload_image',
        [
            'data_base64' => EditorSeeds::pngBase64(),
            'composition_revision' => 0,
            'page_id' => $page->id,
            'stored_index' => 3,
            'field_path' => 'items.0.image',
            'revision_base' => $page->published_revision_id,
            'structure_epoch' => $page->structure_epoch,
        ],
    );

    expect($result->ok)->toBeTrue();
    expect($result->data)->not->toHaveKey('hero_version_id');
    expect(HeroVersion::query()->where('site_id', $site->id)->count())->toBe($before);
    expect($page->fresh()->draftRevision->content_data['sections'][3]['items'][0]['image'])->toBe($result->data['url']);
});
