<?php

use App\Models\Client;
use App\Models\GeneratedPage;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\SiteMedia;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Cross-tenant IDOR regression guards for the projects-page editors.
 *
 * The hole these close: itemId / siteId / pageId were plain public
 * properties, so a client could rewrite them with $wire.set() after a
 * legitimate mount. The site check then passed against the caller's OWN
 * siteId while the row itself was resolved globally
 * (ProjectItem::findOrFail($this->itemId), GeneratedPage::find($this->pageId)),
 * landing the read or write on another tenant's data. Reachable from the
 * CLIENT portal — page-manager renders these editors on
 * resources/views/client/portal/pages.blade.php.
 *
 * Two independent layers are asserted for every component:
 *   1. the identifier is #[Locked] — the client cannot change it at all;
 *   2. every action resolves its model through the AUTHORISED site, so a
 *      row that is not in the caller's site is unreachable even when the
 *      id does point at it (simulated by moving the row to another tenant
 *      after mount, which no #[Locked] attribute can prevent).
 */
uses(RefreshDatabase::class);

/**
 * Two tenants: the caller (client A) and the victim (client B). Category
 * vocab is identical on both sites so a cross-tenant save would pass
 * validation — the guard under test must be what stops it, not the
 * category rule.
 *
 * @return array<string, mixed>
 */
function idorTenants(): array
{
    $clientA = Client::factory()->create();
    $clientB = Client::factory()->create();

    $userA = User::factory()->create([
        'client_id' => $clientA->id,
        'role' => null,
        'last_login_at' => now(),
    ]);

    $siteA = Site::factory()->create(['client_id' => $clientA->id, 'project_categories' => ['Residential']]);
    $siteB = Site::factory()->create(['client_id' => $clientB->id, 'project_categories' => ['Residential']]);

    $pageA = GeneratedPage::factory()->for($siteA)->create(['page_type' => 'projects']);
    $pageB = GeneratedPage::factory()->for($siteB)->create(['page_type' => 'projects']);

    $itemA = ProjectItem::factory()->for($siteA)->create([
        'page_id' => $pageA->id,
        'category' => 'Residential',
        'title' => 'My own tile',
    ]);
    $itemB = ProjectItem::factory()->for($siteB)->create([
        'page_id' => $pageB->id,
        'category' => 'Residential',
        'title' => 'VICTIM TILE',
    ]);

    return compact('userA', 'siteA', 'siteB', 'pageA', 'pageB', 'itemA', 'itemB');
}

// ---------------------------------------------------------------------
// project-item-card
// ---------------------------------------------------------------------

dataset('project_item_card_locked_props', ['itemId', 'siteId']);

it('project-item-card refuses client updates to its identifier properties', function (string $prop) {
    ['userA' => $userA, 'itemA' => $itemA, 'itemB' => $itemB] = idorTenants();

    $card = Livewire::actingAs($userA)->test('project-item-card', ['itemId' => $itemA->id]);

    expect(fn () => $card->set($prop, $itemB->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with('project_item_card_locked_props');

/**
 * Actions that resolve the item through authorizedItemOrFail(). Each is
 * called after the row has left the caller's site: the scoped lookup must
 * miss and abort (404), never fall back to a global resolution.
 */
dataset('project_item_card_failclosed_actions', [
    'save' => ['save', []],
    'archive' => ['archive', []],
    'unarchive' => ['unarchive', []],
    'removeMetric' => ['removeMetric', [0]],
]);

it('project-item-card actions fail closed on an item outside the authorised site', function (string $action, array $args) {
    Bus::fake();
    ['userA' => $userA, 'siteB' => $siteB, 'itemA' => $itemA] = idorTenants();

    $card = Livewire::actingAs($userA)
        ->test('project-item-card', ['itemId' => $itemA->id])
        ->set('title', 'PWNED')
        ->set('description', 'PWNED');

    // The id stops resolving to the caller's site — the exact end state
    // an unlocked, tampered itemId used to produce.
    $itemA->update([
        'site_id' => $siteB->id,
        'title' => 'CANARY',
        'description' => 'CANARY DESCRIPTION',
        'status' => \App\Enums\ProjectItemStatus::Draft,
        'image_job_state' => null,
        'metrics' => [['icon' => 'timer', 'label' => 'canary metric']],
    ]);

    expect(fn () => $card->call($action, ...$args))->toThrow(ModelNotFoundException::class);

    $fresh = $itemA->fresh();
    expect($fresh->title)->toBe('CANARY')
        ->and($fresh->description)->toBe('CANARY DESCRIPTION')
        ->and($fresh->status)->toBe(\App\Enums\ProjectItemStatus::Draft)
        ->and($fresh->image_job_state)->toBeNull()
        ->and($fresh->metrics)->toBe([['icon' => 'timer', 'label' => 'canary metric']]);

    Bus::assertNothingDispatched();
})->with('project_item_card_failclosed_actions');

it('project-item-card activateVersion fails closed on an item outside the authorised site', function () {
    ['userA' => $userA, 'siteB' => $siteB, 'itemA' => $itemA] = idorTenants();

    $card = Livewire::actingAs($userA)->test('project-item-card', ['itemId' => $itemA->id]);

    $media = SiteMedia::factory()->create([
        'site_id' => $siteB->id,
        'project_item_id' => $itemA->id,
    ]);
    $itemA->update(['site_id' => $siteB->id]);

    expect(fn () => $card->call('activateVersion', $media->id))->toThrow(ModelNotFoundException::class);

    expect($itemA->fresh()->image_id)->toBeNull();
});

it('project-item-card uploadProjectImage fails closed on an item outside the authorised site', function () {
    Storage::fake('s3');
    ['userA' => $userA, 'siteB' => $siteB, 'itemA' => $itemA] = idorTenants();

    $card = Livewire::actingAs($userA)
        ->test('project-item-card', ['itemId' => $itemA->id])
        ->set('imageUpload', UploadedFile::fake()->createWithContent(
            'agent.png',
            file_get_contents(base_path('tests/fixtures/logos/large_1024x512.png')),
        ));

    $itemA->update(['site_id' => $siteB->id]);

    $card->call('uploadProjectImage');

    expect(SiteMedia::where('project_item_id', $itemA->id)->where('source', 'agent_upload')->count())->toBe(0)
        ->and($itemA->fresh()->image_id)->toBeNull();
});

it('project-item-card reloadFields does not disclose an item outside the authorised site', function () {
    ['userA' => $userA, 'siteB' => $siteB, 'itemA' => $itemA] = idorTenants();

    $card = Livewire::actingAs($userA)->test('project-item-card', ['itemId' => $itemA->id]);

    $itemA->update(['site_id' => $siteB->id, 'title' => 'LEAKED TITLE', 'description' => 'LEAKED BODY']);

    $card->call('reloadFields')
        ->assertSet('title', 'My own tile')
        ->assertDontSee('LEAKED TITLE')
        ->assertDontSee('LEAKED BODY');
});

it('project-item-card render does not disclose an item outside the authorised site', function () {
    ['userA' => $userA, 'siteB' => $siteB, 'itemA' => $itemA] = idorTenants();

    $card = Livewire::actingAs($userA)->test('project-item-card', ['itemId' => $itemA->id]);

    $itemA->update(['site_id' => $siteB->id, 'category' => 'LEAKED CATEGORY']);

    // The computed item() resolves through findAuthorizedSite(); missing
    // rows already render the "not found" branch, so no 500 here.
    $card->call('$refresh')
        ->assertDontSee('LEAKED CATEGORY')
        ->assertSee('not found');
});

// ---------------------------------------------------------------------
// projects-page-editor
// ---------------------------------------------------------------------

dataset('projects_page_editor_locked_props', ['siteId', 'pageId']);

it('projects-page-editor refuses client updates to its identifier properties', function (string $prop) {
    ['userA' => $userA, 'siteA' => $siteA, 'siteB' => $siteB, 'pageB' => $pageB] = idorTenants();

    $editor = Livewire::actingAs($userA)->test('projects-page-editor', ['siteId' => $siteA->id]);

    $foreign = $prop === 'siteId' ? $siteB->id : $pageB->id;

    expect(fn () => $editor->set($prop, $foreign))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with('projects_page_editor_locked_props');

it('projects-page-editor toggleHero does not write to a page outside the authorised site', function () {
    ['userA' => $userA, 'siteA' => $siteA, 'siteB' => $siteB, 'pageA' => $pageA] = idorTenants();

    $pageA->update(['content_data' => ['sections' => [['type' => 'projects_hero', 'title' => 'Ours']]]]);

    $editor = Livewire::actingAs($userA)->test('projects-page-editor', ['siteId' => $siteA->id]);

    $pageA->update(['site_id' => $siteB->id]);

    $editor->call('toggleHero', false)->assertSet('heroEnabled', true);

    expect($pageA->fresh()->draft_revision_id)->toBeNull();
});

// ---------------------------------------------------------------------
// projects-gallery-editor
// ---------------------------------------------------------------------

dataset('page_editor_locked_props', ['siteId', 'pageId']);

it('projects-gallery-editor refuses client updates to its identifier properties', function (string $prop) {
    ['userA' => $userA, 'siteA' => $siteA, 'siteB' => $siteB, 'pageA' => $pageA, 'pageB' => $pageB] = idorTenants();

    $editor = Livewire::actingAs($userA)
        ->test('projects-gallery-editor', ['siteId' => $siteA->id, 'pageId' => $pageA->id]);

    $foreign = $prop === 'siteId' ? $siteB->id : $pageB->id;

    expect(fn () => $editor->set($prop, $foreign))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with('page_editor_locked_props');

it('projects-gallery-editor reorder does not write to a page outside the authorised site', function () {
    ['userA' => $userA, 'siteA' => $siteA, 'siteB' => $siteB, 'pageA' => $pageA, 'itemA' => $itemA] = idorTenants();

    $pageA->update(['content_data' => ['sections' => [
        ['type' => 'project_gallery', 'item_ids' => [$itemA->id, 4242]],
    ]]]);

    $editor = Livewire::actingAs($userA)
        ->test('projects-gallery-editor', ['siteId' => $siteA->id, 'pageId' => $pageA->id]);

    $pageA->update(['site_id' => $siteB->id]);

    $editor->call('reorder', [4242, $itemA->id]);

    $fresh = $pageA->fresh();
    expect($fresh->draft_revision_id)->toBeNull()
        ->and($fresh->content_data['sections'][0]['item_ids'])->toBe([$itemA->id, 4242]);
});

it('projects-gallery-editor addTile does not plant a row on a page outside the authorised site', function () {
    Bus::fake();
    ['userA' => $userA, 'siteA' => $siteA, 'siteB' => $siteB, 'pageA' => $pageA] = idorTenants();

    $editor = Livewire::actingAs($userA)
        ->test('projects-gallery-editor', ['siteId' => $siteA->id, 'pageId' => $pageA->id])
        ->set('newTitle', 'PLANTED TILE')
        ->set('newDescription', 'planted description')
        ->set('newCategory', 'Residential')
        ->set('newImageMode', 'none');

    $pageA->update(['site_id' => $siteB->id]);

    $editor->call('addTile');

    expect(ProjectItem::where('title', 'PLANTED TILE')->count())->toBe(0);
    Bus::assertNothingDispatched();
});

// ---------------------------------------------------------------------
// case-study-editor
// ---------------------------------------------------------------------

it('case-study-editor refuses client updates to its identifier properties', function (string $prop) {
    ['userA' => $userA, 'siteA' => $siteA, 'siteB' => $siteB, 'pageA' => $pageA, 'pageB' => $pageB] = idorTenants();

    $editor = Livewire::actingAs($userA)
        ->test('case-study-editor', ['siteId' => $siteA->id, 'pageId' => $pageA->id]);

    $foreign = $prop === 'siteId' ? $siteB->id : $pageB->id;

    expect(fn () => $editor->set($prop, $foreign))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with('page_editor_locked_props');

it('case-study-editor reorder does not write to a page outside the authorised site', function () {
    ['userA' => $userA, 'siteA' => $siteA, 'siteB' => $siteB, 'pageA' => $pageA, 'itemA' => $itemA] = idorTenants();

    $pageA->update(['content_data' => ['sections' => [
        ['type' => 'case_study_highlights', 'item_ids' => [$itemA->id, 4242]],
    ]]]);

    $editor = Livewire::actingAs($userA)
        ->test('case-study-editor', ['siteId' => $siteA->id, 'pageId' => $pageA->id]);

    $pageA->update(['site_id' => $siteB->id]);

    $editor->call('reorder', [4242, $itemA->id]);

    $fresh = $pageA->fresh();
    expect($fresh->draft_revision_id)->toBeNull()
        ->and($fresh->content_data['sections'][0]['item_ids'])->toBe([$itemA->id, 4242]);
});

it('case-study-editor addCaseStudy does not plant a row on a page outside the authorised site', function () {
    Bus::fake();
    ['userA' => $userA, 'siteA' => $siteA, 'siteB' => $siteB, 'pageA' => $pageA] = idorTenants();

    $editor = Livewire::actingAs($userA)
        ->test('case-study-editor', ['siteId' => $siteA->id, 'pageId' => $pageA->id])
        ->set('newTitle', 'PLANTED CASE STUDY')
        ->set('newDescription', 'planted description')
        ->set('newCategory', 'Residential')
        ->set('newImageMode', 'none');

    $pageA->update(['site_id' => $siteB->id]);

    $editor->call('addCaseStudy');

    expect(ProjectItem::where('title', 'PLANTED CASE STUDY')->count())->toBe(0);
    Bus::assertNothingDispatched();
});

// ---------------------------------------------------------------------
// Client-portal reachability
// ---------------------------------------------------------------------

it('blocks a portal client from mounting another clients project item card', function () {
    ['userA' => $userA, 'itemB' => $itemB] = idorTenants();

    Livewire::actingAs($userA)
        ->test('project-item-card', ['itemId' => $itemB->id])
        ->assertStatus(403);
});

it('lets a portal client edit their own project item card', function () {
    ['userA' => $userA, 'itemA' => $itemA] = idorTenants();

    Livewire::actingAs($userA)
        ->test('project-item-card', ['itemId' => $itemA->id])
        ->set('title', 'Renamed by the owner')
        ->call('save')
        ->assertHasNoErrors();

    expect($itemA->fresh()->title)->toBe('Renamed by the owner');
});
