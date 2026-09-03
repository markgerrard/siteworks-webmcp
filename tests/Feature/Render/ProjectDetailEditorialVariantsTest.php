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
function renderEditorialDetailSection(string $type, array $section, array $extra = []): string
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

it('maps the editorial personality to its own detail variants', function () {
    expect(config('site_project_detail_layouts.editorial.variants.project_about'))->toBe('editorial')
        ->and(config('site_project_detail_layouts.editorial.variants.project_photo_essay'))->toBe('editorial');
});

it('renders the editorial About as a ledger: ruled eyebrow, meta rows, two-column prose', function () {
    $html = renderEditorialDetailSection('project_about', [
        'variant' => 'editorial',
        'title' => 'A quiet extra storey',
        'body' => 'A fuller story about this project with enough said to earn a page of its own.',
        'project_type' => 'Loft conversion',
        'location' => 'Wigan',
    ]);

    expect($html)->toContain('data-project-about')
        ->and($html)->toContain('data-svc-variant="editorial"')
        ->and($html)->toContain('border-top: 2px solid var(--brand-accent)')
        ->and($html)->toContain('md:columns-2')
        ->and($html)->toContain('Loft conversion')
        ->and($html)->toContain('Wigan');
});

it('renders the editorial essay as offset plates with index-first caption rows', function () {
    $site = Site::factory()->create();
    $media = collect(['one', 'two', 'three'])->map(fn (string $name) => SiteMedia::factory()->for($site)->create([
        'url' => 'https://cdn.test/'.$name.'.jpg',
        'alt_text' => $name.' alt',
        'metadata' => ['caption' => ucfirst($name).' caption'],
    ]));

    $html = renderEditorialDetailSection('project_photo_essay', [
        'variant' => 'editorial',
        'title' => 'The work',
        'image_ids' => $media->pluck('id')->all(),
    ], ['mediaById' => $media->keyBy('id')]);

    expect($html)->toContain('data-svc-variant="editorial"')
        ->and($html)->toContain('md:col-span-10')
        ->and($html)->toContain('md:col-start-3')
        ->and($html)->toContain('aspect-[3/2]')
        ->and($html)->toContain('One caption')
        ->and($html)->toContain('>03<')
        ->and($html)->toContain('alt="one alt"')          // alt text stays in the attribute...
        ->and($html)->not->toMatch('/>[^<]*one alt[^<]*</'); // ...and never renders as visible copy
});
