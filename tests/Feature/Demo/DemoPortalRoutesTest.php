<?php

use App\Enums\Shop\OrderStatus;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Site;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

function portalDemoOrder(Site $site): Order
{
    return Order::create([
        'site_id' => $site->id,
        'number' => 'D-1001',
        'email' => 'buyer@example.com',
        'name' => 'Buyer',
        'status' => OrderStatus::Paid->value,
        'refund_status' => 'none',
        'subtotal_cents' => 1000,
        'shipping_cents' => 0,
        'tax_cents' => 0,
        'shipping_tax_cents' => 0,
        'total_cents' => 1000,
        'tax_country_code' => 'GB',
        'shipping_address_json' => [],
        'shipping_method_label' => 'Std',
        'placed_at' => now(),
    ]);
}

it('every kept client.portal GET route returns 200 for the demo user and does not render DemoMissingComponent', function () {
    Storage::fake('s3');
    Storage::fake(config('filesystems.default'));
    [$site, $user] = demoSite64();
    $this->withoutVite();
    config()->set('site.native_reviews_enabled', true);
    $site->forceFill(['native_reviews_enabled' => true])->save();

    $product = Product::query()->where('site_id', $site->id)->first()
        ?? Product::factory()->for($site)->published()->create();
    $order = Order::query()->where('site_id', $site->id)->first()
        ?? portalDemoOrder($site);

    $params = [
        'site' => $site,
        'product' => $product,
        'order' => $order,
    ];

    $checked = [];

    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();
        if (! is_string($name) || ! str_starts_with($name, 'client.portal.')) {
            continue;
        }
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }
        if (in_array('signed', $route->gatherMiddleware(), true)) {
            continue;
        }

        $urlParams = [];
        foreach ($route->parameterNames() as $parameter) {
            if (! isset($params[$parameter]) || $params[$parameter] === null) {
                continue 2;
            }
            $urlParams[$parameter] = $params[$parameter];
        }

        $response = $this->actingAs($user)->get(route($name, $urlParams));
        if ($response->isRedirect()) {
            $response = $this->actingAs($user)->get($response->headers->get('Location'));
        }

        expect($response->getStatusCode())->toBe(200, $name);
        $html = $response->getContent();
        expect(str_contains($html, 'data-demo-missing-component'))->toBeFalse()
            ->and(str_contains($html, 'DemoMissingComponent'))->toBeFalse();
        $checked[] = $name;
    }

    expect($checked)->not->toBeEmpty()
        ->and($checked)->toContain('client.portal.site')
        ->and($checked)->toContain('client.portal.design');
});
