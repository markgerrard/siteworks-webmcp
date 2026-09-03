<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->staff = User::factory()->staff(AgentRole::Admin)->create());

function seedSiteWithContactPage(): Site
{
    $site = Site::factory()->create(['business_name' => 'Acme Plumbers']);
    BusinessProfile::create([
        'site_id' => $site->id,
        'profile_data' => ['contact' => ['phones' => ['01234 567890'], 'emails' => ['old@example.com'], 'address' => 'Old Rd']],
    ]);

    $content = ['sections' => [
        ['type' => 'details', 'items' => [
            ['label' => 'Phone', 'value' => '01234 567890'],
            ['label' => 'Email', 'value' => 'old@example.com'],
            ['label' => 'Address', 'value' => 'Old Rd'],
        ]],
    ]];
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'contact',
        'content_data' => $content,
        'sort_order' => 5,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $rev = PageRevision::create(['page_id' => $page->id, 'content_data' => $content, 'ai_generated' => false, 'created_at' => now()]);
    $page->update(['published_revision_id' => $rev->id]);
    Preview::factory()->create(['site_id' => $site->id]);

    return $site;
}

test('contact-editor save() creates a draft PageRevision on the contact page', function () {
    $site = seedSiteWithContactPage();
    $contact = $site->generatedPages()->where('page_type', 'contact')->first();
    expect($contact->draft_revision_id)->toBeNull();

    Livewire::actingAs($this->staff)
        ->test('contact-editor', ['siteId' => $site->id])
        ->set('phone', '0800 555-NEW')
        ->set('email', 'new@example.com')
        ->set('address', 'New Rd')
        ->call('save')
        ->assertDispatched('composition-dirty');

    $contact->refresh();
    expect($contact->draft_revision_id)->not->toBeNull();
    $draftRev = PageRevision::find($contact->draft_revision_id);
    $details = collect($draftRev->content_data['sections'])->firstWhere('type', 'details');
    $byLabel = collect($details['items'])->keyBy('label');
    expect($byLabel['Phone']['value'])->toBe('0800 555-NEW');
    expect($byLabel['Email']['value'])->toBe('new@example.com');
    expect($byLabel['Address']['value'])->toBe('New Rd');
});

test('contact-editor save() bumps admin_revision so banner surfaces', function () {
    $site = seedSiteWithContactPage();
    app(\App\Services\Site\CompositionService::class)->getOrCreateDraft($site);
    $before = (int) SiteDraft::where('site_id', $site->id)->value('admin_revision');

    Livewire::actingAs($this->staff)
        ->test('contact-editor', ['siteId' => $site->id])
        ->set('phone', '9999')
        ->call('save');

    $after = (int) SiteDraft::where('site_id', $site->id)->value('admin_revision');
    expect($after)->toBeGreaterThan($before);
});

test('contact-editor save() also updates sites.business_name', function () {
    $site = seedSiteWithContactPage();

    Livewire::actingAs($this->staff)
        ->test('contact-editor', ['siteId' => $site->id])
        ->set('companyName', 'Brand-New Co')
        ->call('save');

    expect($site->fresh()->business_name)->toBe('Brand-New Co');
});

test('contact-editor save() persists mobile to profile_data.contact.mobile', function () {
    $site = seedSiteWithContactPage();

    Livewire::actingAs($this->staff)
        ->test('contact-editor', ['siteId' => $site->id])
        ->set('mobile', '07700 900000')
        ->call('save');

    $profile = $site->fresh()->businessProfile->profile_data;
    expect($profile['contact']['mobile'])->toBe('07700 900000');

    // Draft revision should contain a Mobile items entry.
    $contact = $site->generatedPages()->where('page_type', 'contact')->first();
    $draftRev = PageRevision::find($contact->fresh()->draft_revision_id);
    $details = collect($draftRev->content_data['sections'])->firstWhere('type', 'details');
    $mobileItem = collect($details['items'])->firstWhere('label', 'Mobile');
    expect($mobileItem['value'])->toBe('07700 900000');
});

