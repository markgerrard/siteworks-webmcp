<?php

use App\Enums\AgentRole;
use App\Enums\HeroVersionSource;
use App\Enums\LogoConceptSource;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\LogoConcept;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteDraftAssetSelection;
use App\Models\SiteMedia;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\DraftAssetSelections;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\PublicPageCache;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EditorSeeds;

beforeEach(function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
    $this->withoutVite();
    Storage::fake('s3');

    $this->seedEditorSite = function (array $extraSections = []): array {
        $user = User::factory()->staff(AgentRole::Agent)->create();
        $site = Site::factory()->create(['created_by_user_id' => $user->id]);
        $content = ['sections' => [
            ['type' => 'hero', 'title' => 'A'],
            ['type' => 'cta', 'title' => 'B'],
            ...$extraSections,
        ]];
        $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'content_data' => $content]);
        $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
        $page->update(['published_revision_id' => $revision->id]);

        return ['user' => $user, 'site' => $site, 'page' => $page->fresh()];
    };

    $this->runEditor = function (User $user, Site $site, string $operation, array $input): OperationResult {
        return app(EditorOperations::class)->run(
            new EditorContext($user, $site, ActorChannel::Webmcp),
            $operation,
            $input,
        );
    };

    $this->compositionRevision = function (Site $site): int {
        return (int) (SiteDraft::query()->where('site_id', $site->id)->value('admin_revision') ?? 0);
    };
});

it('list_image_versions lists hero versions in desc order with active and drafted flags', function () {
    ['user' => $user, 'site' => $site] = ($this->seedEditorSite)();
    $active = HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/hero-active.jpg',
        'source' => HeroVersionSource::AiGenerated,
    ]);
    $drafted = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/hero-draft.jpg',
        'source' => HeroVersionSource::UserUpload,
    ]);
    $newest = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/hero-new.jpg',
        'source' => HeroVersionSource::AiGenerated,
    ]);
    HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'intro',
        'url' => 'https://cdn.example/intro.jpg',
    ]);
    app(DraftAssetSelections::class)->setHero($site, 'home', 'hero', $drafted, $user->id);

    $result = ($this->runEditor)($user, $site, 'list_image_versions', [
        'scope' => 'hero',
        'page_type' => 'home',
        'slot' => 'hero',
    ]);

    expect($result->ok)->toBeTrue()
        ->and(array_column($result->data['versions'], 'id'))->toBe([$newest->id, $drafted->id, $active->id])
        ->and($result->data['versions'][0])->toMatchArray([
            'id' => $newest->id,
            'url' => $newest->url,
            'source' => 'ai_generated',
            'active' => false,
            'drafted' => false,
        ])
        ->and($result->data['versions'][1])->toMatchArray([
            'id' => $drafted->id,
            'url' => $drafted->url,
            'source' => 'user_upload',
            'active' => false,
            'drafted' => true,
        ])
        ->and($result->data['versions'][2])->toMatchArray([
            'id' => $active->id,
            'url' => $active->url,
            'source' => 'ai_generated',
            'active' => true,
            'drafted' => false,
        ])
        ->and($result->data['versions'][0]['created_at'])->toBe($newest->created_at->toIso8601String());
});

it('list_image_versions lists logo versions in desc order with active and drafted flags', function () {
    ['user' => $user, 'site' => $site] = ($this->seedEditorSite)();
    $selected = LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/selected-logo.png',
        'source' => LogoConceptSource::Generated,
    ]);
    $drafted = LogoConcept::factory()->for($site)->create([
        'path' => 'logos/draft-logo.png',
        'source' => LogoConceptSource::Uploaded,
    ]);
    $newest = LogoConcept::factory()->for($site)->create([
        'path' => 'logos/new-logo.png',
        'source' => LogoConceptSource::Generated,
    ]);
    app(DraftAssetSelections::class)->setLogo($site, $drafted, $user->id);

    $result = ($this->runEditor)($user, $site, 'list_image_versions', [
        'scope' => 'logo',
    ]);

    expect($result->ok)->toBeTrue()
        ->and(array_column($result->data['versions'], 'id'))->toBe([$newest->id, $drafted->id, $selected->id])
        ->and($result->data['versions'][0])->toMatchArray([
            'id' => $newest->id,
            'url' => $newest->url(),
            'source' => 'generated',
            'active' => false,
            'drafted' => false,
        ])
        ->and($result->data['versions'][1])->toMatchArray([
            'id' => $drafted->id,
            'url' => $drafted->url(),
            'source' => 'uploaded',
            'active' => false,
            'drafted' => true,
        ])
        ->and($result->data['versions'][2])->toMatchArray([
            'id' => $selected->id,
            'url' => $selected->url(),
            'source' => 'generated',
            'active' => true,
            'drafted' => false,
        ]);
});

