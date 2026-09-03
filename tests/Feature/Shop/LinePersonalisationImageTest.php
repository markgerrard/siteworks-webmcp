<?php

use App\Http\Controllers\Shop\CartController;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\CartItem;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Models\User;
use App\Services\Shop\PersonalisationImageStore;
use App\Services\Shop\SnapshotBuilder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\LinePersonalisationFixtures;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(config('filesystems.default'));
});

function personalisationTestJpegBytes(int $width = 1, int $height = 1): string
{
    if (class_exists(\Imagick::class)) {
        $im = new \Imagick;
        $im->newImage(max(1, $width), max(1, $height), new \ImagickPixel('#c0392b'));
        $im->setImageFormat('jpeg');
        $bytes = $im->getImageBlob();
        $im->clear();
        $im->destroy();

        return $bytes;
    }

    $jpeg = hex2bin('ffd8ffe000104a46494600010101000100010000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc0000b080001000101011100ffc40014100000000000000000000000000000000000ffda00080001010100003f00fbffd9');
    $sof = strpos($jpeg, "\xFF\xC0");
    if ($sof !== false) {
        $jpeg = substr_replace($jpeg, pack('n', $height).pack('n', $width), $sof + 5, 4);
    }

    return $jpeg;
}

function personalisationTestJpeg(int $width = 1, int $height = 1, ?int $padBytes = null): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'lpjpg');
    $bytes = personalisationTestJpegBytes($width, $height);
    if ($padBytes) {
        $bytes .= str_repeat('x', $padBytes);
    }
    file_put_contents($path, $bytes);

    return new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);
}

/**
 * @return array{site: Site, product: Product, variant: ProductVariant, host: string}
 */
function personalisationImageSite(string $host = 'img.example'): array
{
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
    ]);
    $product = Product::factory()->published()->for($site)->create([
        'slug' => 'item',
        'name' => 'Item',
        'customer_inputs' => LinePersonalisationFixtures::bakery(),
    ]);
    $variant = ProductVariant::factory()->for($product)->create(['price_cents' => 1500]);
    VariantStock::create(['variant_id' => $variant->id, 'on_hand' => 10]);
    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));

    return compact('site', 'product', 'variant', 'host');
}

test('store writes with private visibility on the media disk not the default disk', function () {
    Storage::fake('other-disk');
    Storage::fake('media-disk');
    config([
        'filesystems.default' => 'other-disk',
        'filesystems.media' => 'media-disk',
    ]);

    $site = Site::factory()->create();
    $stored = app(PersonalisationImageStore::class)->store($site, 'cart-1', personalisationTestJpeg(40, 40));

    Storage::disk('media-disk')->assertExists($stored['path']);
    Storage::disk('other-disk')->assertMissing($stored['path']);
    expect(Storage::disk('media-disk')->getVisibility($stored['path']))->toBe('private');
});

test('a jpeg upload is stored privately and attached to the cart line', function () {
    ['site' => $site, 'variant' => $variant] = personalisationImageSite();
    $sessionId = 'img-session';

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->post('http://img.example/shop/cart/add', [
            'product_slug' => 'item',
            'variant_id' => $variant->id,
            'qty' => 1,
            'personalisation' => [
                'message' => 'Happy birthday',
                'photo' => [personalisationTestJpeg(80, 80)],
            ],
        ])
        ->assertRedirect('http://img.example/shop/cart');

    $item = CartItem::first();
    expect($item->personalisation['photo']['kind'])->toBe('image')
        ->and($item->personalisation['photo']['value'][0]['mime'])->toBe('image/jpeg');

    $path = $item->personalisation['photo']['value'][0]['path'];
    expect($path)->toStartWith('sites/'.$site->id.'/personalisation/cart-')
        ->and(Storage::disk(config('filesystems.default'))->exists($path))->toBeTrue();
});

