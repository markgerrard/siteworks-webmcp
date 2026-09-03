<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makePage(int $siteId, string $type, array $content): GeneratedPage
{
    $gp = GeneratedPage::create([
        'site_id' => $siteId,
        'page_type' => $type,
        'content_data' => $content,
        'sort_order' => 0,
        'version' => 1,
        'nav_label' => ucfirst($type),
    ]);

    // The page-manager tabs builder reads from the latestPreview's snapshot.
    // Mirror the page content into the preview so the tests exercise the
    // full rendering path the live UI uses.
    $preview = \App\Models\Preview::where('site_id', $siteId)->first();
    if (! $preview) {
        $preview = \App\Models\Preview::factory()->create(['site_id' => $siteId]);
    }
    $snapshot = $preview->snapshot ?? [];
    $snapshot['pages'][$type] = $content;
    $preview->update(['snapshot' => $snapshot]);

    return $gp;
}

test('edit opens populated fields when content_data uses list-of-sections shape (new)', function () {
    $site = Site::factory()->create();
    makePage($site->id, 'home', [
        'sections' => [
            ['type' => 'hero', 'title' => 'Premier Plumbing', 'subtitle' => 'Wigan & Beyond', 'cta_label' => 'Get a Quote'],
            ['type' => 'services', 'title' => 'What We Do', 'intro' => 'Trusted trades', 'items' => [
                ['title' => 'Boilers', 'body' => 'Install & service'],
                ['title' => 'Leaks', 'body' => 'Detect & repair'],
            ]],
        ],
        'meta' => [],
    ]);

    $staff = \App\Models\User::factory()->staff(\App\Enums\AgentRole::Admin)->create();

    $component = Livewire::actingAs($staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'hero')
        ->assertSet('editHeading', 'Premier Plumbing')
        ->assertSet('editSubheading', 'Wigan & Beyond')
        ->assertSet('editCtaLabel', 'Get a Quote')
        ->assertSet('editing', 'home.hero.0');

    // Now open services — must populate title + intro + two items.
    $component->call('edit', 'home', 'services')
        ->assertSet('editHeading', 'What We Do')
        ->assertSet('editSubheading', 'Trusted trades');

    $items = $component->get('editItems');
    expect($items)->toHaveCount(2);
    expect($items[0]['title'])->toBe('Boilers');
    expect($items[1]['title'])->toBe('Leaks');
});

test('edit still works on legacy dict-keyed content_data (backwards compat)', function () {
    $site = Site::factory()->create();
    makePage($site->id, 'home', [
        'hero' => ['heading' => 'Legacy Hero', 'subheading' => 'Old shape', 'cta_label' => 'Call'],
    ]);

    $staff = \App\Models\User::factory()->staff(\App\Enums\AgentRole::Admin)->create();

    Livewire::actingAs($staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'hero')
        ->assertSet('editHeading', 'Legacy Hero')
        ->assertSet('editSubheading', 'Old shape')
        ->assertSet('editCtaLabel', 'Call');
});

test('edit flattens ProseMirror intro / item body to plain text for the flyout', function () {
    $site = Site::factory()->create();
    $introDoc = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'From boiler repairs to full bathroom installs.']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Covering Wigan and beyond.']]],
        ],
    ];
    $itemBodyDoc = [
        'type' => 'doc',
        'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Same-day emergency repairs.']]]],
    ];
    makePage($site->id, 'home', [
        'sections' => [
            [
                'type' => 'services',
                'title' => 'Our Services',
                'intro' => $introDoc,
                'items' => [
                    ['icon' => 'wrench', 'title' => 'Boilers', 'body' => $itemBodyDoc],
                    ['icon' => 'drop', 'title' => 'Leaks', 'body' => 'Simple string body'],
                ],
            ],
        ],
        'meta' => [],
    ]);

    $staff = \App\Models\User::factory()->staff(\App\Enums\AgentRole::Admin)->create();

    $component = Livewire::actingAs($staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'services')
        ->assertSet('editHeading', 'Our Services')
        ->assertSet('editSubheading', "From boiler repairs to full bathroom installs.\n\nCovering Wigan and beyond.")
        ->assertSet('editing', 'home.services.0');

    $items = $component->get('editItems');
    expect($items)->toHaveCount(2);
    expect($items[0]['title'])->toBe('Boilers');
    expect($items[0]['body'])->toBe('Same-day emergency repairs.');
    expect($items[1]['title'])->toBe('Leaks');
    expect($items[1]['body'])->toBe('Simple string body');
});

