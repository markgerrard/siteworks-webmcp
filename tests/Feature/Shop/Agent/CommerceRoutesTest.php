<?php

use App\Enums\AgentRole;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\Shop\TaxClassSeeder;
use Illuminate\Support\Facades\Route;
use Tests\Support\CommerceReads;

/**
 * @return array<string, array{name: string, method: string, path: string, write: bool, body: array<string, mixed>}>
 */
function commerceFrontOneRoutes(Site $site): array
{
    return [
        'list_products' => [
            'name' => 'site.editor.list-products',
            'method' => 'GET',
            'path' => "/sites/{$site->id}/shop/products",
            'write' => false,
            'body' => [],
        ],
        'get_product' => [
            'name' => 'site.editor.get-product',
            'method' => 'GET',
            'path' => "/sites/{$site->id}/shop/product",
            'write' => false,
            'body' => ['slug' => 'missing'],
        ],
        'draft_product' => [
            'name' => 'site.editor.draft-product',
            'method' => 'POST',
            'path' => "/sites/{$site->id}/shop/products",
            'write' => true,
            'body' => CommerceReads::draftProductInput(),
        ],
        'update_draft_product' => [
            'name' => 'site.editor.update-draft-product',
            'method' => 'POST',
            'path' => "/sites/{$site->id}/shop/product",
            'write' => true,
            'body' => [
                'slug' => 'missing',
                'catalogue_revision' => 0,
                'product_revision' => 0,
                'name' => 'Nope',
            ],
        ],
        'set_product_image' => [
            'name' => 'site.editor.set-product-image',
            'method' => 'POST',
            'path' => "/sites/{$site->id}/shop/product/image",
            'write' => true,
            'body' => [
                'slug' => 'missing',
                'catalogue_revision' => 0,
                'product_revision' => 0,
                'media_id' => 1,
            ],
        ],
    ];
}

beforeEach(function () {
    CommerceReads::enableFlags();
    $this->seed(TaxClassSeeder::class);
    $this->withoutVite();
});

it('403s every commerce Front 1 route without SitePolicy', function () {
    [$owner, $site] = CommerceReads::shopSite();
    $stranger = User::factory()->staff(AgentRole::Agent)->create();

    foreach (commerceFrontOneRoutes($site) as $operation => $route) {
        $response = $this->actingAs($stranger)->{$route['method'] === 'GET' ? 'getJson' : 'postJson'}(
            $route['path'],
            $route['body'],
        );

        $response->assertForbidden()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error.code', 'forbidden');

        expect(Product::query()->where('site_id', $site->id)->exists())->toBeFalse($operation);
    }
});

it('409s commerce write routes on a missing or stale catalogue_revision', function () {
    [$actor, $site] = CommerceReads::shopSite();
    $product = Product::factory()->for($site)->create(['slug' => 'stale-probe']);

    foreach (commerceFrontOneRoutes($site) as $operation => $route) {
        if (! $route['write']) {
            continue;
        }

        $missing = $route['body'];
        unset($missing['catalogue_revision']);
        if ($operation !== 'draft_product') {
            $missing['slug'] = $product->slug;
            $missing['product_revision'] = (int) $product->revision;
        }

        $this->actingAs($actor)->postJson($route['path'], $missing)
            ->assertConflict()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error.code', 'stale_revision');

        $stale = $route['body'];
        $stale['catalogue_revision'] = 99;
        if ($operation !== 'draft_product') {
            $stale['slug'] = $product->slug;
            $stale['product_revision'] = (int) $product->revision;
        }

        $this->postJson($route['path'], $stale)
            ->assertConflict()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error.code', 'stale_revision');
    }

    expect(Product::query()->where('site_id', $site->id)->where('slug', '!=', 'stale-probe')->exists())->toBeFalse();
});

it('keeps commerce Front 1 routes off the customer surface', function () {
    [$actor, $site] = CommerceReads::shopSite();
    $customer = 'http://'.config('domains.customer_domain');

    foreach (commerceFrontOneRoutes($site) as $operation => $route) {
        expect(Route::has($route['name']))->toBeTrue($operation);
        expect(Route::getRoutes()->getByName($route['name'])?->getDomain())
            ->toBe(config('domains.agent_domain'), $operation);

        $response = $this->actingAs($actor)
            ->{$route['method'] === 'GET' ? 'getJson' : 'postJson'}(
                $customer.$route['path'],
                $route['body'],
            );

        $response->assertNotFound();
        expect($response->json('ok'))->toBeNull($operation);
    }
});
