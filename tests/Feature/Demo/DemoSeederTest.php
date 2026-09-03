<?php

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    config()->set('demo.enabled', true);
    config()->set('demo.site_host', 'localhost');
    config()->set('demo.user_email', 'demo@camino.example');
    config()->set('demo.user_password', 'webmcp-demo');
    config()->set('app.url', 'http://app.localhost:8090');
});

it('seeds site 64, the demo client user, preview host, and watermark off', function () {
    $this->artisan('demo:seed')->assertSuccessful();

    $site = Site::query()->find(64);
    expect($site)->not->toBeNull()
        ->and($site->business_name)->toBe('Camino Bakehouse')
        ->and($site->preview_domain)->toBe('localhost')
        ->and($site->custom_domain)->toBeNull()
        ->and($site->client_id)->not->toBeNull();

    $client = Client::query()->find($site->client_id);
    expect($client)->not->toBeNull()
        ->and($client->name)->toBe('Camino Bakehouse');

    $user = User::query()->where('email', 'demo@camino.example')->first();
    expect($user)->not->toBeNull()
        ->and($user->client_id)->toBe($client->id)
        ->and($user->role)->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('webmcp-demo', $user->password))->toBeTrue();

    $profile = BusinessProfile::query()->where('site_id', 64)->first();
    expect($profile)->not->toBeNull()
        ->and($profile->profile_data['watermark_enabled'] ?? true)->toBeFalse();

    $spaces = 'https://demo-media.lon1.digitaloceanspaces.com/';
    expect($site->brand_favicon_url)->not->toContain($spaces)
        ->and($site->brand_favicon_url)->toContain('/storage/');
});

it('is idempotent: a second demo:seed does not duplicate the site or user', function () {
    $this->artisan('demo:seed')->assertSuccessful();
    $this->artisan('demo:seed')->assertSuccessful();

    expect(Site::query()->where('id', 64)->count())->toBe(1)
        ->and(User::query()->where('email', 'demo@camino.example')->count())->toBe(1)
        ->and(Client::query()->where('name', 'Camino Bakehouse')->count())->toBe(1);

    $site = Site::query()->find(64);
    expect($site->preview_domain)->toBe('localhost')
        ->and($site->custom_domain)->toBeNull();
});

it('lights up the shop tool surface on the seeded demo catalogue', function () {
    $this->artisan('demo:seed')->assertSuccessful();
    $site = Site::query()->findOrFail(64);

    expect(\App\Models\Shop\Category::query()->where('site_id', 64)->count())->toBe(4)
        ->and(\App\Models\Shop\Product::query()->where('site_id', 64)->where('status', 'published')->count())->toBe(10)
        ->and(app(\App\Services\Site\Editor\Shop\ShopEntityResolver::class)->hasShop($site))->toBeTrue()
        ->and(app(\App\Services\Site\Editor\ToolExposure::class)->exposes($site, 'import_products'))->toBeTrue();

    $this->artisan('demo:seed')->assertSuccessful();
    expect(\App\Models\Shop\Category::query()->where('site_id', 64)->count())->toBe(4)
        ->and(\App\Models\Shop\Product::query()->where('site_id', 64)->count())->toBe(10);
});

