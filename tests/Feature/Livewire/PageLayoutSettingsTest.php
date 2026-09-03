<?php

use App\Enums\AgentRole;
use App\Enums\PageKind;
use App\Models\GeneratedPage;
use App\Models\LayoutPreset;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * @return array{service: string, about: string, home: string}
 */
function pageLayoutSettingsColumn(string $kind): string
{
    return match ($kind) {
        'service' => 'services_layout',
        'about' => 'about_layout',
        'home' => 'home_layout',
    };
}

it('agent can switch the layout to each built-in key', function (string $kind, string $key) {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $column = pageLayoutSettingsColumn($kind);

    Livewire::actingAs($agent)
        ->test('page-layout-settings', ['siteId' => $site->id, 'kind' => $kind])
        ->call('setLayout', $key);

    expect($site->fresh()->{$column})->toBe($key);
})->with([
    'service classic' => ['service', 'classic'],
    'service editorial' => ['service', 'editorial'],
    'service showcase' => ['service', 'showcase'],
    'service precision' => ['service', 'precision'],
    'about classic' => ['about', 'classic'],
    'about editorial' => ['about', 'editorial'],
    'about showcase' => ['about', 'showcase'],
    'about precision' => ['about', 'precision'],
    'home classic' => ['home', 'classic'],
    'home editorial' => ['home', 'editorial'],
    'home showcase' => ['home', 'showcase'],
    'home precision' => ['home', 'precision'],
]);

it('switching the layout invalidates the public page cache', function (string $kind, string $key) {
    config(['site.public_cache_enabled' => true]);
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    $counterKey = "site:{$site->id}:pubcache_counter";
    $before = (int) cache()->get($counterKey, 0);

    Livewire::actingAs($agent)
        ->test('page-layout-settings', ['siteId' => $site->id, 'kind' => $kind])
        ->call('setLayout', $key);

    expect((int) cache()->get($counterKey, 0))->toBeGreaterThan($before);
})->with([
    'service' => ['service', 'precision'],
    'about' => ['about', 'precision'],
    'home' => ['home', 'editorial'],
]);

it('a user without access to the site cannot mount or change its layout', function (string $kind) {
    $owner = User::factory()->staff(AgentRole::Agent)->create();
    $outsider = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $owner->id]);
    $column = pageLayoutSettingsColumn($kind);

    Livewire::actingAs($outsider)
        ->test('page-layout-settings', ['siteId' => $site->id, 'kind' => $kind])
        ->assertStatus(403);

    expect($site->fresh()->{$column})->toBe('classic');
})->with(['service', 'about', 'home']);

it('rejects a key that is not in optionsFor', function (string $kind) {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $column = pageLayoutSettingsColumn($kind);

    Livewire::actingAs($agent)
        ->test('page-layout-settings', ['siteId' => $site->id, 'kind' => $kind])
        ->call('setLayout', 'not-a-preset')
        ->assertHasErrors(['layout']);

    expect($site->fresh()->{$column})->toBe('classic');
})->with(['service', 'about', 'home']);

it('rejects another sites active bespoke key', function (string $kind) {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $other = Site::factory()->create();
    $column = pageLayoutSettingsColumn($kind);

    LayoutPreset::factory()->for($other)->active()->create([
        'page_kind' => $kind,
        'key' => 'bespoke-other',
    ]);

    Livewire::actingAs($agent)
        ->test('page-layout-settings', ['siteId' => $site->id, 'kind' => $kind])
        ->call('setLayout', 'bespoke-other')
        ->assertHasErrors(['layout']);

    expect($site->fresh()->{$column})->toBe('classic');
})->with(['service', 'about', 'home']);

it('rejects a hard-invalid active recipe for the site', function (string $kind) {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $column = pageLayoutSettingsColumn($kind);

    LayoutPreset::factory()->for($site)->active()->create([
        'page_kind' => $kind,
        'key' => 'broken-recipe',
        'label' => 'Broken',
        'recipe' => [
            'schema_version' => '1',
            'variants' => ['intro' => 'editorial', 'features' => 'numbered'],
            'eyebrow_policy' => 'first-only',
        ],
    ]);

    Livewire::actingAs($agent)
        ->test('page-layout-settings', ['siteId' => $site->id, 'kind' => $kind])
        ->call('setLayout', 'broken-recipe')
        ->assertHasErrors(['layout']);

    expect($site->fresh()->{$column})->toBe('classic');
})->with(['service', 'about', 'home']);