it('list_image_versions lists media versions in desc order with active flag from the field draft value', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)();
    $older = SiteMedia::factory()->for($site)->create([
        'url' => 'https://cdn.example/media-old.jpg',
        'source' => 'upload',
    ]);
    $current = SiteMedia::factory()->for($site)->create([
        'url' => 'https://cdn.example/media-current.jpg',
        'source' => 'agent_uploaded',
    ]);
    $revision = PageRevision::query()->findOrFail($page->published_revision_id);
    $content = $revision->content_data;
    $content['sections'][0]['background_image'] = $current->url;
    $revision->update(['content_data' => $content]);

    $result = ($this->runEditor)($user, $site, 'list_image_versions', [
        'scope' => 'media',
        'page_id' => $page->id,
        'field_path' => 'background_image',
    ]);

    expect($result->ok)->toBeTrue()
        ->and(array_column($result->data['versions'], 'id'))->toBe([$current->id, $older->id])
        ->and($result->data['versions'][0])->toMatchArray([
            'id' => $current->id,
            'url' => $current->url,
            'source' => 'agent_uploaded',
            'active' => true,
            'drafted' => false,
        ])
        ->and($result->data['versions'][1])->toMatchArray([
            'id' => $older->id,
            'url' => $older->url,
            'source' => 'upload',
            'active' => false,
            'drafted' => false,
        ]);
});

it('restore_image_version writes a hero draft selection and leaves is_active, published_revision_id, and public cache generation untouched', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)();
    $active = HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
    ]);
    $candidate = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
    ]);
    $published = $page->published_revision_id;
    $cache = app(PublicPageCache::class);
    $generation = $cache->generation($site);

    $result = ($this->runEditor)($user, $site, 'restore_image_version', [
        'scope' => 'hero',
        'version_id' => $candidate->id,
        'composition_revision' => ($this->compositionRevision)($site),
        'page_type' => 'home',
        'slot' => 'hero',
    ]);

    $selection = SiteDraftAssetSelection::query()
        ->where('site_id', $site->id)
        ->where('family', 'hero')
        ->first();

    expect($result->ok)->toBeTrue()
        ->and($selection)->not->toBeNull()
        ->and($selection->version_id)->toBe($candidate->id)
        ->and($selection->page_type)->toBe('home')
        ->and($selection->slot)->toBe('hero')
        ->and($active->fresh()->is_active)->toBeTrue()
        ->and($candidate->fresh()->is_active)->toBeFalse()
        ->and($page->fresh()->published_revision_id)->toBe($published)
        ->and($cache->generation($site))->toBe($generation);
});

it('restore_image_version returns stale_revision with current_composition_revision and writes no selection', function () {
    ['user' => $user, 'site' => $site] = ($this->seedEditorSite)();
    $candidate = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
    ]);
    $current = ($this->compositionRevision)($site);

    $result = ($this->runEditor)($user, $site, 'restore_image_version', [
        'scope' => 'hero',
        'version_id' => $candidate->id,
        'composition_revision' => $current + 99,
        'page_type' => 'home',
        'slot' => 'hero',
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('stale_revision')
        ->and($result->error['current_composition_revision'])->toBe($current)
        ->and(SiteDraftAssetSelection::query()->where('site_id', $site->id)->exists())->toBeFalse()
        ->and($candidate->fresh()->is_active)->toBeFalse();
});

it('restore_image_version returns not_found for a foreign hero version id', function () {
    ['user' => $user, 'site' => $site] = ($this->seedEditorSite)();
    $foreign = HeroVersion::factory()->create(['page_type' => 'home', 'slot' => 'hero']);

    $result = ($this->runEditor)($user, $site, 'restore_image_version', [
        'scope' => 'hero',
        'version_id' => $foreign->id,
        'composition_revision' => ($this->compositionRevision)($site),
        'page_type' => 'home',
        'slot' => 'hero',
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('not_found')
        ->and(SiteDraftAssetSelection::query()->where('site_id', $site->id)->exists())->toBeFalse();
});

it('select_logo writes a draft selection and never flips is_selected', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)();
    $selected = LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/live.png',
    ]);
    $candidate = LogoConcept::factory()->for($site)->create([
        'path' => 'logos/draft.png',
    ]);
    $published = $page->published_revision_id;
    $cache = app(PublicPageCache::class);
    $generation = $cache->generation($site);

    $result = ($this->runEditor)($user, $site, 'select_logo', [
        'concept_id' => $candidate->id,
        'composition_revision' => ($this->compositionRevision)($site),
    ]);

    $selection = SiteDraftAssetSelection::query()
        ->where('site_id', $site->id)
        ->where('family', 'logo')
        ->first();

    expect($result->ok)->toBeTrue()
        ->and($selection)->not->toBeNull()
        ->and($selection->version_id)->toBe($candidate->id)
        ->and($selected->fresh()->is_selected)->toBeTrue()
        ->and($candidate->fresh()->is_selected)->toBeFalse()
        ->and($page->fresh()->published_revision_id)->toBe($published)
        ->and($cache->generation($site))->toBe($generation);
});

