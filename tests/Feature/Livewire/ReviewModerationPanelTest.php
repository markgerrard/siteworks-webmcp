<?php

use App\Enums\AgentRole;
use App\Enums\SiteReviewStatus;
use App\Models\Client;
use App\Models\Site;
use App\Models\SiteReview;
use App\Models\User;
use App\Services\Site\PublicPageCache;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->staff(AgentRole::Agent)->create();
    $this->site = Site::factory()->create(['created_by_user_id' => $this->user->id]);
});

test('authorized agent sees pending reviews with author, rating, text and date', function () {
    $review = SiteReview::factory()->for($this->site)->create([
        'author_name' => 'Janet Frame',
        'rating' => 4,
        'text' => 'Cracking job on the extension.',
    ]);

    Livewire::actingAs($this->user)
        ->test('review-moderation-panel', ['siteId' => $this->site->id])
        ->assertSee('Janet Frame')
        ->assertSee('Cracking job on the extension.')
        ->assertSee($review->created_at->toDayDateTimeString());
});

test('default pending filter hides approved reviews; approved filter shows them', function () {
    SiteReview::factory()->for($this->site)->create(['author_name' => 'Pending Pat']);
    SiteReview::factory()->for($this->site)->approved()->create(['author_name' => 'Approved Alma']);

    Livewire::actingAs($this->user)
        ->test('review-moderation-panel', ['siteId' => $this->site->id])
        ->assertSee('Pending Pat')
        ->assertDontSee('Approved Alma')
        ->set('statusFilter', 'approved')
        ->assertSee('Approved Alma')
        ->assertDontSee('Pending Pat');
});

test('unauthorized agent sees no review data', function () {
    SiteReview::factory()->for($this->site)->create(['author_name' => 'Secret Susan']);
    $outsider = User::factory()->staff(AgentRole::Agent)->create();

    Livewire::actingAs($outsider)
        ->test('review-moderation-panel', ['siteId' => $this->site->id])
        ->assertSee('not authorised')
        ->assertDontSee('Secret Susan');
});

test('approve transitions the review to approved and invalidates the public page cache', function () {
    $review = SiteReview::factory()->for($this->site)->create();

    $this->mock(PublicPageCache::class)
        ->shouldReceive('invalidate')
        ->once()
        ->withArgs(fn (Site $site) => $site->id === $this->site->id);

    Livewire::actingAs($this->user)
        ->test('review-moderation-panel', ['siteId' => $this->site->id])
        ->call('approve', $review->id);

    expect($review->refresh()->status)->toBe(SiteReviewStatus::Approved);
});

test('reject transitions the review to rejected', function () {
    $review = SiteReview::factory()->for($this->site)->create();

    Livewire::actingAs($this->user)
        ->test('review-moderation-panel', ['siteId' => $this->site->id])
        ->call('reject', $review->id);

    expect($review->refresh()->status)->toBe(SiteReviewStatus::Rejected);
});

test('unauthorized agent cannot approve a review', function () {
    $review = SiteReview::factory()->for($this->site)->create();
    $outsider = User::factory()->staff(AgentRole::Agent)->create();

    Livewire::actingAs($outsider)
        ->test('review-moderation-panel', ['siteId' => $this->site->id])
        ->call('approve', $review->id);

    expect($review->refresh()->status)->toBe(SiteReviewStatus::Pending);
});

test('approve ignores a review belonging to a different site', function () {
    $otherSite = Site::factory()->create(['created_by_user_id' => $this->user->id]);
    $foreignReview = SiteReview::factory()->for($otherSite)->create();

    Livewire::actingAs($this->user)
        ->test('review-moderation-panel', ['siteId' => $this->site->id])
        ->call('approve', $foreignReview->id);

    expect($foreignReview->refresh()->status)->toBe(SiteReviewStatus::Pending);
});

test('agent can re-moderate: approving a rejected review republishes it', function () {
    $review = SiteReview::factory()->for($this->site)->rejected()->create();

    $this->mock(PublicPageCache::class)
        ->shouldReceive('invalidate')
        ->once()
        ->withArgs(fn (Site $site) => $site->id === $this->site->id);

    Livewire::actingAs($this->user)
        ->test('review-moderation-panel', ['siteId' => $this->site->id])
        ->call('approve', $review->id);

    expect($review->refresh()->status)->toBe(SiteReviewStatus::Approved);
});

test('re-approving an already approved review is a no-op and skips cache invalidation', function () {
    $review = SiteReview::factory()->for($this->site)->approved()->create();

    $this->mock(PublicPageCache::class)->shouldNotReceive('invalidate');

    Livewire::actingAs($this->user)
        ->test('review-moderation-panel', ['siteId' => $this->site->id])
        ->call('approve', $review->id)
        ->assertSee('already approved');

    expect($review->refresh()->status)->toBe(SiteReviewStatus::Approved);
});

