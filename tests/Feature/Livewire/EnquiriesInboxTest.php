<?php

use App\Enums\AgentRole;
use App\Models\Site;
use App\Models\SiteEnquiry;
use App\Models\User;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->staff(AgentRole::Agent)->create();
    $this->site = Site::factory()->create(['created_by_user_id' => $this->user->id]);
});

test('authorized agent sees enquiry details newest first', function () {
    SiteEnquiry::factory()->for($this->site)->create([
        'name' => 'Older Enquirer',
        'created_at' => now()->subDay(),
    ]);
    $newest = SiteEnquiry::factory()->for($this->site)->create([
        'name' => 'Newest Enquirer',
        'email' => 'newest@example.com',
        'page_type' => 'contact',
        'payload' => [
            'phone' => '07700 900123',
            'service' => 'Loft conversion',
            'message' => 'Please quote for a loft conversion.',
        ],
        'created_at' => now(),
    ]);

    Livewire::actingAs($this->user)
        ->test('enquiries-inbox', ['siteId' => $this->site->id])
        ->assertSeeInOrder(['Newest Enquirer', 'Older Enquirer'])
        ->assertSee('newest@example.com')
        ->assertSee('07700 900123')
        ->assertSee('Loft conversion')
        ->assertSee('Please quote for a loft conversion.')
        ->assertSee($newest->created_at->toDayDateTimeString());
});

test('enquiries are scoped to the site', function () {
    $otherSite = Site::factory()->create(['created_by_user_id' => $this->user->id]);
    SiteEnquiry::factory()->for($this->site)->create(['name' => 'Mine Molly']);
    SiteEnquiry::factory()->for($otherSite)->create(['name' => 'Foreign Fred']);

    Livewire::actingAs($this->user)
        ->test('enquiries-inbox', ['siteId' => $this->site->id])
        ->assertSee('Mine Molly')
        ->assertDontSee('Foreign Fred');
});

test('unauthorized agent sees no enquiry data', function () {
    SiteEnquiry::factory()->for($this->site)->create(['name' => 'Hidden Harry']);
    $outsider = User::factory()->staff(AgentRole::Agent)->create();

    Livewire::actingAs($outsider)
        ->test('enquiries-inbox', ['siteId' => $this->site->id])
        ->assertSee('not authorised')
        ->assertDontSee('Hidden Harry');
});

test('enquiries paginate at 25 per page', function () {
    foreach (range(1, 26) as $i) {
        SiteEnquiry::factory()->for($this->site)->create([
            'name' => sprintf('Enquirer %02d', $i),
            'created_at' => now()->subMinutes(27 - $i),
        ]);
    }

    Livewire::actingAs($this->user)
        ->test('enquiries-inbox', ['siteId' => $this->site->id])
        ->assertSee('Enquirer 26')
        ->assertSee('Enquirer 02')
        ->assertDontSee('Enquirer 01')
        ->call('nextPage', 'enquiriesPage')
        ->assertSee('Enquirer 01')
        ->assertDontSee('Enquirer 26');
});

test('with, findAuthorizedSite and assertAuthorizedSiteAccess are not callable actions', function () {
    foreach (['with', 'findAuthorizedSite', 'assertAuthorizedSiteAccess'] as $method) {
        expect(fn () => Livewire::actingAs($this->user)
            ->test('enquiries-inbox', ['siteId' => $this->site->id])
            ->call($method))
            ->toThrow(MethodNotFoundException::class);
    }
});

test('guest gets no enquiry data via direct component access', function () {
    SiteEnquiry::factory()->for($this->site)->create(['name' => 'Hidden Harry']);

    Livewire::test('enquiries-inbox', ['siteId' => $this->site->id])
        ->assertSee('not authorised')
        ->assertDontSee('Hidden Harry');
});
