<?php

use App\Enums\ProjectItemStatus;
use App\Enums\ProjectItemType;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\SiteMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function renderServiceGallery(array $section, $items): string
{
    return view('site.sections.service_gallery', [
        'section' => array_merge(['type' => 'service_gallery'], $section),
        'sectionIndex' => 0,
        'pageId' => 1,
        'emitMarkers' => false,
        'serviceGalleryItems' => collect(['Bathrooms' => collect($items)])->map(fn ($g) => collect($g)),
    ])->render();
}

function makeGalleryItem(Site $site, string $title): ProjectItem
{
    $media = SiteMedia::factory()->for($site)->create();

    return ProjectItem::factory()->for($site)->create([
        'type' => ProjectItemType::Gallery,
        'status' => ProjectItemStatus::Published,
        'category' => 'Bathrooms',
        'title' => $title,
        'image_id' => $media->id,
    ]);
}

test('renders nothing when the feature flag is off', function () {
    config(['site.service_page_galleries_enabled' => false]);
    $site = Site::factory()->create();
    $item = makeGalleryItem($site, 'Herne Hill bathroom');

    $html = renderServiceGallery(['category' => 'Bathrooms'], [$item]);

    expect(trim($html))->toBe('');
});

test('renders gallery tiles with lazy images when the flag is on', function () {
    config(['site.service_page_galleries_enabled' => true]);
    $site = Site::factory()->create();
    $item = makeGalleryItem($site, 'Herne Hill bathroom');

    $html = renderServiceGallery(['category' => 'Bathrooms', 'title' => 'Bathroom Projects'], [$item]);

    expect($html)->toContain('Bathroom Projects')
        ->and($html)->toContain('loading="lazy"')
        ->and($html)->toContain($item->image->url)
        ->and($html)->not->toContain('View more');
});

test('caps initial render and offers view-more for the remainder', function () {
    config(['site.service_page_galleries_enabled' => true]);
    $site = Site::factory()->create();
    $items = collect(range(1, 35))->map(fn ($i) => makeGalleryItem($site, "Job {$i}"));

    $html = renderServiceGallery(['category' => 'Bathrooms'], $items);

    expect($html)->toContain('View more (3)')
        ->and(substr_count($html, 'x-show="expanded"'))->toBe(3);
});

test('renders nothing for a category with no items', function () {
    config(['site.service_page_galleries_enabled' => true]);

    $html = renderServiceGallery(['category' => 'Roofing'], []);

    expect(trim($html))->toBe('');
});