test('unrecognised status filter coerces to pending', function () {
    SiteReview::factory()->for($this->site)->create(['author_name' => 'Pending Pat']);
    SiteReview::factory()->for($this->site)->approved()->create(['author_name' => 'Approved Alma']);

    Livewire::actingAs($this->user)
        ->test('review-moderation-panel', ['siteId' => $this->site->id])
        ->set('statusFilter', 'bogus')
        ->assertSet('statusFilter', 'pending')
        ->assertSee('Pending Pat')
        ->assertDontSee('Approved Alma');
});

test('with, findAuthorizedSite and assertAuthorizedSiteAccess are not callable actions', function () {
    foreach (['with', 'findAuthorizedSite', 'assertAuthorizedSiteAccess'] as $method) {
        expect(fn () => Livewire::actingAs($this->user)
            ->test('review-moderation-panel', ['siteId' => $this->site->id])
            ->call($method))
            ->toThrow(MethodNotFoundException::class);
    }
});

test('guest gets no review data and cannot moderate via direct component access', function () {
    $review = SiteReview::factory()->for($this->site)->create(['author_name' => 'Pending Pat']);

    Livewire::test('review-moderation-panel', ['siteId' => $this->site->id])
        ->assertSee('not authorised')
        ->assertDontSee('Pending Pat')
        ->call('approve', $review->id);

    expect($review->refresh()->status)->toBe(SiteReviewStatus::Pending);
});

test('reviews paginate at 25 per page on the reviewsPage paginator', function () {
    foreach (range(1, 26) as $i) {
        SiteReview::factory()->for($this->site)->create([
            'author_name' => sprintf('Reviewer %02d', $i),
            'created_at' => now()->subMinutes(27 - $i),
        ]);
    }

    Livewire::actingAs($this->user)
        ->test('review-moderation-panel', ['siteId' => $this->site->id])
        ->assertSee('Reviewer 26')
        ->assertSee('Reviewer 02')
        ->assertDontSee('Reviewer 01')
        ->call('nextPage', 'reviewsPage')
        ->assertSee('Reviewer 01')
        ->assertDontSee('Reviewer 26');
});

test('a crafted top-level paginators update does not error', function () {
    Livewire::actingAs($this->user)
        ->test('review-moderation-panel', ['siteId' => $this->site->id])
        ->update([], ['paginators' => []])
        ->assertSet('statusMessage', null);
});

test('conflict and no-op outcomes render as a warning, successful moderation as success', function () {
    $alreadyApproved = SiteReview::factory()->for($this->site)->approved()->create();
    $pending = SiteReview::factory()->for($this->site)->create();

    Livewire::actingAs($this->user)
        ->test('review-moderation-panel', ['siteId' => $this->site->id])
        ->call('approve', $alreadyApproved->id)
        ->assertSet('statusMessageVariant', 'warning')
        ->call('approve', $pending->id)
        ->assertSet('statusMessageVariant', 'success');
});

test('component payload never contains internal Site columns or ip hashes', function () {
    $this->site->update(['agent_notes' => 'SENTINEL-AGENT-NOTES-9f3']);
    $review = SiteReview::factory()->for($this->site)->create(['ip_hash' => 'SENTINEL-IP-HASH-77a']);

    $component = Livewire::actingAs($this->user)
        ->test('review-moderation-panel', ['siteId' => $this->site->id])
        ->call('approve', $review->id);

    $payload = json_encode([$component->snapshot, $component->effects]);

    expect($payload)->not->toContain('SENTINEL-AGENT-NOTES-9f3')
        ->not->toContain('SENTINEL-IP-HASH-77a');
});

test('siteId is locked against hydration-time tampering', function () {
    $otherSite = Site::factory()->create(['created_by_user_id' => $this->user->id]);

    $component = Livewire::actingAs($this->user)
        ->test('review-moderation-panel', ['siteId' => $this->site->id]);

    expect(fn () => $component->set('siteId', $otherSite->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('existing snapshot cannot approve after the staff user is demoted to a same-site client', function () {
    $client = Client::factory()->create();
    $this->site->update(['client_id' => $client->id]);
    $review = SiteReview::factory()->for($this->site)->create();

    $component = Livewire::actingAs($this->user)
        ->test('review-moderation-panel', ['siteId' => $this->site->id]);

    $this->user->role = null;
    $this->user->client_id = $client->id;
    $this->user->save();

    $component->call('approve', $review->id)->assertForbidden();

    expect($review->refresh()->status)->toBe(SiteReviewStatus::Pending);
});
