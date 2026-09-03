<?php

use App\Enums\AgentRole;
use App\Enums\PageKind;
use App\Enums\PageStatus;
use App\Models\Client;
use App\Models\GeneratedPage;
use App\Models\LayoutPreset;
use App\Models\Site;
use App\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * @return list<array<string, mixed>>
 */
function pageLayoutOverrideLongLedgerSections(): array
{
    $items = array_fill(0, 8, ['title' => 't', 'body' => 'b']);

    return [
        ['type' => 'services', 'variant' => 'numbered-rows', 'items' => $items],
        ['type' => 'trust', 'variant' => 'numbered-rows', 'items' => $items],
    ];
}

/**
 * @param  array<string, mixed>  $section
 * @return list<array<string, mixed>>
 */
function pageLayoutOverrideEightItemSections(array $section): array
{
    $section['items'] = array_fill(0, 8, ['title' => 't', 'body' => 'b']);

    return [$section];
}

function pageLayoutOverrideNumberedRowsRecipe(): array
{
    return [
        'schema_version' => 1,
        'variants' => ['services' => 'numbered-rows'],
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => [],
    ];
}

it('writes a stock key to the page and invalidates the cache', function () {
    config(['site.public_cache_enabled' => true]);
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'roofing', 'kind' => PageKind::Service]);
    $before = (int) cache()->get("site:{$site->id}:pubcache_counter", 0);

    Livewire::actingAs($agent)->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id])
        ->call('setOverride', 'editorial');

    expect($page->fresh()->layout_preset_key)->toBe('editorial')
        ->and((int) cache()->get("site:{$site->id}:pubcache_counter", 0))->toBeGreaterThan($before);
});

it('clearing writes null', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'roofing', 'kind' => PageKind::Service, 'layout_preset_key' => 'editorial']);
    Livewire::actingAs($agent)->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id])->call('setOverride', null);
    expect($page->fresh()->layout_preset_key)->toBeNull();
});

it('rejects a key outside optionsFor and a foreign page id', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $other = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'roofing', 'kind' => PageKind::Service]);
    $foreign = GeneratedPage::factory()->for($other)->create(['page_type' => 'roofing', 'kind' => PageKind::Service]);

    Livewire::actingAs($agent)->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id])
        ->call('setOverride', 'bespoke-other')->assertHasErrors('layout');
    Livewire::actingAs($agent)->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $foreign->id])->assertStatus(404);
});

it('hides itself for a page kind with no recipes', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $guide = GeneratedPage::factory()->for($site)->create(['page_type' => 'guide-x', 'kind' => PageKind::Guide]);
    Livewire::actingAs($agent)->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $guide->id])
        ->assertSuccessful()
        ->assertSee('No layout presets for this page type');
});

it('hides itself for an archived page of the same site without 404ing', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'roofing',
        'kind' => PageKind::Service,
        'status' => PageStatus::Archived,
        'layout_preset_key' => 'editorial',
    ]);

    expect($page->fresh()->archived_at)->not->toBeNull();

    Livewire::actingAs($agent)->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id])
        ->assertSuccessful()
        ->assertSee('No layout presets for this page type')
        ->call('setOverride', 'editorial')
        ->assertHasErrors('layout');

    expect($page->fresh()->layout_preset_key)->toBe('editorial');
});

it('hides itself for a service page with an empty page_type', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => '',
        'kind' => PageKind::Service,
    ]);

    Livewire::actingAs($agent)->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id])
        ->assertSuccessful()
        ->assertSee('No layout presets for this page type')
        ->call('setOverride', 'editorial')
        ->assertHasErrors('layout');

    expect($page->fresh()->layout_preset_key)->toBeNull();
});

it('exposes guardrail warnings on mount and refreshes them after changing or clearing the override', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id, 'services_layout' => 'classic']);
    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'roofing',
        'kind' => PageKind::Service,
        'content_data' => ['sections' => pageLayoutOverrideLongLedgerSections()],
    ]);

    $component = Livewire::actingAs($agent)->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id])
        ->assertDontSee('single-column ledger');

    $component->call('setOverride', 'editorial')
        ->assertSee('single-column ledger')
        ->assertHasNoErrors();

    expect($page->fresh()->layout_preset_key)->toBe('editorial');

    $component->call('setOverride', null)
        ->assertDontSee('single-column ledger');

    expect($page->fresh()->layout_preset_key)->toBeNull();
});

it('does not warn about a long ledger when persisted variant is explicit null', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    LayoutPreset::factory()->for($site)->active()->create([
        'page_kind' => 'home',
        'key' => 'numbered-ledger',
        'label' => 'Numbered ledger',
        'recipe' => pageLayoutOverrideNumberedRowsRecipe(),
    ]);

    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'layout_preset_key' => 'numbered-ledger',
        'content_data' => ['sections' => pageLayoutOverrideEightItemSections([
            'type' => 'services',
            'variant' => null,
        ])],
    ]);

    $component = Livewire::actingAs($agent)->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id])
        ->assertDontSee('single-column ledger');

    $component->call('setOverride', 'numbered-ledger')
        ->assertDontSee('single-column ledger')
        ->assertHasNoErrors();
});

