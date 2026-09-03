<?php

use App\Enums\PageKind;
use App\Models\Site\PageRevision;
use Livewire\Livewire;

it('page-layout-override mounts for the demo user on site 64 and writes a layout key', function () {
    [$site, $user] = demoSite64();
    $page = $site->generatedPages()->where('page_type', 'about')->first()
        ?? $site->generatedPages()->where('kind', PageKind::Service)->firstOrFail();

    Livewire::actingAs($user)
        ->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id])
        ->call('setOverride', 'editorial')
        ->assertOk();

    expect($page->fresh()->layout_preset_key)->toBe('editorial');
});

it('section-style-picker mounts for the demo user on site 64 and writes a variant', function () {
    [$site, $user] = demoSite64();
    $page = demoSite64HomePage($site);
    $revision = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'H'],
            ['type' => 'services', 'title' => 'S', 'items' => [['title' => 'a', 'body' => 'b']]],
        ]],
    ]);
    $page->update(['published_revision_id' => $revision->id, 'draft_revision_id' => null]);

    Livewire::actingAs($user)
        ->test('section-style-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'sectionIndex' => 1])
        ->call('setVariant', 'featured-ledger')
        ->assertOk();

    $draft = $page->fresh()->draftRevision;
    expect($draft)->not->toBeNull()
        ->and($draft->content_data['sections'][1]['variant'])->toBe('featured-ledger');
});
