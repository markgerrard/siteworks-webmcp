<?php

use App\Enums\AgentRole;
use App\Enums\PageKind;
use App\Enums\PageOrigin;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\User;
use App\Services\Site\CompositionDefaults;
use App\Services\Site\CompositionService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

function hierarchyPage(Site $site, string $pageType, ?GeneratedPage $parent = null, array $attributes = []): GeneratedPage
{
    return GeneratedPage::factory()->for($site)->create(array_merge([
        'page_type' => $pageType,
        'parent_id' => $parent?->id,
    ], $attributes));
}

it('exposes parent and children relationships', function () {
    $site = Site::factory()->create();
    $parent = hierarchyPage($site, 'projects');
    $child = hierarchyPage($site, 'projects/loft-conversion', $parent);

    expect($child->parent->is($parent))->toBeTrue()
        ->and($parent->children->sole()->is($child))->toBeTrue();
});

it('requires a parent from the same site', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $foreignParent = hierarchyPage($otherSite, 'projects');

    expect(fn () => hierarchyPage($site, 'projects/kitchen', $foreignParent))
        ->toThrow(DomainException::class, 'same site');
});

it('requires an existing parent', function () {
    $site = Site::factory()->create();

    expect(fn () => hierarchyPage($site, 'projects/kitchen', null, ['parent_id' => 999999]))
        ->toThrow(DomainException::class, 'does not exist');
});

it('rejects self parenting and ancestor cycles', function () {
    $site = Site::factory()->create();
    $root = hierarchyPage($site, 'projects');
    $child = hierarchyPage($site, 'projects/kitchen', $root);

    expect(fn () => $root->update(['parent_id' => $root->id]))
        ->toThrow(DomainException::class, 'own parent');

    expect(fn () => $root->update(['parent_id' => $child->id]))
        ->toThrow(DomainException::class, 'cycle');
});

it('allows four levels and rejects a fifth', function () {
    $site = Site::factory()->create();
    $levelOne = hierarchyPage($site, 'one');
    $levelTwo = hierarchyPage($site, 'one/two', $levelOne);
    $levelThree = hierarchyPage($site, 'one/two/three', $levelTwo);
    $levelFour = hierarchyPage($site, 'one/two/three/four', $levelThree);

    expect($levelFour->exists)->toBeTrue();
    expect(fn () => hierarchyPage($site, 'one/two/three/four/five', $levelFour))
        ->toThrow(DomainException::class, 'depth');
});

it('requires the parent path followed by one valid leaf segment', function (string $pageType) {
    $site = Site::factory()->create();
    $parent = hierarchyPage($site, 'projects');

    expect(fn () => hierarchyPage($site, $pageType, $parent))
        ->toThrow(DomainException::class, 'page_type');
})->with([
    'wrong prefix' => 'services/kitchen',
    'uppercase leaf' => 'projects/Kitchen',
    'empty leaf' => 'projects/',
    'extra segment' => 'projects/kitchen/photos',
    'underscore' => 'projects/kitchen_fit',
]);

it('rejects a nested full path longer than 200 characters', function () {
    $site = Site::factory()->create();
    $parent = hierarchyPage($site, 'projects');

    expect(fn () => hierarchyPage($site, 'projects/'.str_repeat('a', 192), $parent))
        ->toThrow(DomainException::class, '200');
});

it('rejects a root path longer than 200 characters', function () {
    $site = Site::factory()->create();

    expect(fn () => hierarchyPage($site, str_repeat('a', 201)))
        ->toThrow(DomainException::class, '200');
});

it('keeps page_type immutable once a page has been persisted', function () {
    $site = Site::factory()->create();
    $page = hierarchyPage($site, 'projects');

    expect(fn () => $page->update(['page_type' => 'our-projects']))
        ->toThrow(DomainException::class, 'immutable')
        ->and($page->fresh()->page_type)->toBe('projects');
});

