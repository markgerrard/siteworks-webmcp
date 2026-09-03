<?php

use App\Models\GeneratedPage;
use App\Models\Site;

/**
 * Regression contract for GeneratedPage::isCorePage() / isServicePage()
 * as they behave TODAY, before kind/origin exist.
 *
 * Callers this suite protects (enumerated, not modified):
 *   - OrganiseNavJob service-page count + is_core payload
 *   - page-manager hero_source / dedicated-hero gates
 *
 * Pin reality, not the spec: the current core list is
 * home/about/contact/projects (projects WAS added — see GeneratedPage
 * docblock). isServicePage() is the strict negation of isCorePage().
 */
it('treats home, about, contact, and projects as core pages', function (string $pageType) {
    $page = GeneratedPage::factory()->create(['page_type' => $pageType]);

    expect($page->isCorePage())->toBeTrue()
        ->and($page->isServicePage())->toBeFalse();
})->with(['home', 'about', 'contact', 'projects']);

it('treats every other page_type as a service page', function (string $pageType) {
    $page = GeneratedPage::factory()->create(['page_type' => $pageType]);

    expect($page->isCorePage())->toBeFalse()
        ->and($page->isServicePage())->toBeTrue();
})->with([
    'article',
    'kitchen-fitting-london',
    'privacy',
    'terms',
    'weird-slug',
]);

it('treats isServicePage as the negation of isCorePage for every pinned page_type', function (string $pageType) {
    $page = GeneratedPage::factory()->for(Site::factory())->create(['page_type' => $pageType]);

    expect($page->isServicePage())->toBe(! $page->isCorePage());
})->with([
    'home',
    'about',
    'contact',
    'projects',
    'article',
    'kitchen-fitting-london',
    'privacy',
    'terms',
    'weird-slug',
]);

it('classifies an article page_type as a service page (not core)', function () {
    // kind=null fallback (this pin): article is not in the core page_type
    // list, so isServicePage() === true. That is PRESERVED for unbackfilled
    // rows. After backfill, article becomes kind=editorial and is NEITHER
    // core nor service — spec §2 behaviour-delta table row 4 (INTENDED).
    // The new ruling is asserted in PageKindTest.
    $page = GeneratedPage::factory()->create(['page_type' => 'article']);

    expect($page->isCorePage())->toBeFalse()
        ->and($page->isServicePage())->toBeTrue();
});
