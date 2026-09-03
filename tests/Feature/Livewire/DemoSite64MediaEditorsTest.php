<?php

use App\Models\HeroVersion;
use App\Models\SiteMedia;
use App\Support\Media\MediaKind;
use App\Support\Media\MediaOrigin;
use Livewire\Livewire;

it('media.picker mounts for the demo user on site 64 and opens the library', function () {
    [$site, $user] = demoSite64();
    SiteMedia::factory()->for($site)->create([
        'title' => 'Camino storefront',
        'kind' => MediaKind::Image,
        'origin' => MediaOrigin::Uploaded,
        'provisional' => false,
    ]);

    Livewire::actingAs($user)
        ->test('media.picker', ['siteId' => $site->id, 'model' => 'brandImageMediaId', 'slotLabel' => 'Brand row'])
        ->call('openPicker')
        ->assertOk()
        ->assertSee('Library')
        ->assertSee('Upload')
        ->assertDontSee('Generate')
        ->assertSee('Camino storefront');
});

it('image-slot-picker mounts for the demo user on site 64 and selects a version', function () {
    [$site, $user] = demoSite64();
    $page = demoSite64HomePage($site);
    $a = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'is_active' => true,
    ]);
    $b = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'is_active' => false,
    ]);

    Livewire::actingAs($user)
        ->test('image-slot-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'slot' => 'hero'])
        ->call('select', $b->id)
        ->assertOk();

    expect($a->fresh()->is_active)->toBeFalse()
        ->and($b->fresh()->is_active)->toBeTrue();
});
