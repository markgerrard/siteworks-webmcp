<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function publishContactPage(Site $site, array $items): GeneratedPage
{
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'contact']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'details', 'title' => 'Get In Touch', 'items' => $items],
        ]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);
    $version = SiteVersion::create([
        'site_id' => $site->id, 'version' => 1,
        'composition' => [
            'nav' => ['items' => []], 'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    return $page;
}

it('skips detail items with no content instead of rendering bare icon pins', function () {
    $site = Site::factory()->create();
    $page = publishContactPage($site, [
        ['label' => 'Phone', 'value' => '07840368946'],
        ['body' => '', 'icon' => '', 'title' => ''],   // flyout-era empty item
        ['label' => '', 'value' => ''],
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect(substr_count($html, 'rounded-md flex items-center justify-center text-white'))->toBe(1)  // one icon tile — Phone only
        ->and($html)->toContain('07840368946');
});

it('renders a whatsapp item as a wa.me link with its own icon', function () {
    $site = Site::factory()->create();
    $page = publishContactPage($site, [
        ['label' => 'WhatsApp', 'value' => '07840 368946'],
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('https://wa.me/447840368946')
        ->and($html)->toContain('WhatsApp');
});

it('uses the widened five-column layout when the form renders alongside details', function () {
    $site = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'contact']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'details', 'title' => 'Get In Touch', 'items' => [
                ['label' => 'Email', 'value' => 'hi@example.com'],
            ]],
            ['type' => 'contact_form', 'title' => 'Send Us a Message', 'submit_label' => 'Send'],
        ]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);
    $version = SiteVersion::create([
        'site_id' => $site->id, 'version' => 1,
        'composition' => [
            'nav' => ['items' => []], 'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('site-shell-container')
        ->and($html)->toContain('lg:grid-cols-5')
        ->and($html)->toContain('lg:col-span-3')
        ->and($html)->toContain('space-y-6 lg:col-span-2');
});
