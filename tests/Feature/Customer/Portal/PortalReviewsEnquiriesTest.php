<?php

use App\Enums\SiteReviewStatus;
use App\Models\Client;
use App\Models\Site;
use App\Models\SiteEnquiry;
use App\Models\SiteReview;
use App\Models\User;
use App\Services\Site\PublicPageCache;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->customerHost = config('domains.customer_domain');

    // The portal Reviews section is gated on the master config flag AND
    // the per-site flag — both on by default for these tests.
    config(['site.native_reviews_enabled' => true]);

    $this->client = Client::factory()->create();
    $this->user = User::factory()->create([
        'client_id' => $this->client->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $this->site = Site::factory()->create([
        'client_id' => $this->client->id,
        'native_reviews_enabled' => true,
    ]);

    $otherClient = Client::factory()->create();
    $this->otherUser = User::factory()->create([
        'client_id' => $otherClient->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $this->otherSite = Site::factory()->create([
        'client_id' => $otherClient->id,
        'native_reviews_enabled' => true,
    ]);
});

// ─── Route access ────────────────────────────────────────────────────────────

test('client can open the reviews and enquiries sections for their own site', function () {
    $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/sites/{$this->site->id}/reviews")
        ->assertOk()
        ->assertSeeLivewire('client.review-moderation');

    $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/sites/{$this->site->id}/enquiries")
        ->assertOk()
        ->assertSeeLivewire('client.enquiries-inbox');
});

test('reviews section 404s when the master feature flag is off', function () {
    config(['site.native_reviews_enabled' => false]);

    $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/sites/{$this->site->id}/reviews")
        ->assertNotFound();
});

test('reviews section 404s when the per-site flag is off', function () {
    $this->site->update(['native_reviews_enabled' => false]);

    $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/sites/{$this->site->id}/reviews")
        ->assertNotFound();
});

test('sidebar hides the reviews entry when native reviews are disabled', function () {
    config(['site.native_reviews_enabled' => false]);

    $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/sites/{$this->site->id}/enquiries")
        ->assertOk()
        ->assertDontSee("/sites/{$this->site->id}/reviews");
});

test('client is forbidden from another tenant\'s reviews and enquiries sections', function () {
    $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/sites/{$this->otherSite->id}/reviews")
        ->assertForbidden();

    $this->actingAs($this->user)
        ->get("https://{$this->customerHost}/sites/{$this->otherSite->id}/enquiries")
        ->assertForbidden();
});

test('guests are redirected away from the reviews and enquiries sections', function () {
    $this->get("https://{$this->customerHost}/sites/{$this->site->id}/reviews")
        ->assertRedirect();

    $this->get("https://{$this->customerHost}/sites/{$this->site->id}/enquiries")
        ->assertRedirect();
});

// ─── Review moderation component ─────────────────────────────────────────────

test('client sees only their own site\'s pending reviews', function () {
    SiteReview::factory()->for($this->site)->create(['author_name' => 'Own Olive']);
    SiteReview::factory()->for($this->otherSite)->create(['author_name' => 'Foreign Fiona']);

    Livewire::actingAs($this->user)
        ->test('client.review-moderation', ['siteId' => $this->site->id])
        ->assertSee('Own Olive')
        ->assertDontSee('Foreign Fiona');
});

test('tampered siteId resolves to nothing — no foreign review data leaks', function () {
    SiteReview::factory()->for($this->otherSite)->create(['author_name' => 'Foreign Fiona']);

    Livewire::actingAs($this->user)
        ->test('client.review-moderation', ['siteId' => $this->otherSite->id])
        ->assertSee('not authorised')
        ->assertDontSee('Foreign Fiona');
});

test('client can approve their own pending review and the public cache is invalidated', function () {
    $review = SiteReview::factory()->for($this->site)->create();

    $this->mock(PublicPageCache::class)
        ->shouldReceive('invalidate')
        ->once()
        ->withArgs(fn (Site $site) => $site->id === $this->site->id);

    Livewire::actingAs($this->user)
        ->test('client.review-moderation', ['siteId' => $this->site->id])
        ->call('approve', $review->id);

    expect($review->refresh()->status)->toBe(SiteReviewStatus::Approved);
});

test('client can reject their own pending review', function () {
    $review = SiteReview::factory()->for($this->site)->create();

    Livewire::actingAs($this->user)
        ->test('client.review-moderation', ['siteId' => $this->site->id])
        ->call('reject', $review->id);

    expect($review->refresh()->status)->toBe(SiteReviewStatus::Rejected);
});

test('client cannot moderate another tenant\'s review by any route', function () {
    $foreignReview = SiteReview::factory()->for($this->otherSite)->create();

    // Cross-site review id against their own (authorised) component.
    Livewire::actingAs($this->user)
        ->test('client.review-moderation', ['siteId' => $this->site->id])
        ->call('approve', $foreignReview->id);

    // Tampered siteId targeting the foreign site directly.
    Livewire::actingAs($this->user)
        ->test('client.review-moderation', ['siteId' => $this->otherSite->id])
        ->call('approve', $foreignReview->id);

    expect($foreignReview->refresh()->status)->toBe(SiteReviewStatus::Pending);
});

test('client cannot re-moderate a non-pending review — agent decisions are final on this surface', function () {
    $review = SiteReview::factory()->for($this->site)->rejected()->create();

    $this->mock(PublicPageCache::class)->shouldNotReceive('invalidate');

    Livewire::actingAs($this->user)
        ->test('client.review-moderation', ['siteId' => $this->site->id])
        ->call('approve', $review->id)
        ->assertSee('already been moderated')
        ->assertSet('statusMessageVariant', 'warning');

    expect($review->refresh()->status)->toBe(SiteReviewStatus::Rejected);
});

test('reviews paginate at 25 per page on the reviewsPage paginator', function () {
    foreach (range(1, 26) as $i) {
        SiteReview::factory()->for($this->site)->create([
            'author_name' => sprintf('Reviewer %02d', $i),
            'created_at' => now()->subMinutes(27 - $i),
        ]);
    }

    Livewire::actingAs($this->user)
        ->test('client.review-moderation', ['siteId' => $this->site->id])
        ->assertSee('Reviewer 26')
        ->assertSee('Reviewer 02')
        ->assertDontSee('Reviewer 01')
        ->call('nextPage', 'reviewsPage')
        ->assertSee('Reviewer 01')
        ->assertDontSee('Reviewer 26');
});

test('a crafted top-level paginators update does not error', function () {
    Livewire::actingAs($this->user)
        ->test('client.review-moderation', ['siteId' => $this->site->id])
        ->update([], ['paginators' => []])
        ->assertSet('statusMessage', null);
});

test('unrecognised status filter coerces to pending', function () {
    SiteReview::factory()->for($this->site)->create(['author_name' => 'Pending Pat']);
    SiteReview::factory()->for($this->site)->approved()->create(['author_name' => 'Approved Alma']);

    Livewire::actingAs($this->user)
        ->test('client.review-moderation', ['siteId' => $this->site->id])
        ->set('statusFilter', 'bogus')
        ->assertSet('statusFilter', 'pending')
        ->assertSee('Pending Pat')
        ->assertDontSee('Approved Alma');
});

test('status message clears when the filter changes', function () {
    $review = SiteReview::factory()->for($this->site)->create();

    Livewire::actingAs($this->user)
        ->test('client.review-moderation', ['siteId' => $this->site->id])
        ->call('approve', $review->id)
        ->assertSet('statusMessage', fn ($message) => $message !== null)
        ->set('statusFilter', 'approved')
        ->assertSet('statusMessage', null);
});

// ─── Direct component attack surface ─────────────────────────────────────────

test('with is not a callable action on the client components', function () {
    expect(fn () => Livewire::actingAs($this->user)
        ->test('client.review-moderation', ['siteId' => $this->site->id])
        ->call('with'))
        ->toThrow(MethodNotFoundException::class);

    expect(fn () => Livewire::actingAs($this->user)
        ->test('client.enquiries-inbox', ['siteId' => $this->site->id])
        ->call('with'))
        ->toThrow(MethodNotFoundException::class);
});

test('siteId is locked — hydration-time tampering after an authorised mount throws', function () {
    $reviews = Livewire::actingAs($this->user)
        ->test('client.review-moderation', ['siteId' => $this->site->id]);

    expect(fn () => $reviews->set('siteId', $this->otherSite->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    $enquiries = Livewire::actingAs($this->user)
        ->test('client.enquiries-inbox', ['siteId' => $this->site->id]);

    expect(fn () => $enquiries->set('siteId', $this->otherSite->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('guest gets no review data and cannot moderate via direct component access', function () {
    $review = SiteReview::factory()->for($this->site)->create(['author_name' => 'Pending Pat']);

    Livewire::test('client.review-moderation', ['siteId' => $this->site->id])
        ->assertSee('not authorised')
        ->assertDontSee('Pending Pat')
        ->call('approve', $review->id);

    expect($review->refresh()->status)->toBe(SiteReviewStatus::Pending);
});

test('guest gets no enquiry data via direct component access', function () {
    SiteEnquiry::factory()->for($this->site)->create(['name' => 'Hidden Harry']);

    Livewire::test('client.enquiries-inbox', ['siteId' => $this->site->id])
        ->assertSee('not authorised')
        ->assertDontSee('Hidden Harry');
});

// ─── Enquiries component ─────────────────────────────────────────────────────

test('client sees only their own site\'s enquiries with contact details', function () {
    SiteEnquiry::factory()->for($this->site)->create([
        'name' => 'Own Enquirer',
        'email' => 'own@example.com',
        'payload' => ['phone' => '07700 900456', 'service' => 'Roofing', 'message' => 'Need a quote please.'],
    ]);
    SiteEnquiry::factory()->for($this->otherSite)->create(['name' => 'Foreign Enquirer']);

    Livewire::actingAs($this->user)
        ->test('client.enquiries-inbox', ['siteId' => $this->site->id])
        ->assertSee('Own Enquirer')
        ->assertSee('own@example.com')
        ->assertSee('07700 900456')
        ->assertSee('Roofing')
        ->assertSee('Need a quote please.')
        ->assertDontSee('Foreign Enquirer');
});

test('tampered siteId resolves to nothing — no foreign enquiry data leaks', function () {
    SiteEnquiry::factory()->for($this->otherSite)->create(['name' => 'Foreign Enquirer']);

    Livewire::actingAs($this->user)
        ->test('client.enquiries-inbox', ['siteId' => $this->otherSite->id])
        ->assertSee('not authorised')
        ->assertDontSee('Foreign Enquirer');
});

// ─── No internal fields in rendered output ───────────────────────────────────

test('internal columns never render — ip hashes and raw status stay server-side', function () {
    $reviewIpHash = hash('sha256', 'review-submitter-ip');
    $enquiryIpHash = hash('sha256', 'enquiry-submitter-ip');
    SiteReview::factory()->for($this->site)->create(['ip_hash' => $reviewIpHash]);
    SiteEnquiry::factory()->for($this->site)->create(['ip_hash' => $enquiryIpHash]);

    Livewire::actingAs($this->user)
        ->test('client.review-moderation', ['siteId' => $this->site->id])
        ->assertDontSee($reviewIpHash)
        ->assertDontSee('ip_hash');

    Livewire::actingAs($this->user)
        ->test('client.enquiries-inbox', ['siteId' => $this->site->id])
        ->assertDontSee($enquiryIpHash)
        ->assertDontSee('ip_hash');
});

test('model ip_hash column stays unreachable even when a payload key collides with it', function () {
    $secret = hash('sha256', 'enquiry-submitter-ip');
    SiteEnquiry::factory()->for($this->site)->create([
        'ip_hash' => $secret,
        'payload' => ['ip_hash' => 'payload-supplied-value', 'message' => 'hello'],
    ]);

    Livewire::actingAs($this->user)
        ->test('client.enquiries-inbox', ['siteId' => $this->site->id])
        ->assertDontSee($secret);
});

test('ip hashes are hidden from model serialization', function () {
    $review = SiteReview::factory()->for($this->site)->create(['ip_hash' => 'review-hash']);
    $enquiry = SiteEnquiry::factory()->for($this->site)->create(['ip_hash' => 'enquiry-hash']);

    expect($review->toArray())->not->toHaveKey('ip_hash')
        ->and($enquiry->toArray())->not->toHaveKey('ip_hash');
});

test('component payload never contains internal Site columns or ip hashes', function () {
    $this->site->update(['agent_notes' => 'SENTINEL-AGENT-NOTES-9f3']);
    $review = SiteReview::factory()->for($this->site)->create(['ip_hash' => 'SENTINEL-IP-HASH-77a']);

    $component = Livewire::actingAs($this->user)
        ->test('client.review-moderation', ['siteId' => $this->site->id])
        ->call('approve', $review->id);

    $payload = json_encode([$component->snapshot, $component->effects]);

    expect($payload)->not->toContain('SENTINEL-AGENT-NOTES-9f3')
        ->not->toContain('SENTINEL-IP-HASH-77a');
});

test('non-scalar and excess payload values render safely instead of erroring', function () {
    SiteEnquiry::factory()->for($this->site)->create([
        'name' => 'Structured Steve',
        'payload' => array_merge(
            ['message' => 'Legit message', 'meta' => ['nested' => 'object'], 'long' => str_repeat('x', 500)],
            collect(range(1, 15))->mapWithKeys(fn ($i) => ["extra_{$i}" => "value {$i}"])->all(),
        ),
    ]);

    Livewire::actingAs($this->user)
        ->test('client.enquiries-inbox', ['siteId' => $this->site->id])
        ->assertSee('Structured Steve')
        ->assertSee('Legit message')
        ->assertDontSee('nested')
        ->assertDontSee('value 15');
});

test('oversized well-known payload values are clipped at render', function () {
    $longPhone = str_repeat('7', 300);
    SiteEnquiry::factory()->for($this->site)->create([
        'name' => 'Clipped Cathy',
        'payload' => ['phone' => $longPhone],
    ]);

    Livewire::actingAs($this->user)
        ->test('client.enquiries-inbox', ['siteId' => $this->site->id])
        ->assertSee('Clipped Cathy')
        ->assertDontSee($longPhone);
});
