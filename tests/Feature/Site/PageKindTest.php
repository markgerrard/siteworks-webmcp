<?php

use App\Enums\PageKind;
use App\Enums\PageOrigin;
use App\Models\GeneratedPage;
use App\Models\Site;

it('backfills kind from page_type', function () {
    $site = Site::factory()->create();
    $rows = [
        ['page_type' => 'home', 'expect' => 'core'],
        ['page_type' => 'projects', 'expect' => 'core'],
        ['page_type' => 'privacy', 'expect' => 'core'],
        ['page_type' => 'terms', 'expect' => 'core'],
        ['page_type' => 'article', 'expect' => 'editorial'],
        ['page_type' => 'kitchen-fitting-london', 'expect' => 'service'],
    ];
    foreach ($rows as $r) {
        $p = GeneratedPage::factory()->for($site)->create(['page_type' => $r['page_type'], 'kind' => null]);
        GeneratedPage::backfillKindFromPageType();
        expect($p->fresh()->kind?->value)->toBe($r['expect']);
    }
});

it('kind wins over page_type when set', function () {
    $p = GeneratedPage::factory()->create(['page_type' => 'weird-slug', 'kind' => PageKind::Guide]);
    expect($p->isServicePage())->toBeFalse();
    expect($p->isCorePage())->toBeFalse();
});

it('origin defaults to pipeline', function () {
    expect(GeneratedPage::factory()->create()->origin)->toBe(PageOrigin::Pipeline);
});

it('classifies a backfilled article as neither core nor service', function () {
    // Spec §2 behaviour-delta table row 4 (isCorePage/isServicePage callers):
    // kind is authoritative when set. article backfills to editorial, so the
    // page is no longer a service page. That is the INTENDED delta vs the
    // kind=null characterisation pin (article was !isCorePage, hence service).
    $page = GeneratedPage::factory()->create(['page_type' => 'article', 'kind' => null]);
    GeneratedPage::backfillKindFromPageType();
    $page = $page->fresh();

    expect($page->kind)->toBe(PageKind::Editorial)
        ->and($page->isCorePage())->toBeFalse()
        ->and($page->isServicePage())->toBeFalse();
});

it('does not overwrite a kind that is already set', function () {
    $page = GeneratedPage::factory()->create([
        'page_type' => 'home',
        'kind' => PageKind::Hub,
    ]);

    GeneratedPage::backfillKindFromPageType();

    expect($page->fresh()->kind)->toBe(PageKind::Hub);
});

it('backfills kind when the kind/origin migration is re-run against seeded rows', function () {
    // Proves the MIGRATION up() path, not GeneratedPage::backfillKindFromPageType().
    // RefreshDatabase has already migrated against empty tables, so we
    // instantiate this migration and down()/up() it on the test connection
    // (artisan migrate:rollback would rewind the whole migrate:fresh batch).
    $site = Site::factory()->create();
    $rows = [
        ['page_type' => 'home', 'expect' => 'core'],
        ['page_type' => 'projects', 'expect' => 'core'],
        ['page_type' => 'privacy', 'expect' => 'core'],
        ['page_type' => 'terms', 'expect' => 'core'],
        ['page_type' => 'article', 'expect' => 'editorial'],
        ['page_type' => 'kitchen-fitting-london', 'expect' => 'service'],
    ];

    $pages = [];
    foreach ($rows as $r) {
        $pages[$r['page_type']] = GeneratedPage::factory()->for($site)->create([
            'page_type' => $r['page_type'],
            'kind' => null,
        ]);
    }

    $trashed = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'article',
        'kind' => null,
    ]);
    $trashed->delete();

    $migration = require database_path('migrations/2026_08_13_100000_add_kind_and_origin_to_generated_pages.php');
    $migration->down();
    $migration->up();

    foreach ($rows as $r) {
        expect($pages[$r['page_type']]->fresh()->kind?->value)->toBe($r['expect']);
    }

    expect(GeneratedPage::withTrashed()->find($trashed->id)?->kind?->value)->toBe('editorial');
});
