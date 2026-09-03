<?php

namespace App\Http\Controllers\Shop;

use App\Services\Shop\RenderContext;
use App\Services\Shop\SnapshotReader;
use App\Support\Shop\ProductReviews;
use App\Support\Shop\ProductReviewSettings;
use App\Support\Shop\ShopSlug;
use App\Support\Shop\ShopUrls;
use Illuminate\Http\Request;

class ProductController
{
    public function __construct(protected SnapshotReader $reader) {}

    public function show(Request $request, string $slug)
    {
        abort_if(ShopUrls::isReservedSlug($slug), 404);

        $site = $request->attributes->get('resolved_site');
        abort_unless($site, 404);

        $json = $this->reader->forSite($site->id);
        abort_unless($json, 404);

        $ctx = RenderContext::fromRequest($request, $request->attributes->getBoolean('is_preview_host'));
        $json = $ctx->filterSnapshot($json);

        $product = $json['products'][$slug] ?? null;
        if ($product === null) {
            $current = ShopSlug::currentProductSlug($site->id, $slug);
            abort_if($current === $slug, 404);
            $query = $request->getQueryString();

            return redirect(ShopUrls::product($current).($query ? '?'.$query : ''), 301);
        }

        $reviewSettings = ProductReviewSettings::fromSite($site);
        $productReviews = null;
        $reviewDistribution = [];
        if ($reviewSettings->enabled) {
            $productReviews = ProductReviews::publishedPage((int) $site->id, (int) $product['id']);
            $reviewDistribution = ProductReviews::distribution((int) $site->id, (int) $product['id']);
        }

        return view('shop.product', [
            'site' => $site,
            'product' => $product,
            'currency' => $json['meta']['currency'] ?? ($site->shop_currency ?? 'GBP'),
            'reviewSettings' => $reviewSettings,
            'productReviews' => $productReviews,
            'reviewDistribution' => $reviewDistribution,
        ]);
    }
}
