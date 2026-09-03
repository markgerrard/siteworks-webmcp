<?php

use App\Enums\Shop\OrderStatus;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{site: Site, order: Order, homeHref: string}
 */
function checkoutOutcomeSite(string $number = 'BLOOM-000042'): array
{
    $site = Site::factory()->create([
        'custom_domain' => 'flowers.example',
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
        'slug' => 'bloom-stem',
    ]);
    Product::factory()->published()->for($site)->create(['slug' => 'conserve', 'name' => 'Strawberry Conserve']);

    $order = Order::create([
        'site_id' => $site->id,
        'number' => $number,
        'email' => 'jane@example.com',
        'name' => 'Jane',
        'status' => OrderStatus::Pending->value,
        'refund_status' => 'none',
        'subtotal_cents' => 595,
        'shipping_cents' => 350,
        'tax_cents' => 99,
        'shipping_tax_cents' => 58,
        'total_cents' => 945,
        'tax_country_code' => 'GB',
        'shipping_address_json' => [],
        'shipping_method_label' => 'Royal Mail 48',
        'placed_at' => now(),
        'stripe_checkout_session_id' => 'cs_test_spent_session',
    ]);

    return [
        'site' => $site,
        'order' => $order,
        'homeHref' => app(PageRenderer::class)->layoutContext($site)['homeHref'],
    ];
}

/**
 * @return array{labels: list<string>, html: string}
 */
function checkoutOutcomeBreadcrumbs(string $html): array
{
    preg_match('/<nav\b[^>]*aria-label="Breadcrumb"[^>]*>(.*?)<\/nav>/is', $html, $nav);
    expect($nav)->not->toBeEmpty();

    preg_match_all('/<li\b([^>]*)>(.*?)<\/li>/is', $nav[1], $matches, PREG_SET_ORDER);
    $labels = [];
    foreach ($matches as $match) {
        $labels[] = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5));
    }

    $last = $matches[array_key_last($matches)];
    expect($last[1])->toContain('aria-current="page"')
        ->and($last[2])->not->toMatch('/<a\b/i');

    return ['labels' => $labels, 'html' => $nav[1]];
}

test('checkout success shows the order number, what happens next, and continue shopping', function () {
    ['order' => $order] = checkoutOutcomeSite();

    $html = $this->withSession(['shop.last_order_id' => $order->id])
        ->get('http://flowers.example/shop/checkout/success')
        ->assertOk()
        ->getContent();
    preg_match('/<main\b[^>]*>(.*?)<\/main>/is', $html, $mainMatch);
    $main = $mainMatch[1];

    preg_match_all('/<h1\b/i', $main, $headings);
    expect($headings[0])->toHaveCount(1);

    $crumbs = checkoutOutcomeBreadcrumbs($main);
    expect($crumbs['labels'])->toBe(['Home', 'Shop', 'Order']);
    expect($crumbs['html'])->not->toMatch('/href="[^"]*\/shop\/checkout"/i');

    expect($main)->toContain($order->number)
        ->and($main)->not->toContain('cs_test_spent_session')
        ->and($main)->toMatch('/receipt|emailed|email/i')
        ->and($main)->toMatch('/Continue shopping/i')
        ->and($main)->toMatch('/href="[^"]*\/shop"/')
        ->and($main)->not->toMatch('/href="[^"]*\/shop\/checkout"/')
        ->and($main)->not->toMatch('/href="[^"]*\/shop\/checkout\/start"/')
        ->and($main)->not->toContain('text-blue-600');
});

test('checkout success shows the order number not the query id', function () {
    ['order' => $order] = checkoutOutcomeSite('SW-TEST-4242');

    $html = $this->withSession(['shop.last_order_id' => $order->id])
        ->get('http://flowers.example/shop/checkout/success')
        ->assertOk()
        ->getContent();
    preg_match('/<main\b[^>]*>(.*?)<\/main>/is', $html, $mainMatch);
    $text = html_entity_decode(strip_tags($mainMatch[1]), ENT_QUOTES | ENT_HTML5);

    expect($text)->toContain('SW-TEST-4242')
        ->and($order->number)->not->toBe((string) $order->id);
});

test('checkout cancel reassures the cart is intact and resumes the checkout form', function () {
    ['order' => $order] = checkoutOutcomeSite();

    $html = $this->get('http://flowers.example/shop/checkout/cancel?order='.$order->id)
        ->assertOk()
        ->getContent();
    preg_match('/<main\b[^>]*>(.*?)<\/main>/is', $html, $mainMatch);
    $main = $mainMatch[1];

    preg_match_all('/<h1\b/i', $main, $headings);
    expect($headings[0])->toHaveCount(1);

    $crumbs = checkoutOutcomeBreadcrumbs($main);
    expect($crumbs['labels'])->toBe(['Home', 'Shop', 'Order']);
    expect($crumbs['html'])->not->toMatch('/href="[^"]*\/shop\/checkout"/i');

    expect($main)->toMatch('/cart/i')
        ->and($main)->toMatch('/intact|still there|kept|waiting/i')
        ->and($main)->toMatch('/href="[^"]*\/shop\/checkout"/')
        ->and($main)->not->toMatch('/href="[^"]*\/shop\/checkout\/start"/')
        ->and($main)->not->toContain('cs_test_spent_session')
        ->and($main)->not->toContain('text-blue-600')
        ->and($main)->toMatch('/focus-visible|outline/');
});
