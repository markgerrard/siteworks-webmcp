<?php

use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\HeroVideoVersion;
use App\Models\LogoConcept;
use App\Models\Preview;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\Editor\DraftAssetSelections;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\OperationResult;
use Tests\Support\EditorSeeds;

beforeEach(function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
});

function seedDraftPage(
    Site $site,
    string $pageType,
    ?array $publishedContent,
    array $draftContent,
    bool $archived = false,
    int $sortOrder = 0,
): GeneratedPage {
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => $pageType,
        'content_data' => $draftContent,
        'sort_order' => $sortOrder,
        'version' => 1,
        'status' => PageStatus::Published,
        'archived_at' => $archived ? now() : null,
    ]);

    $draft = PageRevision::factory()->for($page, 'page')->create(['content_data' => $draftContent]);
    $publishedId = null;

    if ($publishedContent !== null) {
        $published = PageRevision::factory()->for($page, 'page')->create(['content_data' => $publishedContent]);
        $publishedId = $published->id;
    }

    $page->update([
        'draft_revision_id' => $draft->id,
        'published_revision_id' => $publishedId,
    ]);

    return $page->fresh();
}

function runDraftDiff(Site $site, $actor, array $input = []): OperationResult
{
    EditorSeeds::exposeAsInternal($site);

    return EditorSeeds::run($actor, $site, 'get_draft_diff', $input);
}

function draftLawSnapshot(Site $site, GeneratedPage $page, Preview $preview): array
{
    return [
        'published_revision_id' => $page->fresh()->published_revision_id,
        'draft_revision_id' => $page->fresh()->draft_revision_id,
        'hero_active' => HeroVersion::query()->where('site_id', $site->id)->where('is_active', true)->orderBy('id')->pluck('id')->all(),
        'logo_selected' => LogoConcept::query()->where('site_id', $site->id)->where('is_selected', true)->orderBy('id')->pluck('id')->all(),
        'video_active' => HeroVideoVersion::query()->where('site_id', $site->id)->where('is_active', true)->orderBy('id')->pluck('id')->all(),
        'home_hero_video_enabled' => $site->fresh()->home_hero_video_enabled,
        'preview_snapshot' => $preview->fresh()->snapshot,
    ];
}

function collectValueKeys(mixed $node): array
{
    if (! is_array($node)) {
        return [];
    }

    $found = [];
    foreach ($node as $key => $value) {
        if ($key === 'before' || $key === 'after') {
            $found[] = $key;
        }
        $found = [...$found, ...collectValueKeys($value)];
    }

    return $found;
}

it('declares a read-only site-addressed operation that does not require approval', function () {
    $operation = OperationRegistry::discover()->get('get_draft_diff');

    expect($operation->readOnly())->toBeTrue()
        ->and($operation->address())->toBe('site')
        ->and($operation->requiresApproval())->toBeFalse()
        ->and($operation->delegatesTo())->toBe([])
        ->and($operation->wrapInAdminChange())->toBeFalse();
});

it('reports every never-published drafted page as kind insert by equality on the kinds list', function () {
    [$actor, $site] = EditorSeeds::site();
    GeneratedPage::query()->where('site_id', $site->id)->delete();

    $homeDraft = ['sections' => [
        ['type' => 'hero', 'title' => 'Unpublished hero'],
        ['type' => 'cta', 'title' => 'Unpublished cta'],
    ]];
    $aboutDraft = ['sections' => [
        ['type' => 'hero', 'title' => 'About unpublished'],
    ]];

    $home = seedDraftPage($site, 'home', null, $homeDraft, sortOrder: 0);
    $about = seedDraftPage($site, 'about', null, $aboutDraft, sortOrder: 1);

    expect($home->published_revision_id)->toBeNull()
        ->and($about->published_revision_id)->toBeNull()
        ->and($home->draft_revision_id)->not->toBeNull()
        ->and($about->draft_revision_id)->not->toBeNull();

    $result = runDraftDiff($site, $actor);

    $expectedKinds = [];
    foreach (collect([$home, $about])->sortBy('id') as $page) {
        $sectionCount = count($page->draftRevision->content_data['sections']);
        $expectedKinds = [...$expectedKinds, ...array_fill(0, $sectionCount, 'insert')];
    }

    expect($result->ok)->toBeTrue()
        ->and(array_column($result->data['pages'], 'kind'))->toBe($expectedKinds);
});