it('blocks archive and soft delete when a live published child exists', function () {
    $site = Site::factory()->create();
    $parent = hierarchyPage($site, 'projects');
    hierarchyPage($site, 'projects/kitchen', $parent, ['status' => PageStatus::Published]);

    expect(fn () => $parent->update(['status' => PageStatus::Archived]))
        ->toThrow(RuntimeException::class, 'Archive children instead');

    $parent->refresh();
    expect(fn () => $parent->update(['archived_at' => now()]))
        ->toThrow(RuntimeException::class, 'Archive children instead')
        ->and(fn () => $parent->delete())
        ->toThrow(RuntimeException::class, 'Archive children instead');

    expect($parent->fresh()->status)->toBe(PageStatus::Published)
        ->and($parent->fresh()->deleted_at)->toBeNull();
});

it('permits archive when every child is draft or archived', function () {
    $site = Site::factory()->create();
    $parent = hierarchyPage($site, 'projects');
    hierarchyPage($site, 'projects/draft', $parent, ['status' => PageStatus::Draft]);
    hierarchyPage($site, 'projects/archived', $parent, ['status' => PageStatus::Archived]);

    $parent->update(['status' => PageStatus::Archived]);

    expect($parent->fresh()->status)->toBe(PageStatus::Archived)
        ->and($parent->fresh()->archived_at)->not->toBeNull();
});

it('checks root slugs only against roots and nested leaves as full paths', function () {
    $site = Site::factory()->create();
    $parent = hierarchyPage($site, 'projects');

    DB::table('generated_pages')->insert([
        'site_id' => $site->id,
        'parent_id' => $parent->id,
        'page_type' => 'contact',
        'content_data' => '{}',
        'version' => 1,
        'status' => PageStatus::Published->value,
        'hero_source' => 'shared',
        'origin' => 'pipeline',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(GeneratedPage::slugIsTaken($site, 'contact'))->toBeFalse()
        ->and(GeneratedPage::slugIsTaken($site, 'kitchen', $parent))->toBeFalse();

    hierarchyPage($site, 'contact');
    hierarchyPage($site, 'projects/kitchen', $parent);

    expect(GeneratedPage::slugIsTaken($site, 'contact'))->toBeTrue()
        ->and(GeneratedPage::slugIsTaken($site, 'kitchen', $parent))->toBeTrue()
        ->and(GeneratedPage::slugIsTaken($site, 'projects/kitchen'))->toBeTrue();
});

it('returns page_type verbatim as the canonical public path', function () {
    $site = Site::factory()->create();
    $parent = hierarchyPage($site, 'projects');
    $child = hierarchyPage($site, 'projects/loft-conversion', $parent);

    expect($parent->publicPath())->toBe('projects')
        ->and($child->publicPath())->toBe('projects/loft-conversion');
});

it('edits a section on a nested page without silently rejecting its path', function () {
    $staff = User::factory()->staff(AgentRole::Admin)->create();
    $site = Site::factory()->create();
    $parent = hierarchyPage($site, 'projects');
    $content = ['sections' => [['type' => 'hero', 'title' => 'Original title']]];
    $child = hierarchyPage($site, 'projects/loft-conversion', $parent, ['content_data' => $content]);
    $revision = PageRevision::factory()->for($child, 'page')->create(['content_data' => $content]);
    $child->update(['published_revision_id' => $revision->id]);
    Preview::factory()->for($site)->create();

    Livewire::actingAs($staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', $child->page_type, 'hero')
        ->set('editHeading', 'Nested title updated')
        ->call('saveSection');

    $draft = $child->fresh()->draftRevision;
    expect($draft)->not->toBeNull()
        ->and($draft->content_data['sections'][0]['title'])->toBe('Nested title updated');
});

it('excludes nested pages from default top-level nav composition', function () {
    $site = Site::factory()->create();
    hierarchyPage($site, 'home');
    $parent = hierarchyPage($site, 'projects');
    $child = hierarchyPage($site, 'projects/loft-conversion', $parent);

    $composition = app(CompositionDefaults::class)->forSite($site);

    expect(collect($composition['nav']['items'])->pluck('page_id'))
        ->toContain($parent->id)
        ->not->toContain($child->id);
});
