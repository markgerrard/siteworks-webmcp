<?php

use App\Enums\Shop\ProductStatus;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Shop\ProductImage;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopDraft;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Models\Site\EditorOperationLog;
use App\Models\SiteMedia;
use App\Services\Shop\ProductSearchService;
use App\Services\Site\Editor\Operations\ShopSetProductImageOperation;
use Illuminate\Support\Facades\Bus;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
});

/**
 * @return array{0: \App\Models\User, 1: Site, 2: Product, 3: SiteMedia}
 */
function setProductImageFixture(): array
{
    [$actor, $site, $category] = CommerceReads::shopSite();
    $product = Product::factory()->for($site)->create([
        'name' => 'Imaged Candle',
        'slug' => 'imaged-candle',
    ]);
    $product->categories()->attach($category->id, ['is_primary' => true]);
    ProductVariant::factory()->for($product)->create(['sku' => 'IMG-1', 'price_cents' => 999]);
    $media = SiteMedia::factory()->for($site)->create([
        's3_key' => 'shop/products/imaged-candle.png',
        'alt_text' => 'Media alt',
    ]);

    return [$actor, $site, $product, $media];
}

it('returns not_found for a foreign-site media_id and writes nothing', function () {
    [$actor, $site, $product] = setProductImageFixture();
    $foreign = SiteMedia::factory()->create(['s3_key' => 'foreign.png']);
    Bus::fake();

    $result = CommerceReads::run($actor, $site, 'set_product_image', [
        'catalogue_revision' => 0,
        'slug' => 'imaged-candle',
        'product_revision' => (int) $product->revision,
        'media_id' => $foreign->id,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('not_found')
        ->and(ProductImage::query()->where('product_id', $product->id)->exists())->toBeFalse();
    Bus::assertNothingDispatched();
});

it('rejects a site_media row with a null s3_key as validation', function () {
    [$actor, $site, $product] = setProductImageFixture();
    $legacy = SiteMedia::factory()->for($site)->create(['s3_key' => null, 'url' => 'https://cdn.test/legacy.jpg']);

    $result = CommerceReads::run($actor, $site, 'set_product_image', [
        'catalogue_revision' => 0,
        'slug' => 'imaged-candle',
        'product_revision' => (int) $product->revision,
        'media_id' => $legacy->id,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and(ProductImage::query()->where('product_id', $product->id)->exists())->toBeFalse();
});

it('writes shop_product_images.path from site_media.s3_key and falls alt back to alt_text', function () {
    [$actor, $site, $product, $media] = setProductImageFixture();

    $result = CommerceReads::run($actor, $site, 'set_product_image', [
        'catalogue_revision' => 0,
        'product_id' => $product->id,
        'product_revision' => (int) $product->revision,
        'media_id' => $media->id,
        'sort_order' => 2,
    ]);

    $image = ProductImage::query()->where('product_id', $product->id)->first();
    $receipt = $result->toArray()['receipt'];

    expect($result->ok)->toBeTrue()
        ->and($image->path)->toBe($media->s3_key)
        ->and($image->alt)->toBe('Media alt')
        ->and((int) $image->sort_order)->toBe(2)
        ->and($receipt['changed'][0])->toMatchArray([
            'scope' => 'product',
            'product_slug' => 'imaged-candle',
            'path' => 'images.2',
            'kind' => 'insert',
            'after' => ['media_id' => $media->id],
        ])
        ->and($receipt['new_revision'])->toBe((int) ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision'))
        ->and($result->data['revision'])->toBe((int) $product->fresh()->revision)
        ->and($receipt['publishable'])->toBeFalse()
        ->and(json_encode($result->toArray()))->not->toContain('data:image');
});

it('holds the drafts-law quintet and never projects the imaged draft publicly', function () {
    [$actor, $site, $product, $media] = setProductImageFixture();
    $published = Product::factory()->for($site)->published()->create(['slug' => 'live-candle', 'name' => 'Live Candle']);
    $published->categories()->attach(Category::query()->where('site_id', $site->id)->value('id'), ['is_primary' => true]);
    CommerceReads::drainRebuild($site->id);

    $statusesBefore = Product::query()->where('site_id', $site->id)->pluck('status', 'id')->all();
    $projectionBefore = CommerceReads::publicProjection($site);
    $pointerBefore = $projectionBefore['_pointer_version'];
    unset($projectionBefore['_pointer_version']);
    $tablesBefore = CommerceReads::commerceSideTableCounts();
    $stockBefore = VariantStock::query()->count();

    $result = CommerceReads::run($actor, $site, 'set_product_image', [
        'catalogue_revision' => 0,
        'slug' => 'imaged-candle',
        'product_revision' => (int) $product->revision,
        'media_id' => $media->id,
    ]);
    CommerceReads::drainRebuild($site->id);
    $projectionAfter = CommerceReads::publicProjection($site);
    $pointerAfter = $projectionAfter['_pointer_version'];
    unset($projectionAfter['_pointer_version']);

    expect($result->ok)->toBeTrue()
        ->and($product->fresh()->status)->toBe(ProductStatus::Draft)
        ->and(Product::query()->where('site_id', $site->id)->whereIn('id', array_keys($statusesBefore))->pluck('status', 'id')->all())->toBe($statusesBefore)
        ->and($projectionAfter)->toBe($projectionBefore)
        ->and($pointerAfter)->toBeGreaterThanOrEqual($pointerBefore)
        ->and(CommerceReads::commerceSideTableCounts())->toBe($tablesBefore)
        ->and(VariantStock::query()->count())->toBe($stockBefore)
        ->and($projectionAfter['products'])->not->toHaveKey($product->slug)
        ->and(app(ProductSearchService::class)->search($site->id, 'Imaged Candle', false, 10)->modelKeys())->not->toContain($product->id);
});

it('refuses a published product', function () {
    [$actor, $site, $product, $media] = setProductImageFixture();
    $product->update(['status' => ProductStatus::Published]);

    $result = CommerceReads::run($actor, $site, 'set_product_image', [
        'catalogue_revision' => 0,
        'slug' => 'imaged-candle',
        'product_revision' => (int) $product->revision,
        'media_id' => $media->id,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('published_product_immutable')
        ->and(ProductImage::query()->where('product_id', $product->id)->exists())->toBeFalse();
});

it('refuses a 21st image and a data_base64 or primary payload key', function () {
    [$actor, $site, $product, $media] = setProductImageFixture();
    for ($i = 0; $i < 20; $i++) {
        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => "shop/products/{$i}.png",
            'sort_order' => $i,
        ]);
    }

    $tooMany = CommerceReads::run($actor, $site, 'set_product_image', [
        'catalogue_revision' => 0,
        'slug' => 'imaged-candle',
        'product_revision' => (int) $product->revision,
        'media_id' => $media->id,
    ]);
    $bytes = CommerceReads::run($actor, $site, 'set_product_image', [
        'catalogue_revision' => 0,
        'slug' => 'imaged-candle',
        'product_revision' => (int) $product->revision,
        'media_id' => $media->id,
        'data_base64' => 'aaaa',
    ]);
    $primary = CommerceReads::run($actor, $site, 'set_product_image', [
        'catalogue_revision' => 0,
        'slug' => 'imaged-candle',
        'product_revision' => (int) $product->revision,
        'media_id' => $media->id,
        'primary' => true,
    ]);

    expect($tooMany->error['code'])->toBe('validation')
        ->and($bytes->error['code'])->toBe('validation')
        ->and($primary->error['code'])->toBe('validation')
        ->and(ProductImage::query()->where('product_id', $product->id)->count())->toBe(20);
});

it('records subject_type product and subject_ref slug on a successful set_product_image audit row', function () {
    [$actor, $site, $product, $media] = setProductImageFixture();

    $result = CommerceReads::run($actor, $site, 'set_product_image', [
        'catalogue_revision' => 0,
        'slug' => 'imaged-candle',
        'product_revision' => (int) $product->revision,
        'media_id' => $media->id,
    ]);
    $row = EditorOperationLog::query()->where('site_id', $site->id)->where('operation', 'set_product_image')->latest('id')->first();

    expect($result->ok)->toBeTrue()
        ->and($row->result_code)->toBe('ok')
        ->and($row->subject_type)->toBe('product')
        ->and($row->subject_ref)->toBe('imaged-candle');
});

it('dispatches exactly one RebuildShopSnapshot after commit', function () {
    [$actor, $site, $product, $media] = setProductImageFixture();
    Bus::fake();

    $result = CommerceReads::run($actor, $site, 'set_product_image', [
        'catalogue_revision' => 0,
        'slug' => 'imaged-candle',
        'product_revision' => (int) $product->revision,
        'media_id' => $media->id,
        'alt' => 'Caller alt',
    ]);

    expect($result->ok)->toBeTrue()
        ->and(ProductImage::query()->where('product_id', $product->id)->value('alt'))->toBe('Caller alt');
    Bus::assertDispatchedTimes(RebuildShopSnapshot::class, 1);
});

it('is a shop-addressed unwrapped write', function () {
    $operation = app(ShopSetProductImageOperation::class);

    expect($operation->address())->toBe('shop')
        ->and($operation->readOnly())->toBeFalse()
        ->and($operation->wrapInAdminChange())->toBeFalse()
        ->and($operation->inputSchema()['properties'])->not->toHaveKey('data_base64')
        ->and($operation->inputSchema()['properties'])->not->toHaveKey('primary');
});
