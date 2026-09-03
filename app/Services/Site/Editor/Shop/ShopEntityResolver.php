<?php

namespace App\Services\Site\Editor\Shop;

use App\Models\Shop\Category;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopHeroVersion;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Models\SiteMedia;
use App\Services\Site\Editor\EditorState;
use App\Services\Site\Editor\OperationFailed;
use App\Services\Site\Editor\OperationResult;

final class ShopEntityResolver
{
    public function hasShop(Site $site): bool
    {
        if (! $site->shopEnabled()) {
            return false;
        }

        return ShopSnapshotCurrent::query()->where('site_id', $site->id)->exists()
            && Category::query()->where('site_id', $site->id)->exists();
    }

    public function requireShop(Site $site): void
    {
        if (! $this->hasShop($site)) {
            throw self::notFound($site);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function product(Site $site, array $input): Product
    {
        $product = null;

        if (array_key_exists('product_id', $input) && self::intOrNull($input['product_id']) !== null) {
            $product = Product::query()
                ->where('site_id', $site->id)
                ->find(self::intOrNull($input['product_id']));
        } elseif (is_string($input['slug'] ?? null) && $input['slug'] !== '') {
            $product = Product::query()
                ->where('site_id', $site->id)
                ->where('slug', $input['slug'])
                ->first();
        }

        return $product ?? throw self::notFound($site);
    }

    public function media(Site $site, int $mediaId): SiteMedia
    {
        return SiteMedia::query()
            ->where('site_id', $site->id)
            ->find($mediaId)
            ?? throw self::notFound($site);
    }

    public function heroVersion(Site $site, int $versionId): ShopHeroVersion
    {
        return ShopHeroVersion::query()
            ->where('site_id', $site->id)
            ->find($versionId)
            ?? throw self::notFound($site);
    }

    public function category(Site $site, string $slug): Category
    {
        return Category::query()
            ->where('site_id', $site->id)
            ->where('slug', $slug)
            ->first()
            ?? throw self::notFound($site);
    }

    public function order(Site $site, string $number): Order
    {
        return Order::query()
            ->where('site_id', $site->id)
            ->where('number', $number)
            ->first()
            ?? throw self::notFound($site);
    }

    public function variant(Product $product, string $sku): ProductVariant
    {
        return ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('sku', $sku)
            ->first()
            ?? throw self::notFound($product->site);
    }

    private static function notFound(Site $site): OperationFailed
    {
        return new OperationFailed(OperationResult::fail(
            'not_found',
            'Not found.',
            new EditorState(
                siteId: $site->id,
                pageId: null,
                draftRevisionId: null,
                compositionRevision: 0,
                pendingPublish: false,
            ),
        ));
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^(0|-?[1-9][0-9]*)$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