it('select_logo returns not_found for a foreign concept_id', function () {
    ['user' => $user, 'site' => $site] = ($this->seedEditorSite)();
    $foreign = LogoConcept::factory()->create(['path' => 'logos/other.png']);

    $result = ($this->runEditor)($user, $site, 'select_logo', [
        'concept_id' => $foreign->id,
        'composition_revision' => ($this->compositionRevision)($site),
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('not_found')
        ->and(SiteDraftAssetSelection::query()->where('site_id', $site->id)->exists())->toBeFalse();
});

it('restore_media_version sets the field, advances the draft, and leaves published_revision_id and public cache generation unchanged', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)();
    $media = SiteMedia::factory()->for($site)->create([
        'url' => 'https://cdn.example/restored.jpg',
    ]);
    $published = $page->published_revision_id;
    $cache = app(PublicPageCache::class);
    $generation = $cache->generation($site);

    $result = ($this->runEditor)($user, $site, 'restore_media_version', [
        'page_id' => $page->id,
        'stored_index' => 0,
        'field_path' => 'background_image',
        'media_id' => $media->id,
        'revision_base' => $published,
    ]);

    $page->refresh();
    $draft = PageRevision::query()->find($page->draft_revision_id);

    expect($result->ok)->toBeTrue()
        ->and($result->data['draft_revision_id'])->toBe($page->draft_revision_id)
        ->and($page->draft_revision_id)->not->toBeNull()
        ->and($page->draft_revision_id)->not->toBe($published)
        ->and($draft->content_data['sections'][0]['background_image'])->toBe($media->url)
        ->and($page->published_revision_id)->toBe($published)
        ->and($cache->generation($site))->toBe($generation);
});

it('restore_media_version returns not_found for a foreign media_id on a non-_id image field', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)();
    $foreign = SiteMedia::factory()->create(['url' => 'https://cdn.example/foreign.jpg']);
    $published = $page->published_revision_id;

    $result = ($this->runEditor)($user, $site, 'restore_media_version', [
        'page_id' => $page->id,
        'stored_index' => 0,
        'field_path' => 'background_image',
        'media_id' => $foreign->id,
        'revision_base' => $published,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('not_found')
        ->and($page->fresh()->draft_revision_id)->toBeNull()
        ->and($page->fresh()->published_revision_id)->toBe($published);
});

it('restore_media_version writes media_id onto an *_id image field and rejects a foreign id as not_found', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)([
        ['type' => 'project_about', 'title' => 'About the job'],
    ]);
    $media = SiteMedia::factory()->for($site)->create(['url' => 'https://cdn.example/about.jpg']);
    $foreign = SiteMedia::factory()->create(['url' => 'https://cdn.example/other-about.jpg']);
    $published = $page->published_revision_id;

    $ok = ($this->runEditor)($user, $site, 'restore_media_version', [
        'page_id' => $page->id,
        'stored_index' => 2,
        'field_path' => 'image_id',
        'media_id' => $media->id,
        'revision_base' => $published,
    ]);

    $page->refresh();
    $draft = PageRevision::query()->find($page->draft_revision_id);

    expect($ok->ok)->toBeTrue()
        ->and($draft->content_data['sections'][2]['image_id'])->toBe($media->id)
        ->and($page->published_revision_id)->toBe($published);

    $denied = ($this->runEditor)($user, $site, 'restore_media_version', [
        'page_id' => $page->id,
        'stored_index' => 2,
        'field_path' => 'image_id',
        'media_id' => $foreign->id,
        'revision_base' => $page->draft_revision_id,
    ]);

    expect($denied->ok)->toBeFalse()
        ->and($denied->error['code'])->toBe('not_found')
        ->and(PageRevision::query()->find($page->fresh()->draft_revision_id)->content_data['sections'][2]['image_id'])->toBe($media->id);
});