test('saveSection preserves ProseMirror structured body when flattened text roundtrips unchanged', function () {
    $site = Site::factory()->create();
    $structuredDoc = [
        'type' => 'doc',
        'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Keep me']]]],
    ];
    $page = makePage($site->id, 'home', [
        'sections' => [
            [
                'type' => 'services',
                'title' => 'Our Services',
                'intro' => $structuredDoc,
                'items' => [
                    ['icon' => 'wrench', 'title' => 'Boilers', 'body' => $structuredDoc],
                ],
            ],
        ],
        'meta' => [],
    ]);

    $staff = \App\Models\User::factory()->staff(\App\Enums\AgentRole::Admin)->create();

    // Call edit first so editSubheading + editItems get populated from
    // the structured doc (flattened to "Keep me"). Then saveSection
    // without modifying them — roundtrip should preserve the docs.
    Livewire::actingAs($staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'services')
        ->set('editHeading', 'Our Updated Services')
        ->call('saveSection');

    $fresh = $page->fresh()->content_data;
    $services = $fresh['sections'][0];
    // Title was a string → updated
    expect($services['title'])->toBe('Our Updated Services');
    // intro preserved as structured doc (editSubheading echoed the flattened text)
    expect($services['intro'])->toBe($structuredDoc);
    // item body preserved as structured doc
    expect($services['items'][0]['body'])->toBe($structuredDoc);
});

test('hero section edit UI renders Title + Subtitle + CTA fields (new schema)', function () {
    $site = Site::factory()->create();
    makePage($site->id, 'home', [
        'sections' => [
            ['type' => 'hero', 'title' => 'H', 'subtitle' => 'S', 'cta_label' => 'C'],
        ],
        'meta' => [],
    ]);

    $staff = \App\Models\User::factory()->staff(\App\Enums\AgentRole::Admin)->create();

    Livewire::actingAs($staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'hero')
        ->assertSee('Title')
        ->assertSee('Subtitle')
        ->assertSee('CTA Button Text');
});

test('legacy section edit UI still renders Heading + Subheading labels (old schema)', function () {
    $site = Site::factory()->create();
    makePage($site->id, 'home', [
        'hero' => ['heading' => 'H', 'subheading' => 'S', 'cta_label' => 'C'],
    ]);

    $staff = \App\Models\User::factory()->staff(\App\Enums\AgentRole::Admin)->create();

    Livewire::actingAs($staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'hero')
        ->assertSee('Heading')
        ->assertSee('Subheading');
});

test('saveSection demotes structured field to plain string when user edits the text', function () {
    $site = Site::factory()->create();
    $structuredDoc = [
        'type' => 'doc',
        'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Original text']]]],
    ];
    $page = makePage($site->id, 'home', [
        'sections' => [
            ['type' => 'services', 'title' => 'Services', 'intro' => $structuredDoc, 'items' => []],
        ],
        'meta' => [],
    ]);

    $staff = \App\Models\User::factory()->staff(\App\Enums\AgentRole::Admin)->create();

    Livewire::actingAs($staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'services')
        ->set('editSubheading', 'New plain text intro')
        ->call('saveSection');

    $fresh = $page->fresh()->content_data;
    // intro demoted from structured doc to plain string — documented trade-off.
    expect($fresh['sections'][0]['intro'])->toBe('New plain text intro');
});

test('saveSection writes into list-shape sections without dropping other sections', function () {
    $site = Site::factory()->create();
    $page = makePage($site->id, 'home', [
        'sections' => [
            ['type' => 'hero', 'title' => 'Before', 'subtitle' => 'Sub before', 'cta_label' => 'CTA'],
            ['type' => 'services', 'title' => 'Svcs', 'intro' => 'i', 'items' => []],
        ],
        'meta' => ['seo' => 'keep'],
    ]);

    $staff = \App\Models\User::factory()->staff(\App\Enums\AgentRole::Admin)->create();

    Livewire::actingAs($staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'hero')
        ->set('editHeading', 'After')
        ->set('editSubheading', 'Sub after')
        ->call('saveSection');

    $fresh = $page->fresh()->content_data;
    expect($fresh['meta']['seo'])->toBe('keep');
    expect($fresh['sections'])->toHaveCount(2);
    expect($fresh['sections'][0]['type'])->toBe('hero');
    expect($fresh['sections'][0]['title'])->toBe('After');
    expect($fresh['sections'][0]['subtitle'])->toBe('Sub after');
    expect($fresh['sections'][1]['type'])->toBe('services');
    expect($fresh['sections'][1]['title'])->toBe('Svcs');
});