it('rejects a stock key when the sites active row for that kind is hard-invalid', function (string $kind, string $stockKey) {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $other = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $column = pageLayoutSettingsColumn($kind);

    LayoutPreset::factory()->for($site)->active()->create([
        'page_kind' => $kind,
        'key' => $stockKey,
        'label' => 'Broken '.$stockKey,
        'recipe' => [
            'schema_version' => '1',
            'variants' => ['intro' => 'editorial', 'features' => 'numbered'],
            'eyebrow_policy' => 'first-only',
        ],
    ]);

    Livewire::actingAs($agent)
        ->test('page-layout-settings', ['siteId' => $site->id, 'kind' => $kind])
        ->call('setLayout', $stockKey)
        ->assertHasErrors(['layout']);

    expect($site->fresh()->{$column})->toBe('classic');

    Livewire::actingAs($agent)
        ->test('page-layout-settings', ['siteId' => $other->id, 'kind' => $kind])
        ->call('setLayout', $stockKey);

    expect($other->fresh()->{$column})->toBe($stockKey);
})->with([
    'service editorial' => ['service', 'editorial'],
    'about editorial' => ['about', 'editorial'],
    'home showcase' => ['home', 'showcase'],
]);

it('rejects an about bespoke key that stamps a hero family', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    LayoutPreset::factory()->for($site)->active()->create([
        'page_kind' => 'about',
        'key' => 'hero-bespoke',
        'label' => 'Hero about',
        'recipe' => [
            'schema_version' => 1,
            'variants' => ['hero' => 'boxed-left', 'story' => 'editorial', 'values' => 'ledger'],
            'eyebrow_policy' => 'first-only',
            'eyebrow_sections' => ['story', 'values'],
        ],
    ]);

    Livewire::actingAs($agent)
        ->test('page-layout-settings', ['siteId' => $site->id, 'kind' => 'about'])
        ->call('setLayout', 'hero-bespoke')
        ->assertHasErrors(['layout']);

    expect($site->fresh()->about_layout)->toBe('classic');
});

it('rejects an about bespoke key with an unknown family', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    LayoutPreset::factory()->for($site)->active()->create([
        'page_kind' => 'about',
        'key' => 'typo-bespoke',
        'label' => 'Typo about',
        'recipe' => [
            'schema_version' => 1,
            'variants' => ['storys' => 'editorial', 'values' => 'ledger'],
            'eyebrow_policy' => 'first-only',
            'eyebrow_sections' => ['story', 'values'],
        ],
    ]);

    Livewire::actingAs($agent)
        ->test('page-layout-settings', ['siteId' => $site->id, 'kind' => 'about'])
        ->call('setLayout', 'typo-bespoke')
        ->assertHasErrors(['layout']);

    expect($site->fresh()->about_layout)->toBe('classic');
});

it('accepts the sites own active bespoke key', function (string $kind) {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $column = pageLayoutSettingsColumn($kind);

    $recipe = match ($kind) {
        'about' => [
            'schema_version' => 1,
            'variants' => ['story' => 'editorial', 'values' => 'ledger'],
            'eyebrow_policy' => 'first-only',
            'eyebrow_sections' => ['story', 'values'],
        ],
        'home' => [
            'schema_version' => 1,
            'variants' => ['hero' => 'boxed-left'],
            'eyebrow_policy' => 'all',
            'eyebrow_sections' => [],
        ],
        default => [
            'schema_version' => 1,
            'variants' => ['intro' => 'editorial', 'features' => 'numbered'],
            'eyebrow_policy' => 'first-only',
            'eyebrow_sections' => ['intro', 'features'],
        ],
    };

    LayoutPreset::factory()->for($site)->active()->create([
        'page_kind' => $kind,
        'key' => 'bespoke-mine',
        'label' => 'Bespoke mine',
        'recipe' => $recipe,
    ]);

    Livewire::actingAs($agent)
        ->test('page-layout-settings', ['siteId' => $site->id, 'kind' => $kind])
        ->call('setLayout', 'bespoke-mine');

    expect($site->fresh()->{$column})->toBe('bespoke-mine');
})->with(['service', 'about', 'home']);

it('js-encodes a quote-bearing bespoke key in the picker action', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $key = "bespoke-o'quote";

    LayoutPreset::factory()->for($site)->active()->create([
        'page_kind' => 'service',
        'key' => $key,
        'label' => 'Quoted bespoke',
        'recipe' => [
            'schema_version' => 1,
            'variants' => ['intro' => 'editorial', 'features' => 'numbered'],
            'eyebrow_policy' => 'first-only',
            'eyebrow_sections' => ['intro', 'features'],
        ],
    ]);

    $html = Livewire::actingAs($agent)
        ->test('page-layout-settings', ['siteId' => $site->id, 'kind' => 'service'])
        ->html();

    expect($html)->not->toContain("setLayout('{$key}')")
        ->and($html)->not->toContain("setLayout('bespoke-o&#039;quote')")
        ->and($html)->toContain("setLayout('bespoke-o\\u0027quote')");
});

