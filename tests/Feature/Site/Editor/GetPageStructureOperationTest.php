<?php

use App\Enums\PageKind;
use App\Enums\PageStatus;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\Editor\Operations\GetPageStructureOperation;
use App\Services\Site\PageLayoutRegistry;
use App\Services\Site\SectionSchema;
use Tests\Support\EditorSeeds;

beforeEach(function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
});

it('lists stored hero and cta from a home page and leaves the published revision unchanged', function () {
    expect(app(GetPageStructureOperation::class)->readOnly())->toBeTrue();

    [$actor, $site, $page] = EditorSeeds::site();
    $published = $page->published_revision_id;

    $result = EditorSeeds::run($actor, $site, 'get_page_structure', ['page_id' => $page->id]);

    expect($result->ok)->toBeTrue()
        ->and($result->data['page_id'])->toBe($page->id)
        ->and($result->data['page_type'])->toBe('home')
        ->and($result->data['draft_revision_id'])->toBe($published)
        ->and($result->data['structure_epoch'])->toBe(0)
        ->and($result->data['sections'])->toHaveCount(2);

    $hero = $result->data['sections'][0];
    $cta = $result->data['sections'][1];
    $title = collect($hero['fields'])->firstWhere('path', 'title');

    $expectedVariants = array_values(array_unique([
        ...app(PageLayoutRegistry::class)->variantOptionsFor('home', 'hero'),
        ...app(SectionSchema::class)->variantOptionsFor('hero'),
    ]));
    expect($hero['variant_options'])->toBeArray()->and(array_is_list($hero['variant_options']))->toBeTrue()
        ->and(array_values(array_unique($hero['variant_options'])))->toEqualCanonicalizing($expectedVariants)
        ->and($hero['repeatable_lists'])->toBe(app(SectionSchema::class)->repeatableLists('hero'))
        ->and($hero['refs'])->toBe([]);
    expect($hero['stored_index'])->toBe(0)
        ->and($hero['type'])->toBe('hero')
        ->and($hero['mutable'])->toBeTrue()
        ->and($hero['variant'])->toBeNull()
        ->and($cta['stored_index'])->toBe(1)
        ->and($cta['type'])->toBe('cta')
        ->and($cta['mutable'])->toBeTrue()
        ->and($title)->toMatchArray([
            'path' => 'title',
            'type' => 'plain',
            'value' => 'A',
        ])
        ->and($title['constraints'])->toHaveKey('max')
        ->and($title['constraints']['max'])->toBeInt()
        ->and($page->fresh()->published_revision_id)->toBe($published)
        ->and(collect($result->data['sections'])->pluck('type'))->not->toContain('portfolio_strip');
});

it('reports each stored section\'s own id in stored order, never one derived from position', function () {
    [$actor, $site] = EditorSeeds::site();

    // The ids deliberately do not sort with their indices: an implementation that
    // re-derives ids from a sorted collection reports ['AAA', 'MMM', 'ZZZ'] instead.
    $content = ['sections' => [
        ['type' => 'hero', 'title' => 'A', 'id' => 'ZZZ'],
        ['type' => 'intro', 'title' => 'B', 'id' => 'AAA'],
        ['type' => 'cta', 'title' => 'Call us', 'id' => 'MMM'],
    ]];
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'content_data' => $content,
        'sort_order' => 2,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
    $page->update(['published_revision_id' => $revision->id]);

    $result = EditorSeeds::run($actor, $site, 'get_page_structure', ['page_id' => $page->id]);

    expect($result->ok)->toBeTrue()
        ->and(collect($result->data['sections'])->pluck('section_id')->all())->toBe(['ZZZ', 'AAA', 'MMM'])
        ->and(collect($result->data['sections'])->pluck('stored_index')->all())->toBe([0, 1, 2]);
});