it('reports an empty pages list when a page draft equals its published content', function () {
    [$actor, $site] = EditorSeeds::site();
    GeneratedPage::query()->where('site_id', $site->id)->delete();

    $same = ['sections' => [
        ['id' => '01JHG7KX3MNQRSTVWXYZHOM001', 'type' => 'hero', 'title' => 'Unchanged'],
        ['id' => '01JHG7KX3MNQRSTVWXYZHOM002', 'type' => 'cta', 'title' => 'Still the same'],
    ]];
    seedDraftPage($site, 'home', $same, $same, sortOrder: 0);
    seedDraftPage(
        $site,
        'about',
        ['sections' => [['type' => 'hero', 'title' => 'Published about']]],
        ['sections' => [['type' => 'hero', 'title' => 'Drafted about']]],
        archived: true,
        sortOrder: 1,
    );

    $noDraft = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'contact',
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Contact']]],
        'sort_order' => 2,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $publishedOnly = PageRevision::factory()->for($noDraft, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Contact']]],
    ]);
    $noDraft->update(['published_revision_id' => $publishedOnly->id, 'draft_revision_id' => null]);

    $result = runDraftDiff($site, $actor);

    expect($result->ok)->toBeTrue()
        ->and($result->data['pages'])->toBe([]);
});

it('truncates values at 512 bytes and does not mark an exact 512-byte value truncated', function () {
    [$actor, $site] = EditorSeeds::site();
    GeneratedPage::query()->where('site_id', $site->id)->delete();

    $byteLimit = strlen(str_repeat('A', 512));
    $long = str_repeat("\u{00E9}", 300);
    $exact = str_repeat('x', $byteLimit - 1).'!';

    expect(strlen($long))->toBeGreaterThan($byteLimit)
        ->and(strlen($long))->not->toBe(mb_strlen($long, 'UTF-8'))
        ->and(strlen($exact))->toBe($byteLimit);

    $longId = '01JHG7KX3MNQRSTVWXYZLNG001';
    $exactId = '01JHG7KX3MNQRSTVWXYZEXC001';

    $longPage = seedDraftPage(
        $site,
        'home',
        ['sections' => [['id' => $longId, 'type' => 'hero', 'title' => 'short']]],
        ['sections' => [['id' => $longId, 'type' => 'hero', 'title' => $long]]],
        sortOrder: 0,
    );
    $exactPage = seedDraftPage(
        $site,
        'about',
        ['sections' => [['id' => $exactId, 'type' => 'hero', 'title' => 'short']]],
        ['sections' => [['id' => $exactId, 'type' => 'hero', 'title' => $exact]]],
        sortOrder: 1,
    );

    $result = runDraftDiff($site, $actor);

    $byPage = collect($result->data['pages'])->keyBy('page_id');

    expect($result->ok)->toBeTrue()
        ->and($byPage->has($longPage->id))->toBeTrue()
        ->and($byPage->has($exactPage->id))->toBeTrue();

    $longEntry = $byPage[$longPage->id];
    $exactEntry = $byPage[$exactPage->id];

    expect($longEntry['truncated'])->toBeTrue()
        ->and(strlen((string) $longEntry['after']))->toBe($byteLimit)
        ->and($exactEntry['truncated'])->toBeFalse()
        ->and($exactEntry['after'])->toBe($exact)
        ->and(strlen((string) $exactEntry['after']))->toBe($byteLimit);
});

it('never emits the substring base64, anywhere in the encoded result', function () {
    [$actor, $site] = EditorSeeds::site();
    GeneratedPage::query()->where('site_id', $site->id)->delete();

    $payload = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    seedDraftPage(
        $site,
        'home',
        ['sections' => [['type' => 'hero', 'title' => 'Clean', 'background_image' => null]]],
        ['sections' => [['type' => 'hero', 'title' => 'Clean', 'background_image' => $payload]]],
        sortOrder: 0,
    );

    SiteDraft::create([
        'site_id' => $site->id,
        'composition' => [
            'theme' => ['accent_override' => $payload],
            'nav' => ['items' => []],
        ],
        'updated_at' => now(),
    ]);
    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'theme' => ['accent_override' => '#111111'],
            'nav' => ['items' => []],
        ],
        'page_revisions' => [],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    $result = runDraftDiff($site, $actor);
    $encoded = json_encode($result->toArray());

    expect($result->ok)->toBeTrue()
        ->and($encoded)->not->toContain('base64,')
        ->and($encoded)->not->toContain($payload);
});

it('scopes a page_id call to that page id set by equality', function () {
    [$actor, $site] = EditorSeeds::site();
    GeneratedPage::query()->where('site_id', $site->id)->delete();

    $home = seedDraftPage(
        $site,
        'home',
        ['sections' => [['type' => 'hero', 'title' => 'Home published']]],
        ['sections' => [['type' => 'hero', 'title' => 'Home drafted']]],
        sortOrder: 0,
    );
    $about = seedDraftPage(
        $site,
        'about',
        ['sections' => [['type' => 'hero', 'title' => 'About published']]],
        ['sections' => [['type' => 'hero', 'title' => 'About drafted']]],
        sortOrder: 1,
    );

    $result = runDraftDiff($site, $actor, ['page_id' => $home->id]);
    $pageIds = array_values(array_unique(array_column($result->data['pages'], 'page_id')));

    expect($result->ok)->toBeTrue()
        ->and($pageIds)->toBe([$home->id])
        ->and($pageIds)->not->toBe([$home->id, $about->id]);
});