test('contact-editor save() clears mobile when set to empty', function () {
    $site = seedSiteWithContactPage();
    // Pre-seed a mobile value in profile_data.
    $bp = $site->businessProfile;
    $pd = $bp->profile_data;
    $pd['contact']['mobile'] = '07700 900000';
    $bp->update(['profile_data' => $pd]);

    Livewire::actingAs($this->staff)
        ->test('contact-editor', ['siteId' => $site->id])
        ->set('mobile', '')
        ->call('save');

    $profile = $site->fresh()->businessProfile->profile_data;
    expect($profile['contact']['mobile'])->toBeNull();
});

test('contact-editor mounts with blank mobile for sites that predate the field', function () {
    $site = seedSiteWithContactPage();
    // profile_data has no 'mobile' key — simulates existing sites.
    $bp = $site->businessProfile;
    $pd = $bp->profile_data;
    unset($pd['contact']['mobile']);
    $bp->update(['profile_data' => $pd]);

    Livewire::actingAs($this->staff)
        ->test('contact-editor', ['siteId' => $site->id])
        ->assertSet('mobile', '');
});

test('contact-editor save() supports legacy map-shape content_data', function () {
    $site = Site::factory()->create();
    BusinessProfile::create(['site_id' => $site->id, 'profile_data' => []]);

    $legacyContent = ['details' => ['phone' => '01234', 'email' => 'old', 'address' => 'old']];
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'contact',
        'content_data' => $legacyContent,
        'sort_order' => 5,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    PageRevision::create(['page_id' => $page->id, 'content_data' => $legacyContent, 'ai_generated' => false, 'created_at' => now()])
        ->id;
    $page->update(['published_revision_id' => PageRevision::where('page_id', $page->id)->first()->id]);
    Preview::factory()->create(['site_id' => $site->id]);

    Livewire::actingAs($this->staff)
        ->test('contact-editor', ['siteId' => $site->id])
        ->set('phone', '0800-NEW')
        ->call('save');

    $page->refresh();
    expect($page->draft_revision_id)->not->toBeNull();
    $rev = PageRevision::find($page->draft_revision_id);
    // PageService::replaceContent auto-translates legacy map shape to
    // sections-list shape; the details section ends up as
    // { type: 'details', items: [{ label: 'Phone', value: '0800-NEW' }, ...] }.
    $details = collect($rev->content_data['sections'] ?? [])->firstWhere('type', 'details');
    $phoneItem = collect($details['items'] ?? [])->firstWhere('label', 'Phone');
    expect($phoneItem['value'])->toBe('0800-NEW');
});

it('saves opening hours into profile_data, the preview snapshot, and round-trips them', function () {
    $site = seedSiteWithContactPage();

    Livewire::actingAs($this->staff)
        ->test('contact-editor', ['siteId' => $site->id])
        ->set('openingHoursText', "Mon–Fri: 8–5\nSat: 9–1")
        ->call('save');

    expect($site->businessProfile->fresh()->profile_data['opening_hours'])->toBe(['Mon–Fri' => '8–5', 'Sat' => '9–1']);
    // Preview::$casts['snapshot'] => array; PreviewSnapshotWriter::mutate() writes it back
    expect($site->fresh()->latestPreview->snapshot['profile']['opening_hours'] ?? null)->toBe(['Mon–Fri' => '8–5', 'Sat' => '9–1']);
    Livewire::actingAs($this->staff)->test('contact-editor', ['siteId' => $site->id])->assertSet('openingHoursText', "Mon–Fri: 8–5\nSat: 9–1");

    Livewire::actingAs($this->staff)->test('contact-editor', ['siteId' => $site->id])->set('openingHoursText', '')->call('save');
    expect($site->businessProfile->fresh()->profile_data['opening_hours'])->toBe([]);
});

test('contact-editor save() persists site_type and region on the site', function () {
    $site = seedSiteWithContactPage();
    $originalType = $site->business_type;
    $originalLocation = $site->location;

    Livewire::actingAs($this->staff)
        ->test('contact-editor', ['siteId' => $site->id])
        ->assertSee('Type')
        ->assertSee('Region')
        ->set('siteType', 'plumber')
        ->set('region', 'yorkshire')
        ->call('save');

    $fresh = $site->fresh();
    expect($fresh->site_type)->toBe('plumber')
        ->and($fresh->region)->toBe('yorkshire')
        ->and($fresh->business_type)->toBe($originalType)
        ->and($fresh->location)->toBe($originalLocation);
});