it('writes only the mounted kind column', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $agent->id,
        'services_layout' => 'classic',
        'about_layout' => 'classic',
        'home_layout' => 'classic',
    ]);

    Livewire::actingAs($agent)
        ->test('page-layout-settings', ['siteId' => $site->id, 'kind' => 'about'])
        ->call('setLayout', 'editorial');

    $fresh = $site->fresh();
    expect($fresh->about_layout)->toBe('editorial')
        ->and($fresh->services_layout)->toBe('classic')
        ->and($fresh->home_layout)->toBe('classic');
});

it('pagesForKind scopes to the kind page types and selects only adjacency columns', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $agent->id,
        'home_layout' => 'editorial',
    ]);
    GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Home']]],
    ]);
    GeneratedPage::factory()->for($site)->create([
        'page_type' => 'about',
        'kind' => PageKind::Core,
        'content_data' => ['sections' => [['type' => 'story', 'title' => 'About']]],
    ]);
    GeneratedPage::factory()->for($site)->create([
        'page_type' => 'plumbing',
        'kind' => PageKind::Service,
        'content_data' => ['sections' => [['type' => 'intro', 'title' => 'Plumbing']]],
    ]);

    $queries = [];
    DB::listen(function (object $query) use (&$queries): void {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'generated_pages') && str_contains($sql, 'archived_at')) {
            $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        }
    });

    Livewire::actingAs($agent)
        ->test('page-layout-settings', ['siteId' => $site->id, 'kind' => 'home']);

    expect($queries)->not->toBeEmpty();
    $sql = strtolower($queries[0]['sql']);
    expect($sql)->toContain(' in (')
        ->and($sql)->toContain('page_type')
        ->and($sql)->toContain('content_data')
        ->and($sql)->not->toContain('select *')
        ->and($queries[0]['bindings'])->toContain('home');

    $blade = file_get_contents(resource_path('views/livewire/page-layout-settings.blade.php'));
    expect($blade)->toContain("whereIn('page_type'")
        ->and($blade)->toContain('pageTypesForKind')
        ->and($blade)->toContain("['id', 'page_type', 'kind', 'content_data']");
});

it('locks kind against client writes', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    $component = Livewire::actingAs($agent)
        ->test('page-layout-settings', ['siteId' => $site->id, 'kind' => 'service']);

    expect(fn () => $component->set('kind', 'about'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('renders recipeWarnings and adjacencyWarnings for the selected site recipe', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id, 'home_layout' => 'classic']);
    $items = array_fill(0, 8, ['title' => 't', 'body' => 'b']);

    GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'content_data' => ['sections' => [
            ['type' => 'services', 'variant' => 'numbered-rows', 'items' => $items],
            ['type' => 'trust', 'variant' => 'numbered-rows', 'items' => $items],
        ]],
    ]);

    LayoutPreset::factory()->for($site)->active()->create([
        'page_kind' => 'home',
        'key' => 'double-ledger',
        'label' => 'Double ledger',
        'recipe' => [
            'schema_version' => 1,
            'variants' => [
                'hero' => 'boxed-left',
                'services' => 'numbered-rows',
                'trust' => 'numbered-rows',
            ],
            'eyebrow_policy' => 'all',
            'eyebrow_sections' => [],
        ],
    ]);

    $component = Livewire::actingAs($agent)
        ->test('page-layout-settings', ['siteId' => $site->id, 'kind' => 'home'])
        ->assertDontSee('two ledgers on one home')
        ->assertDontSee('single-column ledger');

    $component->call('setLayout', 'double-ledger')
        ->assertSee('two ledgers on one home')
        ->assertSee('single-column ledger')
        ->assertHasNoErrors();

    expect($site->fresh()->home_layout)->toBe('double-ledger');
});

it('page manager mounts the homepage picker and Design hosts the about and service pickers', function () {
    $pageManager = file_get_contents(resource_path('views/livewire/page-manager.blade.php'));
    $show = file_get_contents(resource_path('views/sites/sections/design.blade.php')); // Design section (T-nav split)

    expect(substr_count($pageManager, '<livewire:page-layout-settings'))->toBe(1)
        ->and($pageManager)->toContain(":kind=\"'home'\"")
        ->and($pageManager)->not->toContain(":kind=\"'about'\"")
        ->and($pageManager)->not->toContain(":kind=\"'service'\"")
        ->and($pageManager)->not->toContain('livewire:home-layout-settings')
        ->and($pageManager)->not->toContain('livewire:services-layout-settings');

    expect(substr_count($show, '<livewire:page-layout-settings'))->toBe(2)
        ->and($show)->toContain(":kind=\"'about'\"")
        ->and($show)->toContain(":kind=\"'service'\"");
});
