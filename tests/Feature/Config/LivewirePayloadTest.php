<?php

use App\Enums\AgentRole;
use App\Enums\PageKind;
use App\Models\GeneratedPage;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\User;
use Livewire\Livewire;

/**
 * Regression guard for the TooManyComponentsException incident class: the
 * agent site editor mounts every tab's Livewire components at once (Alpine
 * x-show, not @if), plus one project-item-card per project item. When a
 * /livewire/update batch pools more component snapshots than
 * livewire.payload.max_components, Livewire rejects the whole request and
 * the editor breaks mid-edit.
 *
 * The limit must stay above the statically-mounted components with room for
 * a realistic number of project items — raise it whenever the editor grows.
 *
 * Static `<livewire:` tag counts cannot see per-row mounts inside @foreach.
 * The runtime case below renders page-manager for a 12×10 fixture and counts
 * wire:id / lazy-placeholder occurrences in the HTML Livewire actually ships.
 */

/**
 * Number of `<livewire:` mount points declared in a Blade view.
 */
function livewireMountCount(string $view): int
{
    return preg_match_all('/<livewire:/', file_get_contents(resource_path($view)));
}

/**
 * Nested Livewire instances actually present in rendered HTML (eager wire:id
 * plus lazy-bundle placeholders). Each is a snapshot that would join a
 * /livewire/update batch.
 */
function livewireRuntimeInstanceCount(string $html): int
{
    $ids = preg_match_all('/\bwire:id="/', $html) ?: 0;
    $lazy = preg_match_all('/\bwire:lazy(?:\.|=|\s|$)/', $html) ?: 0;

    return max($ids, $lazy);
}

it('allows enough components per batch for the heaviest site editor screen', function () {
    $maxComponents = config('livewire.payload.max_components');

    expect($maxComponents)->toBeInt();

    // The site editor is split into section pages (T-nav); the heaviest single page bounds the batch.
    $sectionCounts = array_map(
        fn (string $view): int => livewireMountCount(str_replace(resource_path().'/', '', $view)),
        glob(resource_path('views/sites/sections/*.blade.php')) ?: [],
    );
    $chrome = ($sectionCounts === [] ? 0 : max($sectionCounts)) + livewireMountCount('views/components/sites/page.blade.php');
    $pageManager = livewireMountCount('views/livewire/page-manager.blade.php');

    expect($chrome)->toBeGreaterThan(0);
    expect($pageManager)->toBeGreaterThan(0);

    /** project-item-card instances the gallery + case-study editors must support */
    $projectItemHeadroom = 50;

    $required = $chrome + $pageManager + $projectItemHeadroom;

    expect($maxComponents)->toBeGreaterThanOrEqual(
        $required,
        "livewire.payload.max_components ({$maxComponents}) must cover the site editor: "
        ."{$chrome} chrome components + {$pageManager} page-manager children + "
        ."{$projectItemHeadroom} project-item-card slots = {$required}. "
        .'Below this, agents hit TooManyComponentsException while editing.'
    );
});

it('keeps the remaining livewire payload guards enabled', function () {
    expect(config('livewire.payload.max_size'))->not->toBeNull()
        ->and(config('livewire.payload.max_nesting_depth'))->not->toBeNull()
        ->and(config('livewire.payload.max_calls'))->not->toBeNull();
});

it('leaves non-payload livewire defaults to the package', function () {
    // Only these keys may be overridden; everything else must keep coming
    // from the package config via mergeConfigFrom so upgrades aren't frozen.
    expect(array_keys(require config_path('livewire.php')))
        ->toBe(['payload', 'component_placeholder', 'temporary_file_upload']);
});

it('points the lazy placeholder at an existing view', function () {
    expect(view()->exists(config('livewire.component_placeholder')))->toBeTrue();
});

it('keeps a 12-page × 10-section page-manager render under the payload cap', function () {
    $maxComponents = config('livewire.payload.max_components');
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    $pageTypes = array_merge(['home', 'about', 'contact', 'projects'], array_map(
        fn (int $i): string => 'service-'.$i,
        range(1, 8),
    ));

    $projectsPage = null;
    foreach ($pageTypes as $i => $pageType) {
        $kind = in_array($pageType, ['home', 'about', 'contact', 'projects'], true)
            ? PageKind::Core
            : PageKind::Service;
        $sections = [];
        for ($s = 0; $s < 10; $s++) {
            $sections[] = ['type' => 'hero', 'title' => "p{$i}s{$s}"];
        }
        $page = GeneratedPage::factory()->for($site)->create([
            'page_type' => $pageType,
            'kind' => $kind,
            'sort_order' => $i,
        ]);
        $revision = PageRevision::factory()->for($page, 'page')->create([
            'content_data' => ['sections' => $sections],
        ]);
        $page->update(['published_revision_id' => $revision->id]);
        if ($pageType === 'projects') {
            $projectsPage = $page;
        }
    }

    expect($projectsPage)->not->toBeNull();
    ProjectItem::factory()->count(50)->gallery()->create([
        'site_id' => $site->id,
        'page_id' => $projectsPage->id,
    ]);

    $html = Livewire::actingAs($agent)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'hero', 3)
        ->html();

    expect(substr_count($html, 'data-image-slot-pickers'))->toBeLessThanOrEqual(1)
        ->and(livewireRuntimeInstanceCount($html))->toBeLessThanOrEqual($maxComponents);
});
