<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\HeroVideoVersion;
use App\Models\LogoConcept;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\EditorOperationLog;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\User;
use App\Services\Site\CompositionService;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\PublicPageCache;
use Tests\Support\EditorSeeds;

beforeEach(function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
    $this->withoutVite();
});

/**
 * @return array{
 *     user: User,
 *     stranger: User,
 *     site: Site,
 *     home: GeneratedPage,
 *     about: GeneratedPage,
 *     nested: GeneratedPage,
 *     preview: Preview,
 *     drafts: array<string, mixed>
 * }
 */
function seedPageSettingsSite(): array
{
    $user = User::factory()->staff(AgentRole::Agent)->create();
    $stranger = User::factory()->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $user->id,
        'home_hero_video_enabled' => true,
    ]);

    $homeContent = [
        'sections' => [['type' => 'hero', 'title' => 'Home hero']],
        'meta' => ['seo' => [
            'meta_title' => 'Existing home title',
            'meta_description' => 'Existing home description.',
        ]],
    ];
    $home = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'nav_label' => 'Home-LIVE',
        'content_data' => $homeContent,
        'sort_order' => 0,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $homeRevision = PageRevision::factory()->for($home, 'page')->create(['content_data' => $homeContent]);
    $home->update(['published_revision_id' => $homeRevision->id]);

    $aboutContent = [
        'sections' => [['type' => 'cta', 'title' => 'About cta']],
        'meta' => ['seo' => [
            'meta_title' => 'Existing about title',
            'meta_description' => 'Existing about description.',
        ]],
    ];
    $about = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'about',
        'nav_label' => 'About-LIVE',
        'content_data' => $aboutContent,
        'sort_order' => 1,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $aboutRevision = PageRevision::factory()->for($about, 'page')->create(['content_data' => $aboutContent]);
    $about->update(['published_revision_id' => $aboutRevision->id]);

    $nestedContent = [
        'sections' => [['type' => 'cta', 'title' => 'Service cta']],
        'meta' => ['seo' => [
            'meta_title' => 'Existing service title',
            'meta_description' => 'Existing service description.',
        ]],
    ];
    $nested = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'services/roofing',
        'nav_label' => 'Roofing-LIVE',
        'content_data' => $nestedContent,
        'sort_order' => 2,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $nestedRevision = PageRevision::factory()->for($nested, 'page')->create(['content_data' => $nestedContent]);
    $nested->update(['published_revision_id' => $nestedRevision->id]);

    app(CompositionService::class)->getOrCreateDraft($site);
    $draft = SiteDraft::query()->where('site_id', $site->id)->firstOrFail();
    $composition = $draft->composition;
    $composition['nav']['items'] = [
        ['type' => 'page', 'page_id' => $about->id, 'label' => 'About-DRAFT'],
        ['type' => 'group', 'label' => 'Services', 'children' => [
            ['type' => 'page', 'page_id' => $nested->id, 'label' => 'Roofing-DRAFT'],
        ]],
    ];
    $draft->composition = $composition;
    $draft->save();

    HeroVersion::factory()->for($site)->active()->create();
    LogoConcept::factory()->for($site)->selected()->create();
    HeroVideoVersion::factory()->for($site)->active()->create();
    $preview = Preview::factory()->for($site)->create([
        'snapshot' => ['marker' => 'page-settings-oracle'],
    ]);
    EditorSeeds::exposeAsInternal($site);

    return [
        'user' => $user,
        'stranger' => $stranger,
        'site' => $site->fresh(),
        'home' => $home->fresh(),
        'about' => $about->fresh(),
        'nested' => $nested->fresh(),
        'preview' => $preview->fresh(),
        'drafts' => draftsLawSnapshot($site, $preview->fresh()),
    ];
}

/**
 * @return array<string, mixed>
 */
