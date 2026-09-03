<?php

use App\Enums\AgentRole;
use App\Enums\PageKind;
use App\Enums\PageStatus;
use App\Models\Client;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\User;
use App\Services\Site\PageService;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * @param  list<array<string, mixed>|null>  $sections
 * @return array{0: User, 1: Site, 2: GeneratedPage}
 */
function stylePickerSite(array $sections = [], array $siteAttributes = []): array
{
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(array_merge(
        ['created_by_user_id' => $agent->id],
        $siteAttributes,
    ));
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'kind' => PageKind::Core]);
    $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => ['sections' => $sections !== [] ? $sections : [
        ['type' => 'hero', 'title' => 'H'],
        ['type' => 'services', 'title' => 'S', 'items' => [['title' => 'a', 'body' => 'b']]],
    ]]]);
    // PageService::currentEditableContent is draft → published → []; the factory
    // does not point the page at the seeded revision, so the picker would
    // otherwise load empty sections.
    $page->update(['published_revision_id' => $revision->id]);

    return [$agent, $site, $page];
}

it('writes the variant into a DRAFT revision and labels it as draft', function () {
    [$agent, $site, $page] = stylePickerSite();
    Livewire::actingAs($agent)->test('section-style-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'sectionIndex' => 1])
        ->assertSee('saved to draft')
        ->call('setVariant', 'featured-ledger');
    $draft = $page->fresh()->draftRevision;
    expect($draft->content_data['sections'][1]['variant'])->toBe('featured-ledger');
});

it('clearing writes an explicit null (inherit)', function () {
    [$agent, $site, $page] = stylePickerSite();
    Livewire::actingAs($agent)->test('section-style-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'sectionIndex' => 1])
        ->call('setVariant', null);
    expect(array_key_exists('variant', $page->fresh()->draftRevision->content_data['sections'][1]))->toBeTrue();
});

it('rejects an unregistered variant and a foreign page', function () {
    [$agent, $site, $page] = stylePickerSite();
    $foreign = GeneratedPage::factory()->for(Site::factory()->create())->create(['page_type' => 'home', 'kind' => PageKind::Core]);
    Livewire::actingAs($agent)->test('section-style-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'sectionIndex' => 1])
        ->call('setVariant', 'nope')->assertHasErrors('variant');
    Livewire::actingAs($agent)->test('section-style-picker', ['siteId' => $site->id, 'pageId' => $foreign->id, 'sectionIndex' => 1])->assertStatus(404);
});

it('renders neither the draft caption nor recipe options on a variant-less family', function () {
    [$agent, $site, $page] = stylePickerSite([
        ['type' => 'hero', 'title' => 'H'],
        ['type' => 'services', 'title' => 'S', 'items' => [['title' => 'a', 'body' => 'b']]],
        ['type' => 'faqs', 'title' => 'F', 'items' => [['question' => 'q', 'answer' => 'a']]],
    ], ['home_layout' => 'editorial']);

    Livewire::actingAs($agent)
        ->test('section-style-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'sectionIndex' => 2])
        ->assertDontSee('saved to draft')
        ->assertDontSee('featured_count');
});

it('renders neither the draft caption nor recipe options on a lead_form section', function () {
    [$agent, $site, $page] = stylePickerSite([
        ['type' => 'hero', 'title' => 'H'],
        ['type' => 'services', 'title' => 'S', 'items' => [['title' => 'a', 'body' => 'b']]],
        ['type' => 'lead_form', 'title' => 'L'],
    ], ['home_layout' => 'editorial']);

    Livewire::actingAs($agent)
        ->test('section-style-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'sectionIndex' => 2])
        ->assertDontSee('saved to draft')
        ->assertDontSee('featured_count');
});

it('shows editorial services featured_count and omits options for families the recipe does not name', function () {
    [$agent, $site, $page] = stylePickerSite([
        ['type' => 'hero', 'title' => 'H'],
        ['type' => 'services', 'title' => 'S', 'items' => [['title' => 'a', 'body' => 'b']]],
        ['type' => 'cta_band', 'title' => 'C'],
    ], ['home_layout' => 'editorial']);

    $services = Livewire::actingAs($agent)
        ->test('section-style-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'sectionIndex' => 1])
        ->html();
    expect($services)->toContain('featured_count')->toContain(': 4');

    Livewire::actingAs($agent)
        ->test('section-style-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'sectionIndex' => 2])
        ->assertDontSee('featured_count')
        ->assertDontSee('saved to draft');
});

it('displays exactly the recipe option keys as effective options', function () {
    [$agent, $site, $page] = stylePickerSite([
        ['type' => 'hero', 'title' => 'H'],
        ['type' => 'services', 'title' => 'S', 'items' => [['title' => 'a', 'body' => 'b']]],
    ], ['home_layout' => 'editorial']);

    $recipe = app(\App\Services\Site\PageLayoutRegistry::class)
        ->resolveForPage($site, $page->fresh(), 'home');
    $optionKeys = array_keys(is_array($recipe['options'] ?? null) ? $recipe['options'] : []);

    $html = Livewire::actingAs($agent)
        ->test('section-style-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'sectionIndex' => 1])
        ->html();

    preg_match_all('/<dt class="inline font-medium">([^<]*)<\/dt>/', $html, $matches);
    expect($matches[1])->toBe($optionKeys)
        ->and($html)->not->toContain('__surface');
});

it('rejects setVariant when the draft pointer moved and does not create a typeless section', function () {
    [$agent, $site, $page] = stylePickerSite();
    $component = Livewire::actingAs($agent)
        ->test('section-style-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'sectionIndex' => 1]);

    app(PageService::class)->editField(
        $page->fresh(),
        'sections.0.title',
        'Changed while picker was open',
        userId: $agent->id,
    );

    $component->call('setVariant', 'featured-ledger')->assertHasErrors('variant');

    $content = $page->fresh()->draftRevision->content_data;
    expect($content['sections'][1]['variant'] ?? null)->toBeNull()
        ->and($content['sections'][1]['type'] ?? null)->toBe('services');
});

it('rehydrates variant options after the section at that index is replaced', function () {
    [$agent, $site, $page] = stylePickerSite();
    $component = Livewire::actingAs($agent)
        ->test('section-style-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'sectionIndex' => 1])
        ->assertSee('featured-ledger');

    $page->publishedRevision->update(['content_data' => ['sections' => [
        ['type' => 'hero', 'title' => 'H'],
        ['type' => 'faqs', 'title' => 'F', 'items' => [['question' => 'q', 'answer' => 'a']]],
    ]]]);

    $component->call('$refresh')
        ->assertDontSee('featured-ledger')
        ->assertDontSee('saved to draft');
});

it('lets a matching client user set a variant', function () {
    $client = Client::factory()->create();
    $user = User::factory()->create(['client_id' => $client->id, 'role' => null]);
    $site = Site::factory()->create(['client_id' => $client->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'kind' => PageKind::Core]);
    $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => ['sections' => [
        ['type' => 'hero', 'title' => 'H'],
        ['type' => 'services', 'title' => 'S', 'items' => [['title' => 'a', 'body' => 'b']]],
    ]]]);
    $page->update(['published_revision_id' => $revision->id]);

    Livewire::actingAs($user)
        ->test('section-style-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'sectionIndex' => 1])
        ->call('setVariant', 'featured-ledger');

    expect($page->fresh()->draftRevision->content_data['sections'][1]['variant'])->toBe('featured-ledger');
});

it('forbids a client user of another site from setting a variant', function () {
    $client = Client::factory()->create();
    $site = Site::factory()->create(['client_id' => $client->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'kind' => PageKind::Core]);
    $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => ['sections' => [
        ['type' => 'hero', 'title' => 'H'],
        ['type' => 'services', 'title' => 'S', 'items' => [['title' => 'a', 'body' => 'b']]],
    ]]]);
    $page->update(['published_revision_id' => $revision->id]);

    $outsider = User::factory()->create([
        'client_id' => Client::factory()->create()->id,
        'role' => null,
    ]);

    Livewire::actingAs($outsider)
        ->test('section-style-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'sectionIndex' => 1])
        ->assertForbidden();
});

it('does not mount the section style picker for an archived page', function () {
    [$agent, $site] = stylePickerSite();
    $archived = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'old-plumbing',
        'kind' => PageKind::Service,
        'status' => PageStatus::Archived,
        'archived_at' => now(),
    ]);
    $revision = PageRevision::factory()->for($archived, 'page')->create(['content_data' => ['sections' => [
        ['type' => 'hero', 'title' => 'Old'],
        ['type' => 'services', 'title' => 'S', 'items' => [['title' => 'a', 'body' => 'b']]],
    ]]]);
    $archived->update(['published_revision_id' => $revision->id]);

    $html = Livewire::actingAs($agent)
        ->test('page-manager', ['siteId' => $site->id])
        ->html();
    expect($html)->not->toContain('section-style-'.$archived->id);

    $afterEdit = Livewire::actingAs($agent)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'old-plumbing', 'services', 1)
        ->assertSuccessful()
        ->html();
    expect($afterEdit)->not->toContain('section-style-'.$archived->id);
});

it('mounts at most one section style picker for a repeated section type', function () {
    [$agent, $site, $page] = stylePickerSite([
        ['type' => 'hero', 'title' => 'H'],
        ['type' => 'services', 'title' => 'First', 'items' => [['title' => 'a', 'body' => 'b']]],
        ['type' => 'services', 'title' => 'Second', 'items' => [['title' => 'c', 'body' => 'd']]],
    ]);

    $closed = Livewire::actingAs($agent)
        ->test('page-manager', ['siteId' => $site->id])
        ->html();
    expect(substr_count($closed, 'section-style-'.$page->id))->toBe(0);

    $open = Livewire::actingAs($agent)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'services', 2)
        ->html();

    expect(substr_count($open, 'data-livewire-component="section-style-picker"'))->toBe(1)
        ->and($open)->toContain(
            'data-livewire-component="section-style-picker" data-page-id="'.$page->id.'" data-section-index="2"'
        );
});

it('passes the stored section index when a non-object hole sits in the list', function () {
    [$agent, $site, $page] = stylePickerSite([
        ['type' => 'hero', 'title' => 'H'],
        null,
        ['type' => 'services', 'title' => 'S', 'items' => [['title' => 'a', 'body' => 'b']]],
    ]);

    $html = Livewire::actingAs($agent)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'services', 2)
        ->html();

    expect($html)->toContain(
        'data-livewire-component="section-style-picker" data-page-id="'.$page->id.'" data-section-index="2"'
    );
});

it('renders a no-presets empty state when the page has no revision row', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'H'],
            ['type' => 'services', 'title' => 'S', 'items' => [['title' => 'a', 'body' => 'b']]],
        ]],
        'draft_revision_id' => null,
        'published_revision_id' => null,
    ]);

    Livewire::actingAs($agent)
        ->test('section-style-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'sectionIndex' => 1])
        ->assertSee('No presets')
        ->assertSee('no editable revision yet')
        ->assertDontSee('Inherit (site preset)');
});
