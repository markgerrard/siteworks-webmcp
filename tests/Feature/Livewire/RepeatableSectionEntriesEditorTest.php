<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\SiteMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->staff = User::factory()->staff(AgentRole::Admin)->create();
    $this->site = Site::factory()->create();
    $this->page = teamPage($this->site, [
        ['name' => 'Alice', 'role' => 'Founder', 'bio' => 'First'],
        ['name' => 'Bob', 'role' => 'Designer', 'bio' => 'Second'],
        ['name' => 'Cara', 'role' => 'Builder', 'bio' => 'Third'],
    ]);
});

function teamPage(Site $site, array $members): GeneratedPage
{
    $content = ['sections' => [[
        'type' => 'team',
        'title' => 'Our team',
        'members' => $members,
    ]]];
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'about',
        'content_data' => $content,
        'sort_order' => 0,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $revision = PageRevision::create([
        'page_id' => $page->id,
        'content_data' => $content,
        'ai_generated' => false,
        'created_at' => now(),
    ]);
    $page->update(['published_revision_id' => $revision->id]);

    return $page;
}

it('shows generic member add remove reorder and portrait controls on the shared editor surface', function () {
    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $this->site->id])
        ->call('edit', 'about', 'team', 0)
        ->assertSet('editEntryList', 'members')
        ->assertSet('editEntries.0.name', 'Alice')
        ->assertSee('Team members')
        ->assertSee('Add member')
        ->assertSee('Main portrait')
        ->assertSee('Alternate portrait')
        ->assertSee('Hover portrait');
});

it('persists add remove and reorder actions from the shared Livewire editor', function () {
    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $this->site->id])
        ->call('edit', 'about', 'team', 0)
        ->call('reorderEntries', 2, 0)
        ->call('removeEntry', 2)
        ->call('addEntry')
        ->set('editEntries.2.name', 'Drew')
        ->set('editEntries.2.role', 'Apprentice')
        ->call('saveSection')
        ->assertHasNoErrors();

    $members = PageRevision::find($this->page->fresh()->draft_revision_id)
        ->content_data['sections'][0]['members'];

    expect(array_column($members, 'name'))->toBe(['Cara', 'Alice', 'Drew'])
        ->and(array_keys($members))->toBe([0, 1, 2]);
});

it('rejects a foreign media id through the scalar inline editor path', function () {
    $foreignMedia = SiteMedia::factory()->for(Site::factory()->create())->create();

    $this->actingAs($this->staff)
        ->postJson(route('site.admin.field-update', [$this->site, $this->page]), [
            'section_index' => 0,
            'field_path' => 'members.0.image_id',
            'value' => $foreignMedia->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('value');

    expect($this->page->fresh()->draft_revision_id)->toBeNull();
});