function draftsLawSnapshot(Site $site, Preview $preview): array
{
    return [
        'published_revision_ids' => GeneratedPage::query()
            ->where('site_id', $site->id)
            ->orderBy('id')
            ->pluck('published_revision_id', 'id')
            ->all(),
        'active_heroes' => HeroVersion::query()->where('site_id', $site->id)->where('is_active', true)->pluck('id')->all(),
        'selected_logos' => LogoConcept::query()->where('site_id', $site->id)->where('is_selected', true)->pluck('id')->all(),
        'active_hero_videos' => HeroVideoVersion::query()->where('site_id', $site->id)->where('is_active', true)->pluck('id')->all(),
        'home_hero_video_enabled' => $site->fresh()->home_hero_video_enabled,
        'snapshot' => $preview->fresh()->snapshot,
        'cache' => app(PublicPageCache::class)->generation($site),
    ];
}

function assertDraftsLawUnchanged(array $before, Site $site, Preview $preview): void
{
    expect(draftsLawSnapshot($site, $preview->fresh()))->toBe($before);
}

function runPageSettings(User $user, Site $site, array $input): OperationResult
{
    return app(EditorOperations::class)->run(
        new EditorContext($user, $site, ActorChannel::Webmcp),
        'update_page_settings',
        $input,
    );
}

function runNavLabel(User $user, Site $site, array $input): OperationResult
{
    return app(EditorOperations::class)->run(
        new EditorContext($user, $site, ActorChannel::Webmcp),
        'set_nav_label',
        $input,
    );
}

function pageRevisionBytes(GeneratedPage $page): array
{
    $fresh = $page->fresh();
    $revisionId = $fresh->draft_revision_id ?? $fresh->published_revision_id;

    return [
        'draft_revision_id' => $fresh->draft_revision_id,
        'published_revision_id' => $fresh->published_revision_id,
        'content' => PageRevision::query()->find($revisionId)?->content_data,
        'page_content' => $fresh->content_data,
    ];
}

function compositionBytes(Site $site): array
{
    $draft = SiteDraft::query()->where('site_id', $site->id)->first();

    return [
        'admin_revision' => $draft?->admin_revision,
        'composition' => $draft?->composition,
    ];
}

it('writes meta_title and meta_description into the page revision seo block', function () {
    $seed = seedPageSettingsSite();
    $expectedTitle = 'Roofing in Wigan | Distinct Title';
    $expectedDescription = 'A distinct description used as the independent SEO oracle.';
    $published = $seed['home']->published_revision_id;
    $compositionBefore = compositionBytes($seed['site']);

    $result = runPageSettings($seed['user'], $seed['site'], [
        'page_id' => $seed['home']->id,
        'meta_title' => $expectedTitle,
        'meta_description' => $expectedDescription,
        'revision_base' => $published,
    ]);

    $home = $seed['home']->fresh();
    $revision = PageRevision::query()->find($home->draft_revision_id);

    expect($result->ok)->toBeTrue()
        ->and($home->published_revision_id)->toBe($published)
        ->and($home->draft_revision_id)->not->toBeNull()
        ->and($home->draft_revision_id)->not->toBe($published)
        ->and($revision?->content_data['meta']['seo']['meta_title'])->toBe($expectedTitle)
        ->and($revision?->content_data['meta']['seo']['meta_description'])->toBe($expectedDescription)
        ->and($result->receipt?->newRevision)->toBe($home->draft_revision_id)
        ->and(compositionBytes($seed['site'])['composition'])->toBe($compositionBefore['composition']);

    assertDraftsLawUnchanged($seed['drafts'], $seed['site'], $seed['preview']);
});

