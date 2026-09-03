<?php

use App\Enums\PageKind;
use App\Enums\PageStatus;
use App\Enums\ProjectItemType;
use App\Models\GeneratedPage;
use App\Models\ProjectItem;
use Livewire\Livewire;

function demoSite64ProjectsPage($site): GeneratedPage
{
    $page = $site->generatedPages()->where('page_type', 'projects')->first();
    if ($page) {
        return $page;
    }

    return GeneratedPage::factory()->for($site)->create([
        'page_type' => 'projects',
        'kind' => PageKind::Core,
        'status' => PageStatus::Published,
        'content_data' => [
            'sections' => [
                ['type' => 'projects_hero', 'hero_enabled' => true, 'title' => 'Our work'],
            ],
        ],
    ]);
}

it('projects-page-editor mounts for the demo user on site 64 and toggles the hero', function () {
    [$site, $user] = demoSite64();
    $page = demoSite64ProjectsPage($site);

    Livewire::actingAs($user)
        ->test('projects-page-editor', ['siteId' => $site->id])
        ->call('toggleHero', false)
        ->assertOk()
        ->assertSet('heroEnabled', false);

    $sections = $page->fresh()->content_data['sections'] ?? [];
    $hero = collect($sections)->firstWhere('type', 'projects_hero');
    expect($hero['hero_enabled'] ?? true)->toBeFalse();
});

it('projects-gallery-editor mounts for the demo user on site 64 and adds a tile', function () {
    [$site, $user] = demoSite64();
    $site->forceFill(['project_categories' => ['Cakes']])->save();
    $page = demoSite64ProjectsPage($site);

    Livewire::actingAs($user)
        ->test('projects-gallery-editor', ['siteId' => $site->id, 'pageId' => $page->id])
        ->call('openAddModal')
        ->set('newTitle', 'Wedding cake')
        ->set('newDescription', 'Three-tier buttercream.')
        ->set('newCategory', 'Cakes')
        ->set('newImageMode', 'none')
        ->call('addTile')
        ->assertOk()
        ->assertHasNoErrors();

    expect(ProjectItem::query()->where('site_id', $site->id)->where('type', ProjectItemType::Gallery)->count())->toBe(1);
});

it('case-study-editor mounts for the demo user on site 64 and adds a case study', function () {
    [$site, $user] = demoSite64();
    $site->forceFill(['project_categories' => ['Cakes']])->save();
    $page = demoSite64ProjectsPage($site);

    Livewire::actingAs($user)
        ->test('case-study-editor', ['siteId' => $site->id, 'pageId' => $page->id])
        ->call('openAddModal')
        ->set('newTitle', 'Palo Alto wedding')
        ->set('newDescription', 'Flagship tiered cake.')
        ->set('newCategory', 'Cakes')
        ->set('newImageMode', 'none')
        ->call('addCaseStudy')
        ->assertOk()
        ->assertHasNoErrors();

    expect(ProjectItem::query()->where('site_id', $site->id)->where('type', ProjectItemType::CaseStudy)->count())->toBe(1);
});