it('warns about a long ledger when variant is absent and the recipe stamps numbered-rows', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    LayoutPreset::factory()->for($site)->active()->create([
        'page_kind' => 'home',
        'key' => 'numbered-ledger',
        'label' => 'Numbered ledger',
        'recipe' => pageLayoutOverrideNumberedRowsRecipe(),
    ]);

    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'layout_preset_key' => 'numbered-ledger',
        'content_data' => ['sections' => pageLayoutOverrideEightItemSections([
            'type' => 'services',
        ])],
    ]);

    Livewire::actingAs($agent)->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id])
        ->assertSee('single-column ledger')
        ->call('setOverride', 'numbered-ledger')
        ->assertSee('single-column ledger')
        ->assertHasNoErrors();
});

it('shows recipeWarnings on mount for a home page without blocking the save', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

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

    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'layout_preset_key' => 'double-ledger',
        'content_data' => ['sections' => pageLayoutOverrideLongLedgerSections()],
    ]);

    Livewire::actingAs($agent)->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id])
        ->assertSee('two ledgers on one home')
        ->call('setOverride', 'showcase')
        ->assertHasNoErrors()
        ->assertDontSee('two ledgers on one home');

    expect($page->fresh()->layout_preset_key)->toBe('showcase');
});

it('renders each option label and description including inherit', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'roofing', 'kind' => PageKind::Service]);

    LayoutPreset::factory()->for($site)->active()->create([
        'page_kind' => 'service',
        'key' => 'bespoke-mine',
        'label' => 'Bespoke Distinctive Label',
        'description' => 'UNIQUE_DESC_TOKEN_xyz',
        'recipe' => [
            'schema_version' => 1,
            'variants' => ['intro' => 'editorial', 'features' => 'numbered'],
            'eyebrow_policy' => 'first-only',
            'eyebrow_sections' => ['intro', 'features'],
        ],
    ]);

    Livewire::actingAs($agent)->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id])
        ->assertSee('Inherit site default')
        ->assertSee('Editorial')
        ->assertSee('Magazine longform')
        ->assertSee('Bespoke Distinctive Label')
        ->assertSee('UNIQUE_DESC_TOKEN_xyz')
        ->assertSee('Overrides the site-wide service layout for this page only')
        ->assertSee('Applies immediately to the live site');
});

it('reverts the picker to the persisted value when the write is rejected', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'roofing',
        'kind' => PageKind::Service,
        'layout_preset_key' => 'editorial',
    ]);

    $html = Livewire::actingAs($agent)->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id])
        ->call('setOverride', 'bespoke-other')
        ->assertHasErrors('layout')
        ->assertSet('current', 'editorial')
        ->html();

    expect($page->fresh()->layout_preset_key)->toBe('editorial')
        ->and($html)->toContain('value="editorial"')
        ->and($html)->toMatch('/value="editorial"[^>]*checked|checked[^>]*value="editorial"/');
});

it('rejects another sites active bespoke key', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $other = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'roofing', 'kind' => PageKind::Service]);

    LayoutPreset::factory()->for($other)->active()->create([
        'page_kind' => 'service',
        'key' => 'bespoke-other',
    ]);

    Livewire::actingAs($agent)->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id])
        ->call('setOverride', 'bespoke-other')
        ->assertHasErrors('layout');

    expect($page->fresh()->layout_preset_key)->toBeNull();
});

it('a user without access to the site cannot mount or change its override', function () {
    $owner = User::factory()->staff(AgentRole::Agent)->create();
    $outsider = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $owner->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'roofing', 'kind' => PageKind::Service]);

    Livewire::actingAs($outsider)
        ->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id])
        ->assertForbidden();

    expect($page->fresh()->layout_preset_key)->toBeNull();
});

it('a client user of another site cannot mount the picker', function () {
    $owner = User::factory()->staff(AgentRole::Agent)->create();
    $ownerClient = Client::factory()->create();
    $otherClient = Client::factory()->create();
    $outsider = User::factory()->create([
        'client_id' => $otherClient->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create([
        'created_by_user_id' => $owner->id,
        'client_id' => $ownerClient->id,
    ]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'roofing', 'kind' => PageKind::Service]);

    Livewire::actingAs($outsider)
        ->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id])
        ->assertForbidden();
});

it('rejects a foreign site id on mount', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $other = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'roofing', 'kind' => PageKind::Service]);

    Livewire::actingAs($agent)
        ->test('page-layout-override', ['siteId' => $other->id, 'pageId' => $page->id])
        ->assertForbidden();
});

it('locks ids and server-derived display state against client writes', function (string $prop, mixed $value) {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $other = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'roofing', 'kind' => PageKind::Service]);
    $foreign = GeneratedPage::factory()->for($other)->create(['page_type' => 'roofing', 'kind' => PageKind::Service]);

    $component = Livewire::actingAs($agent)
        ->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id]);

    $payload = match ($prop) {
        'pageId' => $foreign->id,
        'siteId' => $other->id,
        default => $value,
    };

    expect(fn () => $component->set($prop, $payload))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    'pageId' => ['pageId', 0],
    'siteId' => ['siteId', 0],
    'kind' => ['kind', 'about'],
    'options' => ['options', []],
    'warnings' => ['warnings', ['pwned']],
    'detailFollowMismatch' => ['detailFollowMismatch', true],
]);