it('keeps an omitted seo field and addresses the page, not the site', function () {
    $seed = seedPageSettingsSite();
    $expectedTitle = 'Only the title changes on this call';

    $result = runPageSettings($seed['user'], $seed['site'], [
        'page_id' => $seed['home']->id,
        'meta_title' => $expectedTitle,
        'revision_base' => $seed['home']->published_revision_id,
        'composition_revision' => 999_999,
    ]);

    $revision = PageRevision::query()->find($seed['home']->fresh()->draft_revision_id);

    expect($result->ok)->toBeTrue()
        ->and(OperationRegistry::discover()->get('update_page_settings')->address())->toBe('page')
        ->and($revision?->content_data['meta']['seo']['meta_title'])->toBe($expectedTitle)
        ->and($revision?->content_data['meta']['seo']['meta_description'])->toBe('Existing home description.');
});

it('accepts a meta_description of 155 characters and rejects 156 without writing', function () {
    $seed = seedPageSettingsSite();
    $allowed = str_repeat('d', 155);
    $rejected = str_repeat('d', 156);
    $before = pageRevisionBytes($seed['home']);
    $compositionBefore = compositionBytes($seed['site']);

    $ok = runPageSettings($seed['user'], $seed['site'], [
        'page_id' => $seed['home']->id,
        'meta_description' => $allowed,
        'revision_base' => $seed['home']->published_revision_id,
    ]);

    expect($ok->ok)->toBeTrue()
        ->and(PageRevision::query()->find($seed['home']->fresh()->draft_revision_id)?->content_data['meta']['seo']['meta_description'])->toBe($allowed);

    $page = $seed['home']->fresh();
    $afterAllowed = pageRevisionBytes($page);
    $compositionAfterAllowed = compositionBytes($seed['site']);

    $fail = runPageSettings($seed['user'], $seed['site'], [
        'page_id' => $page->id,
        'meta_description' => $rejected,
        'revision_base' => $page->draft_revision_id,
    ]);

    expect($fail->ok)->toBeFalse()
        ->and($fail->error['code'])->toBe('validation')
        ->and($fail->error['fields'])->toHaveKey('meta_description')
        ->and(pageRevisionBytes($page->fresh()))->toBe($afterAllowed)
        ->and(compositionBytes($seed['site']))->toBe($compositionAfterAllowed)
        ->and($before['published_revision_id'])->toBe($seed['home']->published_revision_id);
});

it('emits no warning at 60 title characters and exactly one meta_title_long warning at 61', function () {
    $seed = seedPageSettingsSite();
    $atLimit = str_repeat('t', 60);
    $overLimit = str_repeat('t', 61);

    $quiet = runPageSettings($seed['user'], $seed['site'], [
        'page_id' => $seed['home']->id,
        'meta_title' => $atLimit,
        'revision_base' => $seed['home']->published_revision_id,
    ]);

    expect($quiet->ok)->toBeTrue()
        ->and(array_column($quiet->receipt?->warnings ?? [], 'code'))->not->toContain('meta_title_long')
        ->and(PageRevision::query()->find($seed['home']->fresh()->draft_revision_id)?->content_data['meta']['seo']['meta_title'])->toBe($atLimit);

    $warned = runPageSettings($seed['user'], $seed['site'], [
        'page_id' => $seed['home']->id,
        'meta_title' => $overLimit,
        'revision_base' => $seed['home']->fresh()->draft_revision_id,
    ]);

    $longWarnings = array_values(array_filter(
        $warned->receipt?->warnings ?? [],
        fn (array $warning): bool => $warning['code'] === 'meta_title_long',
    ));

    expect($warned->ok)->toBeTrue()
        ->and($longWarnings)->toHaveCount(1)
        ->and(PageRevision::query()->find($seed['home']->fresh()->draft_revision_id)?->content_data['meta']['seo']['meta_title'])->toBe($overLimit);
});

