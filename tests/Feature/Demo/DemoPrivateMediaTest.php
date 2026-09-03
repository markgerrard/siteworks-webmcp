<?php

use App\Models\Shop\Product;
use App\Models\Site;
use App\Services\Shop\PersonalisationImageStore;
use App\Support\MediaStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Demo media invariant: product photos and site media are served statically
 * from the public disk; customer personalisation images live on a private
 * disk and are only reachable through the signed, authorised route.
 */
beforeEach(function () {
    config()->set('demo.enabled', true);
    config()->set('demo.site_host', 'localhost');
    config()->set('demo.user_email', 'demo@camino.example');
    config()->set('demo.user_password', 'webmcp-demo');
    config()->set('app.url', 'http://app.localhost:8090');
    config()->set('filesystems.media', 'public');
    config()->set('filesystems.media_private', 'local');
    Storage::fake('public');
    Storage::fake('local');
    $this->artisan('demo:seed')->assertSuccessful();
});

function demoPrivateMediaJpeg(): UploadedFile
{
    if (class_exists(\Imagick::class)) {
        $im = new \Imagick;
        $im->newImage(40, 40, new \ImagickPixel('#c0392b'));
        $im->setImageFormat('jpeg');
        $bytes = $im->getImageBlob();
        $im->clear();
        $im->destroy();
    } else {
        // Smallest valid baseline JPEG (1x1) for runtimes without an image library.
        $bytes = hex2bin('ffd8ffe000104a46494600010101000100010000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc0000b080001000101011100ffc40014100000000000000000000000000000000000ffda00080001010100003f00fbffd9');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'demo-artwork');
    file_put_contents($tmp, $bytes);

    return new UploadedFile($tmp, 'artwork.jpg', 'image/jpeg', null, true);
}

it('keeps seeded product photos on the statically served media disk', function () {
    $image = Product::query()->where('site_id', 64)->where('slug', 'fig-walnut-tart')->firstOrFail()
        ->images()->orderBy('sort_order')->firstOrFail();

    expect(MediaStorage::diskName())->toBe('public')
        ->and(Storage::disk('public')->exists($image->path))->toBeTrue()
        ->and(Storage::disk('local')->exists($image->path))->toBeFalse()
        ->and($image->url())->toBe(Storage::disk('public')->url($image->path))
        ->and(parse_url($image->url(), PHP_URL_PATH))->toStartWith('/storage/');
});

it('keeps a personalisation image off the static tree and refuses it at an unsigned /storage URL', function () {
    $site = Site::query()->findOrFail(64);
    $store = app(PersonalisationImageStore::class);

    $stored = $store->store($site, 'cart-1', demoPrivateMediaJpeg());
    $path = $stored['path'];

    expect(MediaStorage::privateDiskName())->toBe('local')
        ->and($path)->toStartWith('sites/64/personalisation/cart-1/')
        ->and(Storage::disk('local')->exists($path))->toBeTrue()
        ->and(Storage::disk('public')->exists($path))->toBeFalse()
        ->and(MediaStorage::disk()->exists($path))->toBeFalse();

    // Not in public/storage, so nothing serves it statically, and the app
    // refuses the bare path on both demo hosts (403 from the private disk's
    // signed-only route, or 404 where a storefront route shadows it).
    foreach (['http://localhost', 'http://app.localhost:8090'] as $host) {
        $response = $this->get($host.'/storage/'.$path);
        expect($response->status())->toBeIn([403, 404], $host)
            ->and((string) $response->headers->get('Content-Type'))->not->toStartWith('image/');
    }

    // The signed route remains the only serving path.
    $this->get($store->signedUrl($site, $path, 300, 'mail'))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
});