it('restore_media_version refuses a non-image field and list_image_versions media active honours stored_index', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)();
    $media = SiteMedia::factory()->for($site)->create(['url' => 'https://cdn.example/x.jpg']);
    $result = ($this->runEditor)($user, $site, 'restore_media_version', [
        'page_id' => $page->id, 'stored_index' => 0, 'field_path' => 'title', 'media_id' => $media->id, 'revision_base' => $page->published_revision_id,
    ]);
    expect($result->ok)->toBeFalse()->and($result->error['code'])->toBe('unsupported_field');

    // Discriminating: put the media on section 0's background_image; asking about index 1 must NOT report it active.
    $restored = ($this->runEditor)($user, $site, 'restore_media_version', [
        'page_id' => $page->id, 'stored_index' => 0, 'field_path' => 'background_image', 'media_id' => $media->id, 'revision_base' => $page->published_revision_id,
    ]);
    expect($restored->ok)->toBeTrue();
    $at0 = ($this->runEditor)($user, $site, 'list_image_versions', ['scope' => 'media', 'page_id' => $page->id, 'field_path' => 'background_image', 'stored_index' => 0]);
    $at1 = ($this->runEditor)($user, $site, 'list_image_versions', ['scope' => 'media', 'page_id' => $page->id, 'field_path' => 'background_image', 'stored_index' => 1]);
    $activeAt = fn ($r) => collect($r->data['versions'])->firstWhere('id', $media->id)['active'] ?? null;
    expect($activeAt($at0))->toBeTrue()->and($activeAt($at1))->toBeFalse();
    expect(($this->runEditor)($user, $site, 'restore_media_version', ['page_id' => $page->id, 'stored_index' => 9, 'field_path' => 'background_image', 'media_id' => $media->id, 'revision_base' => $page->fresh()->draft_revision_id])->error['code'])->toBe('validation');
});

it('media scope refuses an archived or foreign page instead of listing the whole site', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)();
    SiteMedia::factory()->for($site)->create(['url' => 'https://cdn.example/m.jpg']);
    $foreign = \App\Models\GeneratedPage::factory()->for(\App\Models\Site::factory()->create())->create();

    expect(($this->runEditor)($user, $site, 'list_image_versions', ['scope' => 'media', 'page_id' => $foreign->id, 'field_path' => 'background_image'])->error['code'])->toBe('not_found');

    $page->update(['archived_at' => now()]);
    expect(($this->runEditor)($user, $site, 'list_image_versions', ['scope' => 'media', 'page_id' => $page->id, 'field_path' => 'background_image'])->error['code'])->toBe('not_found');
});

it('lists hero versions for a page_id, matching the picker query', function () {
    [$user, $site, $page] = EditorSeeds::homeWithHero();

    $active = HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/hero-active.jpg',
        'source' => HeroVersionSource::AiGenerated,
    ]);
    $drafted = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/hero-draft.jpg',
        'source' => HeroVersionSource::UserUpload,
    ]);
    $newest = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/hero-new.jpg',
        'source' => HeroVersionSource::AiGenerated,
    ]);
    HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'intro',
        'url' => 'https://cdn.example/intro.jpg',
    ]);
    app(DraftAssetSelections::class)->setHero($site, 'home', 'hero', $drafted, $user->id);

    $byPageId = EditorSeeds::run($user, $site, 'list_image_versions', ['scope' => 'hero', 'page_id' => $page->id]);
    $byPageType = EditorSeeds::run($user, $site, 'list_image_versions', ['scope' => 'hero', 'page_type' => 'home', 'slot' => 'hero']);

    $expectedIds = HeroVersion::where('site_id', $site->id)->where('page_type', 'home')
        ->where('slot', 'hero')->orderByDesc('id')->pluck('id')->all();

    expect($byPageId->ok)->toBeTrue();
    expect(array_column($byPageId->data['versions'], 'id'))
        ->toBe(array_column($byPageType->data['versions'], 'id'))
        ->toBe($expectedIds);
});

it('rejects a foreign page_id for the hero scope', function () {
    [$user, $site] = EditorSeeds::homeWithHero();
    $foreign = GeneratedPage::factory()->for(Site::factory()->create())->create();

    $result = EditorSeeds::run($user, $site, 'list_image_versions', ['scope' => 'hero', 'page_id' => $foreign->id]);

    expect($result->ok)->toBeFalse();
    expect($result->error['code'])->toBe('not_found');
});

it('rejects page_id and page_type that disagree', function () {
    [$user, $site, $page] = EditorSeeds::homeWithHero();

    $result = EditorSeeds::run($user, $site, 'list_image_versions', [
        'scope' => 'hero',
        'page_id' => $page->id,
        'page_type' => 'services',
    ]);

    expect($result->ok)->toBeFalse();
    expect($result->error['code'])->toBe('validation');
});
