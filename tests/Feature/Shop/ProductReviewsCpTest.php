<?php

use App\Enums\Shop\ProductReviewStatus;
use App\Models\Client;
use App\Models\Shop\Product;
use App\Models\Shop\ProductReview;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\ProductReviewsFixtures;

test('an agent can filter approve hide delete and add a manual review', function () {
    $fixture = ProductReviewsFixtures::bakery(['enabled' => true]);
    $site = $fixture['site'];
    $user = $fixture['user'];
    $product = $fixture['products'][0];
    $pending = ProductReview::query()->where('site_id', $site->id)->where('status', ProductReviewStatus::Pending)->first();
    $published = ProductReview::query()->where('site_id', $site->id)->where('status', ProductReviewStatus::Published)->first();

    $this->actingAs($user)
        ->get(route('sites.shop.reviews', $site))
        ->assertOk()
        ->assertSeeLivewire('shop.product-reviews-moderation')
        ->assertSee('Reviews')
        ->assertDontSee($published->author_email_hash ?? 'never-hash')
        ->assertSee(route('sites.shop.reviews', $site), false);

    Livewire::actingAs($user)
        ->test('shop.product-reviews-moderation', ['siteId' => $site->id])
        ->assertSee($pending->title)
        ->call('approve', $pending->id)
        ->assertHasNoErrors();

    expect($pending->fresh()->status)->toBe(ProductReviewStatus::Published);

    Livewire::actingAs($user)
        ->test('shop.product-reviews-moderation', ['siteId' => $site->id])
        ->set('statusFilter', 'published')
        ->call('hide', $published->id);

    expect($published->fresh()->status)->toBe(ProductReviewStatus::Hidden);

    $toDelete = ProductReview::factory()->for($site)->for($product)->published()->create(['title' => 'Delete me']);
    Livewire::actingAs($user)
        ->test('shop.product-reviews-moderation', ['siteId' => $site->id])
        ->set('statusFilter', 'published')
        ->call('deleteReview', $toDelete->id);

    expect(ProductReview::query()->whereKey($toDelete->id)->exists())->toBeFalse();

    Livewire::actingAs($user)
        ->test('shop.product-reviews-moderation', ['siteId' => $site->id])
        ->set('manualProductId', $product->id)
        ->set('manualRating', 4)
        ->set('manualTitle', 'Staff pick')
        ->set('manualBody', 'We tried this one.')
        ->set('manualAuthorName', 'Camino')
        ->call('addManualReview')
        ->assertHasNoErrors();

    $manual = ProductReview::query()->where('title', 'Staff pick')->sole();
    expect($manual->source->value)->toBe('manual')
        ->and($manual->status)->toBe(ProductReviewStatus::Published)
        ->and($manual->site_id)->toBe($site->id);
});

test('bulk approve and hide only touch the current site', function () {
    $fixture = ProductReviewsFixtures::bakery(['enabled' => true]);
    $site = $fixture['site'];
    $user = $fixture['user'];
    $pending = ProductReview::query()->where('site_id', $site->id)->where('status', ProductReviewStatus::Pending)->get();
    $foreign = ProductReviewsFixtures::florist(['enabled' => true]);
    $foreignPending = ProductReview::factory()->for($foreign['site'])->for($foreign['products'][0])->create();

    Livewire::actingAs($user)
        ->test('shop.product-reviews-moderation', ['siteId' => $site->id])
        ->set('selectedIds', $pending->pluck('id')->all())
        ->call('bulkApprove');

    expect($pending->map->fresh()->every(fn ($review) => $review->status === ProductReviewStatus::Published))->toBeTrue()
        ->and($foreignPending->fresh()->status)->toBe(ProductReviewStatus::Pending);
});

test('moderation of another site\'s review is 404', function () {
    $fixture = ProductReviewsFixtures::bakery(['enabled' => true]);
    $foreign = ProductReviewsFixtures::florist(['enabled' => true]);
    $foreignReview = ProductReview::factory()->for($foreign['site'])->for($foreign['products'][0])->create();

    Livewire::actingAs($fixture['user'])
        ->test('shop.product-reviews-moderation', ['siteId' => $fixture['site']->id])
        ->call('approve', $foreignReview->id)
        ->assertNotFound();

    expect($foreignReview->fresh()->status)->toBe(ProductReviewStatus::Pending);
});

test('a client of the site can moderate and an outsider cannot', function () {
    $tenant = Client::factory()->create();
    $otherTenant = Client::factory()->create();
    $client = User::factory()->create(['client_id' => $tenant->id, 'role' => null]);
    $stranger = User::factory()->create(['client_id' => $otherTenant->id, 'role' => null]);
    $site = Site::factory()->create(['client_id' => $tenant->id, 'shop_enabled' => true]);
    $product = Product::factory()->for($site)->published()->create();
    $review = ProductReview::factory()->for($site)->for($product)->create(['title' => 'Client pending']);

    $this->actingAs($client)
        ->get(route('client.portal.shop.reviews', $site))
        ->assertOk()
        ->assertSeeLivewire('shop.product-reviews-moderation');

    Livewire::actingAs($client)
        ->test('shop.product-reviews-moderation', ['siteId' => $site->id])
        ->call('approve', $review->id)
        ->assertHasNoErrors();

    expect($review->fresh()->status)->toBe(ProductReviewStatus::Published);

    Livewire::actingAs($stranger)
        ->test('shop.product-reviews-moderation', ['siteId' => $site->id])
        ->assertForbidden();
});

test('the CP escapes review copy and never shows hashes', function () {
    $fixture = ProductReviewsFixtures::bakery(['enabled' => true]);
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    $review = ProductReview::factory()->for($site)->for($product)->create([
        'title' => '<script>alert(1)</script>',
        'body' => '<img src=x onerror=alert(1)>',
        'author_name' => '<b>Ada</b>',
        'author_email_hash' => hash('sha256', 'ada@example.com'),
        'ip_hash' => hash('sha256', '10.0.0.1'),
        'invite_token_hash' => hash('sha256', 'token'),
    ]);

    $html = Livewire::actingAs($fixture['user'])
        ->test('shop.product-reviews-moderation', ['siteId' => $site->id])
        ->html();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->not->toContain($review->author_email_hash)
        ->and($html)->not->toContain($review->ip_hash)
        ->and($html)->not->toContain($review->invite_token_hash);
});
