<?php

use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Models\User;
use App\Support\MediaStorage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/**
 * The demo serves the portal and the storefront from one app on two hosts, so
 * product images are addressed by root-relative /storage paths and each host
 * serves its own. A faked disk answers with a relative path whatever its
 * configuration says, so these tests drive the public disk through its real
 * driver: only its root moves to a scratch directory, and its URL is pinned to
 * the value the demo container boots with.
 */
beforeEach(function () {
    config()->set('demo.enabled', true);
    config()->set('demo.site_host', 'localhost');
    config()->set('demo.user_email', 'demo@camino.example');
    config()->set('demo.user_password', 'webmcp-demo');
    config()->set('app.url', 'http://app.localhost:8090');
    config()->set('filesystems.media', 'public');
    config()->set('filesystems.media_private', 'local');
    Storage::fake('s3');
    Storage::fake('local');

    $root = storage_path('framework/testing/disks/public-demo-media');
    File::ensureDirectoryExists($root);
    File::cleanDirectory($root);
    config()->set('filesystems.disks.public.root', $root);
    config()->set('filesystems.disks.public.url', '/storage');
    Storage::forgetDisk('public');

    $this->artisan('demo:seed')->assertSuccessful();
});

/**
 * The live container pins URL generation to APP_URL at boot and rebinds the
 * host per request; the pin is applied here so the storefront renders exactly
 * what the container renders.
 */
function demoMediaPinUrlRootToPortal(): void
{
    URL::forceRootUrl((string) config('app.url'));
}

it('defaults the public disk to a root-relative URL when the demo flag is set at boot', function () {
    $snapshot = [];
    foreach (['DEMO_MODE', 'FILESYSTEM_PUBLIC_URL', 'APP_URL'] as $name) {
        $snapshot[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
    }
    $set = function (string $name, ?string $value): void {
        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            return;
        }
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name.'='.$value);
    };

    try {
        $set('FILESYSTEM_PUBLIC_URL', null);
        $set('APP_URL', 'http://app.localhost:8090');

        $set('DEMO_MODE', '1');
        $demo = require base_path('config/filesystems.php');
        $set('DEMO_MODE', 'false');
        $plain = require base_path('config/filesystems.php');
        $set('FILESYSTEM_PUBLIC_URL', 'https://cdn.example/media');
        $explicit = require base_path('config/filesystems.php');
        $set('DEMO_MODE', '1');
        $demoExplicit = require base_path('config/filesystems.php');

        // The demo's s3 alias holds logos and uploads; it takes the same address
        // form as the public disk so the storefront header never names the portal host.
        expect($demo['disks']['public']['url'])->toBe('/storage')
            ->and($demo['disks']['s3']['url'])->toBe('/storage')
            ->and($plain['disks']['public']['url'])->toBe('http://app.localhost:8090/storage')
            ->and($explicit['disks']['public']['url'])->toBe('https://cdn.example/media')
            ->and($demoExplicit['disks']['public']['url'])->toBe('https://cdn.example/media')
            ->and($demoExplicit['disks']['s3']['url'])->toBe('https://cdn.example/media');
    } finally {
        foreach ($snapshot as $name => [$env, $server, $put]) {
            if ($env === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }
            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
            $put === false ? putenv($name) : putenv($name.'='.$put);
        }
    }
});

it('serves seeded product photos from the real public disk at a root-relative path', function () {
    $path = 'site-media/64/products/fig-walnut-tart.webp';

    expect(Storage::disk('public')->exists($path))->toBeTrue()
        ->and(MediaStorage::disk()->url($path))->toBe('/storage/'.$path);
});

it('stores root-relative image paths in the shop snapshot', function () {
    $json = ShopSnapshotCurrent::query()->where('site_id', 64)->firstOrFail()->snapshot->json;

    $seen = 0;
    foreach ($json['products'] as $slug => $product) {
        foreach (['thumb', 'card', 'full'] as $size) {
            expect($product['image_urls'][$size] ?? null)->toStartWith('/storage/site-media/64/products/', $slug.' '.$size);
            $seen++;
        }
    }
    expect($seen)->toBe(30);
});

it('renders storefront images without the portal host and the portal list with the same paths', function () {
    demoMediaPinUrlRootToPortal();
    $src = '/storage/site-media/64/products/fig-walnut-tart.webp';

    foreach (['http://localhost/shop', 'http://localhost/products/fig-walnut-tart'] as $url) {
        $html = $this->get($url)->assertOk()->getContent();

        expect($html)->not->toContain('app.localhost', $url)
            ->and($html)->toMatch('#<img[^>]+src="'.preg_quote($src, '#').'"#', $url);
    }

    // Open Graph and JSON-LD need an absolute address; it resolves on the storefront host.
    $product = $this->get('http://localhost/products/fig-walnut-tart')->getContent();
    expect($product)->toContain('http://localhost:8090'.$src);

    $this->actingAs(User::query()->where('email', 'demo@camino.example')->firstOrFail());
    $list = Livewire::test('shop.products-list', ['siteId' => 64])->html();
    expect($list)->toContain('src="'.$src.'"')
        ->and($list)->not->toContain('src="http://app.localhost');
});

it('rewrites bundle media references to root-relative paths', function () {
    $site = Site::query()->findOrFail(64);

    expect($site->brand_favicon_url)->toStartWith('/storage/');
});

it('rebuilds a snapshot whose image addresses carry a host on the next seed, and only then', function () {
    $snapshot = ShopSnapshotCurrent::query()->where('site_id', 64)->firstOrFail()->snapshot;
    $json = $snapshot->json;
    foreach ($json['products'] as $slug => $product) {
        foreach ($product['image_urls'] ?? [] as $size => $url) {
            $json['products'][$slug]['image_urls'][$size] = 'http://app.localhost:8090'.$url;
        }
    }
    $snapshot->update(['json' => $json]);
    $stale = $snapshot->id;

    $this->artisan('demo:seed')->assertSuccessful();

    $rebuilt = ShopSnapshotCurrent::query()->where('site_id', 64)->firstOrFail()->snapshot;
    expect($rebuilt->id)->not->toBe($stale)
        ->and($rebuilt->json['products']['fig-walnut-tart']['image_urls']['full'])
        ->toBe('/storage/site-media/64/products/fig-walnut-tart.webp');

    $this->artisan('demo:seed')->assertSuccessful();

    expect(ShopSnapshotCurrent::query()->where('site_id', 64)->firstOrFail()->snapshot_id)->toBe($rebuilt->id);
});

it('renders the storefront home, header logo included, without a single portal-host address', function () {
    $home = $this->get('http://localhost/')->assertOk()->getContent();

    expect($home)->toContain('src="/storage/sites/64/logo/')
        ->and($home)->not->toMatch('#https?://[^"\']*/storage/#')
        ->and($home)->not->toContain(parse_url((string) config('app.url'), PHP_URL_HOST));
});