test('a php file renamed as jpg is rejected', function () {
    ['variant' => $variant] = personalisationImageSite('phpjpg.example');
    $tmp = tempnam(sys_get_temp_dir(), 'phpjpg');
    file_put_contents($tmp, '<?php echo "hi";');
    $upload = new UploadedFile($tmp, 'photo.jpg', 'image/jpeg', null, true);

    test()->from('http://phpjpg.example/products/item')
        ->post('http://phpjpg.example/shop/cart/add', [
            'product_slug' => 'item',
            'variant_id' => $variant->id,
            'qty' => 1,
            'personalisation' => [
                'message' => 'Happy birthday',
                'photo' => [$upload],
            ],
        ])
        ->assertSessionHasErrors();

    expect(CartItem::count())->toBe(0);
    @unlink($tmp);
});

test('an oversized file is rejected', function () {
    ['variant' => $variant] = personalisationImageSite('big.example');
    $upload = personalisationTestJpeg(10, 10, 9 * 1024 * 1024);

    test()->from('http://big.example/products/item')
        ->post('http://big.example/shop/cart/add', [
            'product_slug' => 'item',
            'variant_id' => $variant->id,
            'qty' => 1,
            'personalisation' => [
                'message' => 'Happy birthday',
                'photo' => [$upload],
            ],
        ])
        ->assertSessionHasErrors();

    expect(CartItem::count())->toBe(0);
});

test('a stock failure removes files uploaded for the rejected cart line', function () {
    ['site' => $site, 'variant' => $variant] = personalisationImageSite('stock-failure.example');
    VariantStock::where('variant_id', $variant->id)->update(['on_hand' => 0]);

    test()->withCookie(CartController::COOKIE_NAME, 'stock-failure-session')
        ->from('http://stock-failure.example/products/item')
        ->post('http://stock-failure.example/shop/cart/add', [
            'product_slug' => 'item',
            'variant_id' => $variant->id,
            'qty' => 1,
            'personalisation' => [
                'message' => 'Happy birthday',
                'photo' => [personalisationTestJpeg(40, 40)],
            ],
        ])
        ->assertSessionHasErrors();

    expect(Storage::disk(config('filesystems.default'))->allFiles('sites'))->toBe([]);
});

test('copying a missing image source fails before recording a destination path', function () {
    ['site' => $site] = personalisationImageSite('missing-source.example');

    expect(fn () => app(PersonalisationImageStore::class)->copyToOwner([
        'photo' => [
            'label' => 'Photo',
            'kind' => 'image',
            'value' => [[
                'path' => 'sites/'.$site->id.'/personalisation/cart-999/missing.jpg',
                'name' => 'missing.jpg',
                'bytes' => 10,
                'mime' => 'image/jpeg',
            ]],
        ],
    ], $site, 'order-1'))->toThrow(ValidationException::class);
});

test('an image over 6000px is rejected', function () {
    ['variant' => $variant] = personalisationImageSite('wide.example');
    $upload = personalisationTestJpeg(6001, 10);

    test()->from('http://wide.example/products/item')
        ->post('http://wide.example/shop/cart/add', [
            'product_slug' => 'item',
            'variant_id' => $variant->id,
            'qty' => 1,
            'personalisation' => [
                'message' => 'Happy birthday',
                'photo' => [$upload],
            ],
        ])
        ->assertSessionHasErrors();

    expect(CartItem::count())->toBe(0);
});

test('the uploading session can read the signed url and another site cannot', function () {
    ['site' => $site, 'variant' => $variant] = personalisationImageSite('own.example');
    $other = Site::factory()->create([
        'custom_domain' => 'other.example',
        'custom_domain_status' => 'active',
    ]);
    $otherProduct = Product::factory()->published()->for($other)->create(['slug' => 'other']);
    ProductVariant::factory()->for($otherProduct)->create();
    VariantStock::create(['variant_id' => $otherProduct->variants()->first()->id, 'on_hand' => 1]);
    $sessionId = 'own-session';

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->post('http://own.example/shop/cart/add', [
            'product_slug' => 'item',
            'variant_id' => $variant->id,
            'qty' => 1,
            'personalisation' => [
                'message' => 'Happy birthday',
                'photo' => [personalisationTestJpeg(40, 40)],
            ],
        ])
        ->assertRedirect();

    $path = CartItem::first()->personalisation['photo']['value'][0]['path'];
    $url = app(PersonalisationImageStore::class)->signedUrl($site, $path, 300);
    $query = parse_url($url, PHP_URL_QUERY);

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get('http://own.example/shop/personalisation?'.$query)
        ->assertOk();

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get('http://other.example/shop/personalisation?'.$query)
        ->assertForbidden();
});