it('lists the injected phone_cta_strip + lead_form exactly where the renderer splices them, only once the home lead form is published', function () {
    [$actor, $site, $home] = EditorSeeds::site();
    BusinessProfile::factory()->for($site)->create([
        'profile_data' => ['lead_form_policy' => 'home_services'],
    ]);
    $content = ['sections' => [
        ['type' => 'intro', 'title' => 'Plumbing'],
        ['type' => 'cta', 'title' => 'Call'],
    ]];
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'emergency-plumbing',
        'kind' => PageKind::Service,
        'content_data' => $content,
        'sort_order' => 1,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
    $page->update(['published_revision_id' => $revision->id]);
    $published = $page->fresh()->published_revision_id;

    // Never published → the renderer injects nothing → the read lists nothing injected.
    $before = EditorSeeds::run($actor, $site->fresh(), 'get_page_structure', ['page_id' => $page->id]);
    expect(collect($before->data['sections'])->pluck('type')->all())->toBe(['intro', 'cta']);

    // Publish a home revision that carries a lead_form → the renderer splices [phone_cta_strip, lead_form] before the trailing cta.
    $homeContent = ['sections' => [['type' => 'hero', 'title' => 'A'], ['type' => 'lead_form', 'title' => 'Get a quote', 'fields' => []], ['type' => 'cta', 'title' => 'Call us']]];
    $homeRevision = PageRevision::factory()->for($home, 'page')->create(['content_data' => $homeContent]);
    $home->update(['published_revision_id' => $homeRevision->id]);
    $version = SiteVersion::query()->create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => ['nav' => ['items' => []], 'footer' => ['columns' => [], 'show_credit' => true], 'homepage_page_id' => $home->id],
        'page_revisions' => [['page_id' => $home->id, 'revision_id' => $homeRevision->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::query()->create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    $result = EditorSeeds::run($actor, $site->fresh(), 'get_page_structure', ['page_id' => $page->id]);
    $types = collect($result->data['sections'])->pluck('type')->all();
    $lead = collect($result->data['sections'])->firstWhere('type', 'lead_form');

    expect($result->ok)->toBeTrue()
        ->and($types)->toBe(['intro', 'phone_cta_strip', 'lead_form', 'cta'])
        ->and($lead['mutable'])->toBeFalse()
        ->and($lead['stored_index'])->toBeNull()
        ->and($result->data['sections'][1]['stored_index'])->toBeNull()
        ->and($result->data['sections'][3]['stored_index'])->toBe(1)
        ->and($page->fresh()->published_revision_id)->toBe($published);
});

it('reports section_id null for renderer-injected sections while the page\'s stored sections keep their own ids', function () {
    [$actor, $site, $home] = EditorSeeds::site();
    BusinessProfile::factory()->for($site)->create([
        'profile_data' => ['lead_form_policy' => 'home_services'],
    ]);
    $content = ['sections' => [
        ['type' => 'intro', 'title' => 'Plumbing', 'id' => 'SVC-ZZ'],
        ['type' => 'cta', 'title' => 'Call', 'id' => 'SVC-AA'],
    ]];
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'emergency-plumbing',
        'kind' => PageKind::Service,
        'content_data' => $content,
        'sort_order' => 1,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
    $page->update(['published_revision_id' => $revision->id]);

    // The home revision is a pre-backfill one: its lead_form carries no id, so the
    // injected copy has nothing to leak — its section_id must be null regardless.
    $homeContent = ['sections' => [['type' => 'hero', 'title' => 'A'], ['type' => 'lead_form', 'title' => 'Get a quote', 'fields' => []], ['type' => 'cta', 'title' => 'Call us']]];
    $homeRevision = PageRevision::factory()->for($home, 'page')->create(['content_data' => $homeContent]);
    $home->update(['published_revision_id' => $homeRevision->id]);
    $version = SiteVersion::query()->create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => ['nav' => ['items' => []], 'footer' => ['columns' => [], 'show_credit' => true], 'homepage_page_id' => $home->id],
        'page_revisions' => [['page_id' => $home->id, 'revision_id' => $homeRevision->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::query()->create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    $result = EditorSeeds::run($actor, $site->fresh(), 'get_page_structure', ['page_id' => $page->id]);

    expect($result->ok)->toBeTrue()
        ->and(collect($result->data['sections'])->pluck('type')->all())->toBe(['intro', 'phone_cta_strip', 'lead_form', 'cta'])
        ->and(collect($result->data['sections'])->pluck('section_id')->all())->toBe(['SVC-ZZ', null, null, 'SVC-AA'])
        ->and(collect($result->data['sections'])->pluck('stored_index')->all())->toBe([0, null, null, 1]);
});

it('returns not_found for a foreign page id', function () {
    [$actor, $site] = EditorSeeds::site();
    [, , $foreignPage] = EditorSeeds::site();

    $result = EditorSeeds::run($actor, $site, 'get_page_structure', ['page_id' => $foreignPage->id]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('not_found');
});

it('lists a section type missing from the catalog as immutable', function () {
    [$actor, $site] = EditorSeeds::site();
    $content = ['sections' => [
        ['type' => 'not_in_catalog', 'title' => 'X'],
    ]];
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'content_data' => $content,
        'sort_order' => 2,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
    $page->update(['published_revision_id' => $revision->id]);

    $result = EditorSeeds::run($actor, $site, 'get_page_structure', ['page_id' => $page->id]);

    expect($result->ok)->toBeTrue()
        ->and($result->data['sections'][0]['type'])->toBe('not_in_catalog')
        ->and($result->data['sections'][0]['mutable'])->toBeFalse()
        ->and($result->data['sections'][0]['stored_index'])->toBe(0);
});

it('expands starred field patterns against present members plus a template slot', function () {
    [$actor, $site] = EditorSeeds::site();
    $content = ['sections' => [
        ['type' => 'team', 'title' => 'Us', 'members' => [
            ['name' => 'Sam', 'image_id' => 9],
        ]],
    ]];
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'about',
        'content_data' => $content,
        'sort_order' => 3,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
    $page->update(['published_revision_id' => $revision->id]);

    $result = EditorSeeds::run($actor, $site, 'get_page_structure', ['page_id' => $page->id]);
    $fields = collect($result->data['sections'][0]['fields']);

    expect($fields->firstWhere('path', 'members.0.image_id'))->toMatchArray([
        'path' => 'members.0.image_id',
        'type' => 'image',
        'value' => 9,
    ])
        ->and($fields->firstWhere('path', 'members.{n}.image_id'))->toMatchArray([
            'path' => 'members.{n}.image_id',
            'type' => 'image',
            'value' => null,
        ]);
});