it('omits every value payload when include_values is false', function () {
    [$actor, $site] = EditorSeeds::site();
    GeneratedPage::query()->where('site_id', $site->id)->delete();

    $secret = 'ORACLE_VALUE_PAYLOAD_913';
    seedDraftPage(
        $site,
        'home',
        ['sections' => [['type' => 'hero', 'title' => 'Published']]],
        ['sections' => [['type' => 'hero', 'title' => $secret]]],
        sortOrder: 0,
    );

    $result = runDraftDiff($site, $actor, ['include_values' => false]);
    $encoded = json_encode($result->toArray());

    expect($result->ok)->toBeTrue()
        ->and($result->data['pages'])->not->toBe([])
        ->and(collectValueKeys($result->toArray()))->toBe([])
        ->and($encoded)->not->toContain($secret);
});

it('walks composition, drafted asset selections, and project-item drift with an independent summary', function () {
    [$actor, $site, $existing] = EditorSeeds::site();
    $existing->delete();

    $titleId = '01JHG7KX3MNQRSTVWXYZTTL001';
    $page = seedDraftPage(
        $site,
        'home',
        ['sections' => [['id' => $titleId, 'type' => 'hero', 'title' => 'Published title']]],
        ['sections' => [['id' => $titleId, 'type' => 'hero', 'title' => 'Drafted title']]],
        sortOrder: 0,
    );

    $preview = Preview::factory()->for($site)->create(['snapshot' => ['marker' => 'unchanged']]);

    SiteDraft::create([
        'site_id' => $site->id,
        'composition' => [
            'theme' => ['accent_override' => '#ff6600'],
            'nav' => ['items' => [['label' => 'Home']]],
        ],
        'updated_at' => now(),
    ]);
    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'theme' => ['accent_override' => '#111111'],
            'nav' => ['items' => [['label' => 'Home']]],
        ],
        'page_revisions' => [],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    $activeHero = HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
    ]);
    $draftHero = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
    ]);
    app(DraftAssetSelections::class)->setHero($site, 'home', 'hero', $draftHero, $actor->id);

    $item = ProjectItem::factory()->for($site)->create([
        'page_id' => $page->id,
        'title' => 'Drifted title',
        'description' => 'Same description',
        'category' => 'Residential',
        'metrics' => null,
        'image_id' => null,
        'sort_order' => 0,
        'published_snapshot' => [
            'title' => 'Published tile',
            'description' => 'Same description',
            'category' => 'Residential',
            'metrics' => null,
            'image_id' => null,
            'sort_order' => 0,
        ],
    ]);

    expect($item->hasUnpublishedDrift())->toBeTrue();

    $before = draftLawSnapshot($site, $page, $preview);
    $result = runDraftDiff($site, $actor);

    $expectedPageTitle = [
        'scope' => 'page',
        'page_id' => $page->id,
        'stored_index' => 0,
        'section_id' => $titleId,
        'field_path' => 'title',
        'path' => 'sections.0.title',
        'before' => 'Published title',
        'after' => 'Drafted title',
        'kind' => 'set',
        'truncated' => false,
    ];
    $expectedItemTitle = [
        'scope' => 'page',
        'page_id' => $page->id,
        'stored_index' => null,
        'section_id' => null,
        'field_path' => 'project_item.'.$item->id.'.title',
        'path' => 'project_item.'.$item->id.'.title',
        'before' => 'Published tile',
        'after' => 'Drifted title',
        'kind' => 'set',
        'truncated' => false,
    ];
    $expectedComposition = [
        'scope' => 'site',
        'page_id' => null,
        'stored_index' => null,
        'section_id' => null,
        'field_path' => null,
        'path' => 'composition.theme.accent_override',
        'before' => '#111111',
        'after' => '#ff6600',
        'kind' => 'set',
        'truncated' => false,
    ];
    $expectedAsset = [
        'scope' => 'site',
        'page_id' => null,
        'stored_index' => null,
        'section_id' => null,
        'field_path' => null,
        'path' => 'asset_selection.hero.home.hero.version_id',
        'before' => $activeHero->id,
        'after' => $draftHero->id,
        'kind' => 'set',
        'truncated' => false,
    ];

    expect($result->ok)->toBeTrue()
        ->and($result->data['pages'])->toBe([$expectedPageTitle, $expectedItemTitle])
        ->and($result->data['composition'])->toBe([$expectedComposition])
        ->and($result->data['assets'])->toBe([$expectedAsset])
        ->and($result->data['summary'])->toBe([
            'pages' => 1,
            'fields' => 3,
            'assets' => 1,
        ])
        ->and(draftLawSnapshot($site, $page, $preview))->toBe($before);
});

it('does not read another site when page_id belongs elsewhere', function () {
    [$actor, $site] = EditorSeeds::site();
    $foreign = EditorSeeds::site();
    [, $foreignSite, $foreignPage] = [$foreign[0], $foreign[1], $foreign[2]];

    $result = runDraftDiff($site, $actor, ['page_id' => $foreignPage->id]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('not_found')
        ->and($result->error['message'])->toBe('Page not found.')
        ->and($result->data)->toBe([]);
});
