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
function renderBandedDetailSection(string $type, array $section, array $extra = []): string
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

it('maps the banded personality to its own detail variants', function () {
    expect(config('site_project_detail_layouts.banded.variants.project_about'))->toBe('banded')
        ->and(config('site_project_detail_layouts.banded.variants.project_photo_essay'))->toBe('banded');
});

it('renders the banded About as a surface-alt split band with checklist meta', function () {
    $html = renderBandedDetailSection('project_about', [
        'variant' => 'banded',
        'title' => 'A quiet extra storey',
        'body' => 'A fuller story about this project with enough said to earn a page of its own.',
        'project_type' => 'Loft conversion',
        'location' => 'Wigan',
    ]);

    expect($html)->toContain('data-svc-variant="banded"')
        ->and($html)->toContain('background-color: var(--color-surface-alt)')
        ->and($html)->toContain('lg:grid-cols-12')
        ->and($html)->toContain('background-color: var(--brand-accent)')   // checklist square markers
        ->and($html)->toContain('Loft conversion');
});

it('renders the banded essay as alternating full-width bands with facing captions', function () {
    $site = Site::factory()->create();
    $media = collect(['one', 'two', 'three'])->map(fn (string $name) => SiteMedia::factory()->for($site)->create([
        'url' => 'https://cdn.test/'.$name.'.jpg',
        'alt_text' => $name.' alt',
        'metadata' => ['caption' => ucfirst($name).' caption'],
    ]));

    $html = renderBandedDetailSection('project_photo_essay', [
        'variant' => 'banded',
        'title' => 'The work',
        'image_ids' => $media->pluck('id')->all(),
    ], ['mediaById' => $media->keyBy('id')]);

    expect($html)->toContain('data-svc-variant="banded"')
        ->and($html)->toContain('md:col-span-7')
        ->and($html)->toContain('md:order-2')                    // alternation
        ->and($html)->toContain('background-color: var(--color-surface-alt)')
        ->and($html)->toContain('Two caption')
        ->and($html)->toContain('>03<');
});
