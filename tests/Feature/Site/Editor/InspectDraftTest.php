<?php

use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\HeroVideoVersion;
use App\Models\LogoConcept;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Services\Site\Editor\Operations\InspectDraftOperation;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\OperationResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\EditorSeeds;

beforeEach(function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
});

/**
 * @param  array<string, mixed>  $publishedContent
 * @param  array<string, mixed>  $draftContent
 */
function inspectDraftSeedPage(
    Site $site,
    string $pageType,
    array $publishedContent,
    array $draftContent,
    int $sortOrder = 0,
): GeneratedPage {
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => $pageType,
        'content_data' => $draftContent,
        'sort_order' => $sortOrder,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);

    $draft = PageRevision::factory()->for($page, 'page')->create(['content_data' => $draftContent]);
    $published = PageRevision::factory()->for($page, 'page')->create(['content_data' => $publishedContent]);

    $page->update([
        'draft_revision_id' => $draft->id,
        'published_revision_id' => $published->id,
    ]);

    return $page->fresh();
}

/**
 * @return array{actor: \App\Models\User, site: Site, home: GeneratedPage, about: GeneratedPage, preview: Preview}
 */
function inspectDraftFixture(): array
{
    [$actor, $site, $existing] = EditorSeeds::site();
    $existing->delete();

    $longTitle = str_repeat("\u{00E9}", 300);
    expect(strlen($longTitle))->toBeGreaterThan(512);

    $home = inspectDraftSeedPage(
        $site,
        'home',
        ['sections' => [['type' => 'hero', 'title' => 'short']]],
        [
            'sections' => [['type' => 'hero', 'title' => $longTitle]],
            'meta' => ['seo' => [
                'meta_title' => str_repeat('t', 61),
                'meta_description' => str_repeat('d', 156),
            ]],
        ],
        sortOrder: 0,
    );

    $about = inspectDraftSeedPage(
        $site,
        'about',
        ['sections' => [['type' => 'hero', 'title' => 'About published']]],
        [
            'sections' => [['type' => 'hero', 'title' => 'About drafted']],
            'meta' => ['seo' => [
                'meta_title' => str_repeat('a', 61),
                'meta_description' => 'Short enough.',
            ]],
        ],
        sortOrder: 1,
    );

    SiteDraft::query()->create([
        'site_id' => $site->id,
        'composition' => [
            'nav' => ['items' => [
                ['type' => 'page', 'page_id' => $home->id, 'label' => 'Home'],
                ['type' => 'page', 'page_id' => 9_000_001, 'label' => 'Missing'],
            ]],
            'theme' => ['key' => 'trades-bold'],
        ],
        'updated_at' => now(),
    ]);

    $preview = Preview::factory()->for($site)->create(['snapshot' => ['marker' => 'inspect-draft-unchanged']]);

    return [
        'actor' => $actor,
        'site' => $site->fresh(),
        'home' => $home,
        'about' => $about,
        'preview' => $preview,
    ];
}

function inspectDraftRun(Site $site, $actor, array $input = []): OperationResult
{
    return EditorSeeds::run($actor, $site, 'inspect_draft', $input);
}

function inspectDraftOracle(Site $site, $actor, string $operation, array $input = []): OperationResult
{
    EditorSeeds::exposeAsInternal($site);

    return EditorSeeds::run($actor, $site, $operation, $input);
}

function inspectDraftEncode(mixed $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}

function inspectDraftLawSnapshot(Site $site, GeneratedPage $home, GeneratedPage $about, Preview $preview): array
{
    return [
        'home_published_revision_id' => $home->fresh()->published_revision_id,
        'about_published_revision_id' => $about->fresh()->published_revision_id,
        'hero_active' => HeroVersion::query()->where('site_id', $site->id)->where('is_active', true)->orderBy('id')->pluck('id')->all(),
        'logo_selected' => LogoConcept::query()->where('site_id', $site->id)->where('is_selected', true)->orderBy('id')->pluck('id')->all(),
        'video_active' => HeroVideoVersion::query()->where('site_id', $site->id)->where('is_active', true)->orderBy('id')->pluck('id')->all(),
        'home_hero_video_enabled' => $site->fresh()->home_hero_video_enabled,
        'preview_snapshot' => $preview->fresh()->snapshot,
    ];
}

it('declares a read-only site-addressed composition with no screenshot flag and no approval', function () {
    $operation = OperationRegistry::discover()->get('inspect_draft');

    expect($operation->name())->toBe('inspect_draft')
        ->and($operation->readOnly())->toBeTrue()
        ->and($operation->address())->toBe('site')
        ->and($operation->requiresApproval())->toBeFalse()
        ->and((new ReflectionMethod($operation, 'requiresApproval'))->getDeclaringClass()->getName())->toBe(InspectDraftOperation::class)
        ->and($operation->wrapInAdminChange())->toBeFalse()
        ->and($operation->delegatesTo())->toBe(['get_draft_diff', 'validate_draft'])
        ->and(array_keys($operation->inputSchema()['properties'] ?? []))->toBe(['page_id'])
        ->and($operation->inputSchema()['properties'] ?? [])->not->toHaveKey('screenshot')
        ->and($operation->inputSchema()['properties'] ?? [])->not->toHaveKey('include_screenshot')
        ->and($operation->inputSchema()['properties'] ?? [])->not->toHaveKey('capture')
        ->and($operation->sideEffects())->toContain('no screenshot flag');
});