it('writes a stock key for home and about pages', function (string $pageType, PageKind $kind, string $key) {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => $pageType, 'kind' => $kind]);

    Livewire::actingAs($agent)->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id])
        ->call('setOverride', $key);

    expect($page->fresh()->layout_preset_key)->toBe($key);
})->with([
    'home editorial' => ['home', PageKind::Core, 'editorial'],
    'about precision' => ['about', PageKind::Core, 'precision'],
]);

it('page manager mounts the per-page picker without wrapping chrome', function () {
    $blade = file_get_contents(resource_path('views/livewire/page-manager.blade.php'));

    expect($blade)->toContain('<livewire:page-layout-override')
        ->and($blade)->toContain(":page-id=\"\$tab['page_id']\"");
});

it('lists stock projects personalities plus Tier-1 rows for the projects page', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects', 'kind' => PageKind::Core]);
    LayoutPreset::factory()->active()->create([
        'site_id' => $site->id,
        'page_kind' => 'projects',
        'key' => 'bespoke-proj',
        'label' => 'Bespoke projects',
        'recipe' => [
            'schema_version' => 1,
            'variants' => ['project_gallery' => 'classic'],
            'eyebrow_policy' => 'all',
            'eyebrow_sections' => [],
        ],
    ]);

    $component = Livewire::actingAs($agent)->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id]);

    expect($component->get('kind'))->toBe('projects')
        ->and(array_keys($component->get('options')))
        ->toContain('classic', 'editorial', 'showcase', 'precision', 'banded', 'bespoke-proj');
});

it('writes the projects page override and shows the detail-follows footer', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects', 'kind' => PageKind::Core]);

    Livewire::actingAs($agent)->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id])
        ->assertSee('Project detail pages follow this choice.')
        ->call('setOverride', 'precision');

    expect($page->fresh()->layout_preset_key)->toBe('precision');
});

it('warns after selecting a projects-only Tier-1 layout without remounting', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects', 'kind' => PageKind::Core]);
    LayoutPreset::factory()->active()->create([
        'site_id' => $site->id,
        'page_kind' => 'projects',
        'key' => 'bespoke-proj',
        'label' => 'Bespoke projects',
        'recipe' => [
            'schema_version' => 1,
            'variants' => ['project_gallery' => 'classic'],
            'eyebrow_policy' => 'all',
            'eyebrow_sections' => [],
        ],
    ]);

    Livewire::actingAs($agent)->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id])
        ->assertDontSee('This layout has no project detail recipe')
        ->call('setOverride', 'bespoke-proj')
        ->assertSee('This layout has no project detail recipe — detail pages will fall back to the classic layout.');
});

it('does not warn for projects layouts available to detail pages', function (string $key) {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects', 'kind' => PageKind::Core]);

    Livewire::actingAs($agent)->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id])
        ->call('setOverride', $key)
        ->assertDontSee('This layout has no project detail recipe')
        ->assertSee('Project detail pages follow this choice.');
})->with([
    'precision' => 'precision',
    'classic' => 'classic',
]);

it('does not warn when a projects page inherits the site default', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects', 'kind' => PageKind::Core]);

    Livewire::actingAs($agent)->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id])
        ->assertSet('current', null)
        ->assertDontSee('This layout has no project detail recipe');
});

it('does not show the project detail warning for a service page', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'roofing', 'kind' => PageKind::Service]);

    Livewire::actingAs($agent)->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id])
        ->call('setOverride', 'precision')
        ->assertDontSee('This layout has no project detail recipe');
});

it('clears the projects page override to null', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'projects',
        'kind' => PageKind::Core,
        'layout_preset_key' => 'precision',
    ]);

    Livewire::actingAs($agent)->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id])
        ->call('setOverride', null);

    expect($page->fresh()->layout_preset_key)->toBeNull();
});

it('invalidates the public cache when setting a projects page override', function () {
    config(['site.public_cache_enabled' => true]);
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects', 'kind' => PageKind::Core]);
    $before = (int) cache()->get("site:{$site->id}:pubcache_counter", 0);

    Livewire::actingAs($agent)->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id])
        ->call('setOverride', 'precision');

    expect((int) cache()->get("site:{$site->id}:pubcache_counter", 0))->toBeGreaterThan($before);
});

it('hides layout options for an archived projects page without 404ing', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'projects',
        'kind' => PageKind::Core,
        'status' => PageStatus::Archived,
    ]);

    Livewire::actingAs($agent)->test('page-layout-override', ['siteId' => $site->id, 'pageId' => $page->id])
        ->assertSuccessful()
        ->assertSet('kind', null)
        ->assertSet('options', [])
        ->assertSee('No layout presets for this page type');
});
