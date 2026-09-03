<?php

use App\Enums\Shop\ProductReviewStatus;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\ProductReview;
use App\Services\Shop\SnapshotBuilder;
use Tests\Support\ProductReviewsFixtures;

/**
 * @return array{site: \App\Models\Site, product: \App\Models\Shop\Product, host: string}
 */
function productReviewFormStore(array $settings): array
{
    $fixture = ProductReviewsFixtures::bakery($settings);
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    $site->update(['product_fact_groups' => null, 'custom_domain' => 'reviews-form-'.uniqid().'.example']);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

    return ['site' => $site->fresh(), 'product' => $product, 'host' => $site->fresh()->custom_domain];
}

function productReviewFormPost(string $host, \App\Models\Site $site, array $payload = [])
{
    $honeypot = $site->enquiryHoneypotFieldName();

    return test()->post('http://'.$host.'/products/victoria-sponge/reviews', array_merge([
        'author_name' => 'Ada',
        'rating' => 5,
        'title' => 'Lovely sponge',
        'body' => 'Soft crumb, proper jam.',
        $honeypot => '',
    ], $payload));
}

test('the public form is 404 when disabled or when the public form knob is off', function () {
    $off = productReviewFormStore(['enabled' => false, 'public_form' => true]);
    $this->get('http://'.$off['host'].'/products/victoria-sponge/review')->assertNotFound();
    productReviewFormPost($off['host'], $off['site'])->assertNotFound();
    expect(ProductReview::query()->where('author_name', 'Ada')->count())->toBe(0);

    $hidden = productReviewFormStore(['enabled' => true, 'public_form' => false]);
    $this->get('http://'.$hidden['host'].'/products/victoria-sponge/review')->assertNotFound();
    productReviewFormPost($hidden['host'], $hidden['site'])->assertNotFound();
    expect(ProductReview::query()->where('author_name', 'Ada')->count())->toBe(0);
});

test('a shopper review is pending when moderate is on and published when it is off', function () {
    $pendingSite = productReviewFormStore(['enabled' => true, 'public_form' => true, 'moderate' => true]);
    productReviewFormPost($pendingSite['host'], $pendingSite['site'])
        ->assertRedirect()
        ->assertSessionHas('status', 'Thanks — your review is awaiting approval.');

    $pending = ProductReview::query()->where('site_id', $pendingSite['site']->id)->where('author_name', 'Ada')->sole();
    expect($pending->status)->toBe(ProductReviewStatus::Pending)
        ->and($pending->source->value)->toBe('shopper')
        ->and($pending->author_email_hash)->toBeNull();

    $html = $this->get('http://'.$pendingSite['host'].'/products/victoria-sponge')->assertOk()->getContent();
    expect($html)->not->toContain('Ada')
        ->and($html)->not->toContain('author_email');

    $live = productReviewFormStore(['enabled' => true, 'public_form' => true, 'moderate' => false]);
    productReviewFormPost($live['host'], $live['site'])->assertRedirect();
    $published = ProductReview::query()->where('site_id', $live['site']->id)->where('author_name', 'Ada')->sole();
    expect($published->status)->toBe(ProductReviewStatus::Published);
});

test('the write a review button is only on the PDP when the public form is on', function () {
    $on = productReviewFormStore(['enabled' => true, 'public_form' => true]);
    $off = productReviewFormStore(['enabled' => true, 'public_form' => false]);

    $onHtml = $this->get('http://'.$on['host'].'/products/victoria-sponge')->assertOk()->getContent();
    $offHtml = $this->get('http://'.$off['host'].'/products/victoria-sponge')->assertOk()->getContent();

    expect($onHtml)->toContain('Write a review')
        ->and($offHtml)->not->toContain('Write a review');
});

test('the honeypot pretends success and stores nothing', function () {
    $store = productReviewFormStore(['enabled' => true, 'public_form' => true]);
    $before = ProductReview::query()->count();

    productReviewFormPost($store['host'], $store['site'], [
        $store['site']->enquiryHoneypotFieldName() => 'https://spam.example',
    ])->assertRedirect();

    expect(ProductReview::query()->count())->toBe($before);
});

test('a fourth review from the same IP in ten minutes is 429', function () {
    $store = productReviewFormStore(['enabled' => true, 'public_form' => true]);

    for ($i = 0; $i < 3; $i++) {
        test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.80'])
            ->post('http://'.$store['host'].'/products/victoria-sponge/reviews', [
                'author_name' => 'Ada',
                'rating' => 5,
                'title' => 'Nice '.$i,
                'body' => 'A solid buy.',
                $store['site']->enquiryHoneypotFieldName() => '',
            ])->assertRedirect();
    }

    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.80'])
        ->post('http://'.$store['host'].'/products/victoria-sponge/reviews', [
            'author_name' => 'Ada',
            'rating' => 5,
            'title' => 'Nice 3',
            'body' => 'A solid buy.',
            $store['site']->enquiryHoneypotFieldName() => '',
        ])->assertStatus(429);
});

test('the form rejects out of bounds ratings and overlong bodies', function () {
    $store = productReviewFormStore(['enabled' => true, 'public_form' => true]);

    productReviewFormPost($store['host'], $store['site'], ['rating' => 6])->assertSessionHasErrors('rating');
    productReviewFormPost($store['host'], $store['site'], ['body' => str_repeat('B', 2001)])->assertSessionHasErrors('body');
});
