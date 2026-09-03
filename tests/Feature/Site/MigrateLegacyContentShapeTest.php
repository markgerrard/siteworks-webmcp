<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use Illuminate\Support\Str;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function legacyContent(): array
{
    return [
        'hero' => [
            'heading' => 'Welcome',
            'subheading' => 'We do great work.',
            'cta_label' => 'Call us',
        ],
        'services' => [
            'heading' => 'Services',
            'intro' => 'We offer these services.',
            'items' => [
                ['title' => 'Boilers', 'body' => 'Install and repair.'],
            ],
        ],
        'seo' => ['meta_title' => 'T', 'meta_description' => 'D'],
    ];
}

test('translates revisions and generated_pages.content_data on a real run', function () {
    $site = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create(['content_data' => legacyContent()]);
    $rev = PageRevision::factory()->for($page, 'page')->create(['content_data' => legacyContent()]);
    $page->update(['published_revision_id' => $rev->id]);

    $this->artisan('site:migrate-legacy-content-shape')->assertSuccessful();

    $rev->refresh();
    $page->refresh();

    expect($rev->content_data)->toHaveKey('sections');
    expect($rev->content_data['sections'][0]['type'])->toBe('hero');
    expect($rev->content_data['sections'][0]['title'])->toBe('Welcome');
    expect($rev->content_data['meta']['seo']['meta_title'])->toBe('T');

    expect($page->content_data)->toHaveKey('sections');
    expect($page->content_data['sections'][0]['title'])->toBe('Welcome');
});

test('--dry-run does not persist changes', function () {
    $site = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create(['content_data' => legacyContent()]);
    $rev = PageRevision::factory()->for($page, 'page')->create(['content_data' => legacyContent()]);

    $this->artisan('site:migrate-legacy-content-shape', ['--dry-run' => true])
        ->assertSuccessful();

    $rev->refresh();
    $page->refresh();

    expect($rev->content_data)->not->toHaveKey('sections');
    expect($rev->content_data)->toHaveKey('hero');
    expect($page->content_data)->not->toHaveKey('sections');
});

test('--site scopes updates to that site only', function () {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();

    $pageA = GeneratedPage::factory()->for($siteA)->create(['content_data' => legacyContent()]);
    $revA = PageRevision::factory()->for($pageA, 'page')->create(['content_data' => legacyContent()]);

    $pageB = GeneratedPage::factory()->for($siteB)->create(['content_data' => legacyContent()]);
    $revB = PageRevision::factory()->for($pageB, 'page')->create(['content_data' => legacyContent()]);

    $this->artisan('site:migrate-legacy-content-shape', ['--site' => $siteA->id])
        ->assertSuccessful();

    $revA->refresh();
    $revB->refresh();

    expect($revA->content_data)->toHaveKey('sections');
    expect($revB->content_data)->not->toHaveKey('sections');
});

test('idempotent: a second run reports 0 translated', function () {
    $site = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create(['content_data' => legacyContent()]);
    PageRevision::factory()->for($page, 'page')->create(['content_data' => legacyContent()]);

    $this->artisan('site:migrate-legacy-content-shape')->assertSuccessful();
    $this->artisan('site:migrate-legacy-content-shape')
        ->expectsOutputToContain('translated 0 revisions, 1 skipped')
        ->assertSuccessful();
});

test('revisions already in new shape are skipped untouched', function () {
    $site = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create();
    $already = ['sections' => [['type' => 'hero', 'title' => 'Already new']]];
    $rev = PageRevision::factory()->for($page, 'page')->create(['content_data' => $already]);

    expect($rev->content_data['sections'][0])->toHaveKey('id')
        ->and(Str::isUlid($rev->content_data['sections'][0]['id']))->toBeTrue();
    $withoutId = $rev->content_data['sections'][0];
    unset($withoutId['id']);
    expect($withoutId)->toEqual($already['sections'][0]);
    $stored = $rev->content_data;

    $this->artisan('site:migrate-legacy-content-shape')->assertSuccessful();

    $rev->refresh();
    expect($rev->content_data)->toEqual($stored);
});