test('staff of the site can read the image', function () {
    ['site' => $site, 'variant' => $variant] = personalisationImageSite('staff.example');
    $user = User::factory()->staff()->create();
    $site->update(['created_by_user_id' => $user->id]);
    $sessionId = 'staff-upload';

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->post('http://staff.example/shop/cart/add', [
            'product_slug' => 'item',
            'variant_id' => $variant->id,
            'qty' => 1,
            'personalisation' => [
                'message' => 'Happy birthday',
                'photo' => [personalisationTestJpeg(40, 40)],
            ],
        ])
        ->assertRedirect();

    $path = CartItem::first()->personalisation['photo']['value'][0]['path'];
    $url = app(PersonalisationImageStore::class)->signedUrl($site, $path, 300, 'mail');
    $query = parse_url($url, PHP_URL_QUERY);
    $otherStaff = User::factory()->staff()->create();

    test()->actingAs($user)
        ->get('http://staff.example/shop/personalisation?'.$query)
        ->assertOk();

    test()->actingAs($otherStaff)
        ->get('http://staff.example/shop/personalisation?'.$query)
        ->assertForbidden();

    auth()->logout();
    $shopperQuery = parse_url(app(PersonalisationImageStore::class)->signedUrl($site, $path, 300), PHP_URL_QUERY);
    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->get('http://staff.example/shop/personalisation?'.$shopperQuery)
        ->assertOk();

});

test('an anonymous request cannot read a signed shopper image', function () {
    auth()->logout();
    ['site' => $site, 'variant' => $variant] = personalisationImageSite('anonymous.example');
    $sessionId = 'anonymous-session';

    test()->withCookie(CartController::COOKIE_NAME, $sessionId)
        ->post('http://anonymous.example/shop/cart/add', [
            'product_slug' => 'item',
            'variant_id' => $variant->id,
            'qty' => 1,
            'personalisation' => [
                'message' => 'Happy birthday',
                'photo' => [personalisationTestJpeg(40, 40)],
            ],
        ])
        ->assertRedirect();

    $path = CartItem::first()->personalisation['photo']['value'][0]['path'];
    $query = parse_url(app(PersonalisationImageStore::class)->signedUrl($site, $path, 300), PHP_URL_QUERY);

    test()->withCookie(CartController::COOKIE_NAME, '')
        ->get('http://anonymous.example/shop/personalisation?'.$query)
        ->assertForbidden();
});

test('staff web surfaces never mint mail-audience image urls', function () {
    $html = file_get_contents(resource_path('views/livewire/shop/order-detail.blade.php'))
        .file_get_contents(resource_path('views/shop/partials/quote-enquiry-lines.blade.php'));

    expect($html)->not->toContain("'audience' => 'mail'")
        ->and($html)->not->toContain("?? 'mail'")
        ->and(file_get_contents(resource_path('views/mail/site-enquiry-received.blade.php')))->toContain("'mailAudience' => 'mail'");
});

test('a jpeg upload is re-encoded so trailing polyglot bytes and comment markers do not survive', function () {
    ['site' => $site, 'variant' => $variant] = personalisationImageSite('polyglot.example');
    $path = tempnam(sys_get_temp_dir(), 'lppoly');
    $marker = '<script>window.__polyglot=1</script>';
    file_put_contents($path, personalisationTestJpegBytes(80, 80).$marker);
    $file = new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);

    test()->withCookie(CartController::COOKIE_NAME, 'poly-session')
        ->post('http://polyglot.example/shop/cart/add', [
            'product_slug' => 'item',
            'variant_id' => $variant->id,
            'qty' => 1,
            'personalisation' => ['message' => 'Hi', 'photo' => [$file]],
        ])
        ->assertRedirect('http://polyglot.example/shop/cart');

    $stored = Storage::disk(config('filesystems.default'))->get(CartItem::first()->personalisation['photo']['value'][0]['path']);

    expect($stored)->toStartWith("\xFF\xD8")
        ->and(str_ends_with(rtrim($stored, "\x00"), "\xFF\xD9"))->toBeTrue()
        ->and($stored)->not->toContain($marker);
});