it('returns diff and findings byte-identical to invoking the two underlying operations on a non-trivial fixture', function () {
    ['actor' => $actor, 'site' => $site, 'home' => $home, 'about' => $about, 'preview' => $preview] = inspectDraftFixture();

    expect($home->draft_revision_id)->not->toBe($home->published_revision_id)
        ->and($about->draft_revision_id)->not->toBeNull();

    $before = inspectDraftLawSnapshot($site, $home, $about, $preview);

    Queue::fake();
    Http::fake();

    $diff = inspectDraftOracle($site, $actor, 'get_draft_diff');
    $validate = inspectDraftOracle($site, $actor, 'validate_draft');

    $pageIds = collect($diff->data['pages'] ?? [])->pluck('page_id')->unique()->filter()->values();
    $codes = collect($validate->data['findings'] ?? [])->pluck('code')->unique()->values();
    $truncated = collect($diff->data['pages'] ?? [])->contains(
        fn (mixed $entry): bool => is_array($entry) && ($entry['truncated'] ?? false) === true,
    );

    expect($diff->ok)->toBeTrue()
        ->and($validate->ok)->toBeTrue()
        ->and($pageIds->count())->toBeGreaterThanOrEqual(2)
        ->and($truncated)->toBeTrue()
        ->and(count($validate->data['findings']))->toBeGreaterThanOrEqual(3)
        ->and($codes->count())->toBeGreaterThanOrEqual(2);

    $inspect = inspectDraftRun($site, $actor);

    expect($inspect->ok)->toBeTrue()
        ->and(inspectDraftEncode($inspect->data['diff'] ?? null))->toBe(inspectDraftEncode($diff->data))
        ->and(inspectDraftEncode($inspect->data['findings'] ?? null))->toBe(inspectDraftEncode($validate->data['findings']));

    Queue::assertNothingPushed();
    Http::assertNothingSent();

    expect(inspectDraftLawSnapshot($site, $home, $about, $preview))->toBe($before);
});

it('forwards page_id to both delegates and does not equal the unscoped result', function () {
    ['actor' => $actor, 'site' => $site, 'home' => $home] = inspectDraftFixture();

    $pageId = $home->id;
    $scopedInput = ['page_id' => $pageId];

    $scopedDiff = inspectDraftOracle($site, $actor, 'get_draft_diff', $scopedInput);
    $scopedValidate = inspectDraftOracle($site, $actor, 'validate_draft', $scopedInput);
    $unscopedDiff = inspectDraftOracle($site, $actor, 'get_draft_diff');
    $unscopedValidate = inspectDraftOracle($site, $actor, 'validate_draft');

    expect($scopedDiff->ok)->toBeTrue()
        ->and($scopedValidate->ok)->toBeTrue()
        ->and(inspectDraftEncode($scopedDiff->data))->not->toBe(inspectDraftEncode($unscopedDiff->data))
        ->and(inspectDraftEncode($scopedValidate->data['findings']))->not->toBe(inspectDraftEncode($unscopedValidate->data['findings']));

    $inspectScoped = inspectDraftRun($site, $actor, $scopedInput);
    $inspectUnscoped = inspectDraftRun($site, $actor);

    expect($inspectScoped->ok)->toBeTrue()
        ->and(inspectDraftEncode($inspectScoped->data['diff'] ?? null))->toBe(inspectDraftEncode($scopedDiff->data))
        ->and(inspectDraftEncode($inspectScoped->data['findings'] ?? null))->toBe(inspectDraftEncode($scopedValidate->data['findings']))
        ->and(inspectDraftEncode($inspectScoped->data))->not->toBe(inspectDraftEncode($inspectUnscoped->data));

    $expectedRevisionId = $home->fresh()->draft_revision_id;
    expect($expectedRevisionId)->not->toBeNull()
        ->and($expectedRevisionId)->not->toBe($home->fresh()->published_revision_id)
        ->and($inspectScoped->state->draftRevisionId)->toBe($expectedRevisionId);
});

it('runs inspect_draft on an ordinary sandbox site while a direct get_draft_diff is refused', function () {
    ['actor' => $actor, 'site' => $site] = inspectDraftFixture();

    $inspect = inspectDraftRun($site, $actor);

    expect($inspect->ok)->toBeTrue()
        ->and($inspect->data)->toHaveKeys(['diff', 'findings']);

    $directDiff = EditorSeeds::run($actor, $site, 'get_draft_diff', []);
    $directValidate = EditorSeeds::run($actor, $site, 'validate_draft', []);
    $unknown = EditorSeeds::run($actor, $site, 'certainly_not_registered', []);

    expect($directDiff->ok)->toBeFalse()
        ->and($directDiff->error)->toBe($unknown->error)
        ->and($directValidate->ok)->toBeFalse()
        ->and($directValidate->error)->toBe($unknown->error)
        ->and($unknown->error)->toBe(['code' => 'not_found', 'message' => 'Unknown operation.']);
});