it('rejects {key} with validation naming its prerequisite and writes nothing', function (string $key, string $prerequisiteNeedle) {
    $seed = seedPageSettingsSite();
    $pageBefore = pageRevisionBytes($seed['home']);
    $compositionBefore = compositionBytes($seed['site']);

    $result = runPageSettings($seed['user'], $seed['site'], [
        'page_id' => $seed['home']->id,
        'meta_title' => 'Would-be title if this were silently applied',
        $key => 'agent-supplied-value',
        'revision_base' => $seed['home']->published_revision_id,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($result->error['fields'])->toHaveKey($key)
        ->and(strtolower($result->error['message'].json_encode($result->error['fields'])))->toContain(strtolower($prerequisiteNeedle))
        ->and(pageRevisionBytes($seed['home']->fresh()))->toBe($pageBefore)
        ->and(compositionBytes($seed['site']))->toBe($compositionBefore);

    assertDraftsLawUnchanged($seed['drafts'], $seed['site'], $seed['preview']);
})->with([
    'slug' => ['slug', 'redirects'],
    'page_type' => ['page_type', 'page_type'],
    'status' => ['status', 'status'],
    'visibility' => ['visibility', 'status'],
    'canonical_url' => ['canonical_url', 'column'],
    'social_image' => ['social_image', 'column'],
]);

it('writes the drafted nav item label and leaves the live column untouched', function () {
    $seed = seedPageSettingsSite();
    $expectedLabel = 'About-NEW';
    $liveBefore = $seed['about']->nav_label;
    $compositionBefore = (int) SiteDraft::query()->where('site_id', $seed['site']->id)->value('admin_revision');

    $result = runNavLabel($seed['user'], $seed['site'], [
        'page_id' => $seed['about']->id,
        'label' => $expectedLabel,
        'composition_revision' => $compositionBefore,
    ]);

    $draft = SiteDraft::query()->where('site_id', $seed['site']->id)->firstOrFail();
    $items = $draft->composition['nav']['items'];
    $aboutItem = collect($items)->firstWhere('page_id', $seed['about']->id);

    expect($result->ok)->toBeTrue()
        ->and(OperationRegistry::discover()->get('set_nav_label')->address())->toBe('site')
        ->and($seed['about']->fresh()->nav_label)->toBe($liveBefore)
        ->and($liveBefore)->toBe('About-LIVE')
        ->and($aboutItem['label'])->toBe($expectedLabel)
        ->and((int) $draft->admin_revision)->toBe($compositionBefore + 1)
        ->and($result->state->compositionRevision)->toBe($compositionBefore + 1)
        ->and($result->receipt?->newRevision)->toBe($compositionBefore + 1);

    assertDraftsLawUnchanged($seed['drafts'], $seed['site'], $seed['preview']);
});

it('relabels a nested nav child by page_id without touching the live column', function () {
    $seed = seedPageSettingsSite();
    $expectedLabel = 'Roofing-NEW';
    $liveBefore = $seed['nested']->nav_label;
    $before = (int) SiteDraft::query()->where('site_id', $seed['site']->id)->value('admin_revision');

    $result = runNavLabel($seed['user'], $seed['site'], [
        'page_id' => $seed['nested']->id,
        'label' => $expectedLabel,
        'composition_revision' => $before,
    ]);

    $items = SiteDraft::query()->where('site_id', $seed['site']->id)->firstOrFail()->composition['nav']['items'];
    $child = $items[1]['children'][0];

    expect($result->ok)->toBeTrue()
        ->and($child['label'])->toBe($expectedLabel)
        ->and($seed['nested']->fresh()->nav_label)->toBe($liveBefore)
        ->and($liveBefore)->toBe('Roofing-LIVE');
});

it('accepts a nav label of 30 characters and rejects 31 without writing', function () {
    $seed = seedPageSettingsSite();
    $allowed = str_repeat('L', 30);
    $rejected = str_repeat('L', 31);
    $before = (int) SiteDraft::query()->where('site_id', $seed['site']->id)->value('admin_revision');
    $liveBefore = $seed['about']->nav_label;
    $compositionBefore = compositionBytes($seed['site']);

    $ok = runNavLabel($seed['user'], $seed['site'], [
        'page_id' => $seed['about']->id,
        'label' => $allowed,
        'composition_revision' => $before,
    ]);

    expect($ok->ok)->toBeTrue()
        ->and(collect(SiteDraft::query()->where('site_id', $seed['site']->id)->first()->composition['nav']['items'])->firstWhere('page_id', $seed['about']->id)['label'])->toBe($allowed)
        ->and($seed['about']->fresh()->nav_label)->toBe($liveBefore);

    $afterAllowed = compositionBytes($seed['site']);
    $pageAfterAllowed = pageRevisionBytes($seed['about']);

    $fail = runNavLabel($seed['user'], $seed['site'], [
        'page_id' => $seed['about']->id,
        'label' => $rejected,
        'composition_revision' => (int) SiteDraft::query()->where('site_id', $seed['site']->id)->value('admin_revision'),
    ]);

    expect($fail->ok)->toBeFalse()
        ->and($fail->error['code'])->toBe('validation')
        ->and($fail->error['fields'])->toHaveKey('label')
        ->and(compositionBytes($seed['site']))->toBe($afterAllowed)
        ->and(pageRevisionBytes($seed['about']->fresh()))->toBe($pageAfterAllowed)
        ->and($seed['about']->fresh()->nav_label)->toBe($liveBefore)
        ->and($compositionBefore['composition']['nav']['items'][0]['label'])->toBe('About-DRAFT');
});

it('subjects set_nav_label to composition_revision staleness and leaves page settings free of it', function () {
    $seed = seedPageSettingsSite();
    $pageBefore = pageRevisionBytes($seed['about']);
    $compositionBefore = compositionBytes($seed['site']);
    $liveBefore = $seed['about']->nav_label;

    $staleNav = runNavLabel($seed['user'], $seed['site'], [
        'page_id' => $seed['about']->id,
        'label' => 'Should-not-land',
        'composition_revision' => 999_999,
    ]);

    expect($staleNav->ok)->toBeFalse()
        ->and($staleNav->error['code'])->toBe('stale_revision')
        ->and($seed['about']->fresh()->nav_label)->toBe($liveBefore)
        ->and(compositionBytes($seed['site']))->toBe($compositionBefore)
        ->and(pageRevisionBytes($seed['about']->fresh()))->toBe($pageBefore);

    $stalePage = runPageSettings($seed['user'], $seed['site'], [
        'page_id' => $seed['home']->id,
        'meta_title' => 'Stale page base',
        'revision_base' => 999_999,
    ]);

    expect($stalePage->ok)->toBeFalse()
        ->and($stalePage->error['code'])->toBe('stale_revision')
        ->and(pageRevisionBytes($seed['home']->fresh()))->toBe(pageRevisionBytes($seed['home']));
});

it('denies update_page_settings and set_nav_label when SitePolicy forbids the actor', function () {
    $seed = seedPageSettingsSite();
    $pageBefore = pageRevisionBytes($seed['home']);
    $compositionBefore = compositionBytes($seed['site']);

    $pageResult = runPageSettings($seed['stranger'], $seed['site'], [
        'page_id' => $seed['home']->id,
        'meta_title' => 'Policy denied title',
        'revision_base' => $seed['home']->published_revision_id,
    ]);
    $navResult = runNavLabel($seed['stranger'], $seed['site'], [
        'page_id' => $seed['about']->id,
        'label' => 'Policy denied',
        'composition_revision' => $compositionBefore['admin_revision'],
    ]);

    expect($pageResult->ok)->toBeFalse()
        ->and($pageResult->error['code'])->toBe('forbidden')
        ->and($navResult->ok)->toBeFalse()
        ->and($navResult->error['code'])->toBe('forbidden')
        ->and(pageRevisionBytes($seed['home']->fresh()))->toBe($pageBefore)
        ->and(compositionBytes($seed['site']))->toBe($compositionBefore)
        ->and($seed['about']->fresh()->nav_label)->toBe('About-LIVE');
});

it('denies both operations when agent tools are flagged off and writes nothing', function () {
    $seed = seedPageSettingsSite();
    config(['editor.agent_tools.enabled' => false]);
    $pageBefore = pageRevisionBytes($seed['home']);
    $compositionBefore = compositionBytes($seed['site']);

    $pageResult = runPageSettings($seed['user'], $seed['site'], [
        'page_id' => $seed['home']->id,
        'meta_title' => 'Flag-off title',
        'revision_base' => $seed['home']->published_revision_id,
    ]);
    $navResult = runNavLabel($seed['user'], $seed['site'], [
        'page_id' => $seed['about']->id,
        'label' => 'Flag-off',
        'composition_revision' => $compositionBefore['admin_revision'],
    ]);

    expect($pageResult->ok)->toBeFalse()
        ->and($pageResult->error['code'])->toBe('forbidden')
        ->and($navResult->ok)->toBeFalse()
        ->and($navResult->error['code'])->toBe('forbidden')
        ->and(pageRevisionBytes($seed['home']->fresh()))->toBe($pageBefore)
        ->and(compositionBytes($seed['site']))->toBe($compositionBefore)
        ->and(EditorOperationLog::query()->where('operation', 'update_page_settings')->where('result_code', 'forbidden')->count())->toBe(1)
        ->and(EditorOperationLog::query()->where('operation', 'set_nav_label')->where('result_code', 'forbidden')->count())->toBe(1)
        ->and(EditorOperationLog::query()->whereIn('operation', ['update_page_settings', 'set_nav_label'])->where('result_code', 'ok')->count())->toBe(0);

    assertDraftsLawUnchanged($seed['drafts'], $seed['site'], $seed['preview']);
});

it('denies both operations when the sandbox exposure set excludes them', function () {
    if (! class_exists(\App\Services\Site\Editor\ToolExposure::class)) {
        test()->markTestSkipped('ToolExposure is owned by Task 20 and is not present in this tree.');
    }

    $seed = seedPageSettingsSite();
    config([
        'editor.exposure.default' => 'sandbox',
        'editor.exposure.sets.sandbox' => ['get_page_structure', 'edit_field', 'get_brand_context'],
        'editor.exposure.sets.internal' => ['*'],
        'editor.exposure.internal_sites' => '',
    ]);
    $pageBefore = pageRevisionBytes($seed['home']);
    $compositionBefore = compositionBytes($seed['site']);

    $pageResult = runPageSettings($seed['user'], $seed['site'], [
        'page_id' => $seed['home']->id,
        'meta_title' => 'Exposure denied title',
        'revision_base' => $seed['home']->published_revision_id,
    ]);
    $navResult = runNavLabel($seed['user'], $seed['site'], [
        'page_id' => $seed['about']->id,
        'label' => 'Exposure denied',
        'composition_revision' => $compositionBefore['admin_revision'],
    ]);

    expect($pageResult->ok)->toBeFalse()
        ->and($pageResult->error['code'])->toBe('not_found')
        ->and($navResult->ok)->toBeFalse()
        ->and($navResult->error['code'])->toBe('not_found')
        ->and(pageRevisionBytes($seed['home']->fresh()))->toBe($pageBefore)
        ->and(compositionBytes($seed['site']))->toBe($compositionBefore);
});

it('does not treat update_page_settings or set_nav_label as mixed-address', function () {
    expect(EditorOperations::MIXED_ADDRESS)->not->toContain('update_page_settings')
        ->and(EditorOperations::MIXED_ADDRESS)->not->toContain('set_nav_label')
        ->and(OperationRegistry::discover()->get('update_page_settings')->address())->toBe('page')
        ->and(OperationRegistry::discover()->get('set_nav_label')->address())->toBe('site');
});
