<?php

namespace App\Http\Controllers\Shop;

use App\Models\Shop\Product;
use App\Models\Site;
use App\Services\Shop\ProductSearchService;
use App\Services\Shop\RenderContext;
use App\Support\ShopMoney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class SearchController
{
    public function __construct(protected ProductSearchService $searcher) {}

    public function __invoke(Request $request): View|JsonResponse
    {
        $site = $request->attributes->get('resolved_site');
        abort_unless($site, 404);

        $query = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $products = [];
        if ($query !== '') {
            $ctx = RenderContext::fromRequest($request, $request->attributes->getBoolean('is_preview_host'));
            $products = $this->searcher->search($site->id, $query, includeDrafts: $ctx->includeDrafts);
        }

        if ($request->expectsJson()) {
            return $this->json($site, $query, $products instanceof Collection ? $products : collect());
        }

        return view('shop.search', [
            'site' => $site,
            'query' => $query,
            'products' => $products,
        ]);
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    private function json(Site $site, string $query, Collection $products): JsonResponse
    {
        if ($products->isNotEmpty()) {
            $products->load(['variants', 'images']);
        }
        $currency = $site->shop_currency ?? 'GBP';

        $results = $products->take(5)->map(function (Product $product) use ($currency): array {
            $cents = (int) ($product->variants->first()?->price_cents ?? 0);

            return [
                'name' => $product->name,
                'slug' => $product->slug,
                'url' => \App\Support\Shop\ShopUrls::product($product),
                'price_display' => ShopMoney::display($cents, $currency, (bool) $product->price_from),
                'image_url' => $product->images->first()?->url('thumb'),
            ];
        })->values();

        return response()->json([
            'query' => $query,
            'count' => $products->count(),
            'results' => $results,
            'see_all_url' => route('shop.search', ['q' => $query]),
        ]);
    }
}
