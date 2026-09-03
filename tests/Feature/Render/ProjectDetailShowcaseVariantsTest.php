<?php

use App\Enums\PageKind;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\SiteMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $section
 * @param  array<string, mixed>  $extra
 */
function renderShowcaseDetailSection(string $type, array $section, array $extra = []): string
{
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $parent = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'projects', 'kind' => PageKind::Core, 'nav_label' => 'Our Work', 'status' => PageStatus::Published,
    ]);
    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'projects/loft-one', 'parent_id' => $parent->id,
        'kind' => PageKind::ProjectDetail, 'nav_label' => 'Loft One', 'status' => PageStatus::Published,
    ]);

    return View::make("site.sections.{$type}", array_merge([
        'section' => array_merge(['type' => $type], $section),
        'sectionIndex' => 0,
        'pageId' => $page->id,
        'mode' => 'public',
        'emitMarkers' => false,
        'emitFormMarkers' => false,
        'schema' => [],
        'theme' => [],
        'profile' => [],
        'site' => $site,
        'page' => $page,
        'pagesBySlug' => ['home' => '/', 'projects' => '/projects', $page->page_type => '/'.$page->page_type],
        'itemsById' => collect(),
        'mediaById' => collect(),
        'pinnedPages' => collect(),
    ], $extra))->render();
}

it('maps the showcase personality to its own detail variants', function () {
    expect(config('site_project_detail_layouts.showcase.variants.project_about'))->toBe('showcase')
        ->and(config('site_project_detail_layouts.showcase.variants.project_photo_essay'))->toBe('showcase');
});

it('renders the showcase About as an image-led panel with meta chips', function () {
    $site = Site::factory()->create();
    $photo = SiteMedia::factory()->for($site)->create([
        'url' => 'https://cdn.test/panel.jpg',
        'alt_text' => 'panel alt',
    ]);

    $html = renderShowcaseDetailSection('project_about', [
        'variant' => 'showcase',
        'title' => 'A quiet extra storey',
        'body' => 'A fuller story about this project with enough said to earn a page of its own.',
        'project_type' => 'Loft conversion',
        'location' => 'Wigan',
        'image_id' => $photo->id,
    ], ['mediaById' => collect([$photo->id => $photo])]);

    expect($html)->toContain('data-svc-variant="showcase"')
        ->and($html)->toContain('background-color: var(--color-surface-alt)')
        ->and($html)->toContain('https://cdn.test/panel.jpg')
        ->and($html)->toContain('aspect-[4/5]')
        ->and($html)->toContain('rounded-full')      // meta chips
        ->and($html)->toContain('lg:col-span-7');
});

it('showcase About without an image renders the review full-width', function () {
    $html = renderShowcaseDetailSection('project_about', [
        'variant' => 'showcase',
        'title' => 'No photo yet',
        'body' => 'Body copy that still deserves the review treatment.',
    ]);

    // Panel padding is the discriminator — classic fallback would also
    // have no <img> AND still stamps data-svc-variant (review finding),
    // so those two alone can never go red.
    expect($html)->toContain('p-6 sm:p-10 lg:p-12')
        ->and($html)->toContain('background-color: var(--color-surface-alt)')
        ->and($html)->not->toContain('<img');
});

it('renders the showcase essay as a 2-up grid with scrim captions', function () {
    $site = Site::factory()->create();
    $media = collect(['one', 'two', 'three', 'four'])->map(fn (string $name) => SiteMedia::factory()->for($site)->create([
        'url' => 'https://cdn.test/'.$name.'.jpg',
        'alt_text' => $name.' alt',
        'metadata' => ['caption' => ucfirst($name).' caption'],
    ]));

    $html = renderShowcaseDetailSection('project_photo_essay', [
        'variant' => 'showcase',
        'title' => 'The work',
        'image_ids' => $media->pluck('id')->all(),
    ], ['mediaById' => $media->keyBy('id')]);

    expect($html)->toContain('data-svc-variant="showcase"')
        ->and($html)->toContain('sm:grid-cols-2')
        ->and($html)->toContain('aspect-[3/2]')
        ->and($html)->toContain('linear-gradient')
        ->and($html)->toContain('Four caption')
        ->and($html)->toContain('>04<');
});
