<?php

namespace App\Http\Controllers\Shop;

use App\Models\Shop\Category;
use App\Services\Shop\CatalogueListing;
use App\Services\Shop\CategoryContentRenderer;
use App\Services\Shop\RenderContext;
use App\Services\Shop\SnapshotReader;
use App\Support\Shop\ShopUrls;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ShopController
{
    public function __construct(
        protected SnapshotReader $reader,
    ) {}

    public function index(Request $request)
    {
        $site = $request->attributes->get('resolved_site');
        abort_unless($site, 404);

        $json = $this->reader->forSite($site->id);
        if ($json === null) {
            // A shop-enabled site with no published snapshot yet (e.g. a fresh
            // clone) renders the storefront empty state rather than a 404.
            $json = ['categories' => [], 'products' => []];
        }

        $ctx = RenderContext::fromRequest($request, $request->attributes->getBoolean('is_preview_host'));
        $json = $ctx->filterSnapshot($json);

        $indexHasCategories = false;
        foreach ($json['categories'] ?? [] as $cat) {
            $catSlugs = array_values(array_filter($cat['product_slugs'] ?? []));
            if ($catSlugs !== [] && ($cat['visibility'] ?? 'visible') === 'visible') {
                $indexHasCategories = true;
                break;
            }
        }

        $listing = null;
        if (! $indexHasCategories) {
            $products = [];
            foreach ($json['products'] ?? [] as $product) {
                if (is_array($product)) {
                    $products[] = $product;
                }
            }
            $listing = CatalogueListing::apply(
                $products,
                Arr::only($request->query(), ['sort', 'page']),
                [],
                self::sitePageSize($site),
                self::siteDefaultSort($site),
            );
        }

        return view('shop.index', [
            'site' => $site,
            'snapshot' => $json,
            'listing' => $listing,
            'indexHasCategories' => $indexHasCategories,
        ]);
    }

    public function category(Request $request, string $path, CategoryContentRenderer $contentRenderer)
    {
        abort_if(ShopUrls::isReservedPath($path), 404);

        $site = $request->attributes->get('resolved_site');
        abort_unless($site, 404);

        $json = $this->reader->forSite($site->id);
        abort_unless($json, 404);

        $ctx = RenderContext::fromRequest($request, $request->attributes->getBoolean('is_preview_host'));
        $json = $ctx->filterSnapshot($json);

        $slug = $json['category_paths'][$path] ?? $path;
        $category = $json['categories'][$slug] ?? null;
        abort_unless($category, 404);

        $content = Category::query()
            ->where('site_id', $site->id)
            ->whereKey($category['id'] ?? null)
            ->first();
        $categoryContent = $content === null ? [] : [
            'description_long' => $contentRenderer->render($content),
            'faqs' => $content->faqs ?? [],
            'meta_title' => $content->meta_title,
            'meta_description' => $content->meta_description,
        ];

        $products = array_values(array_filter(array_map(
            fn ($s) => $json['products'][$s] ?? null,
            $category['product_slugs'] ?? []
        )));

        $listing = CatalogueListing::apply(
            $products,
            $request->query(),
            is_array($category['facets'] ?? null) ? $category['facets'] : [],
            self::sitePageSize($site),
            self::siteDefaultSort($site),
        );

        $children = [];
        foreach ($category['children'] ?? [] as $childSlug) {
            $child = $json['categories'][$childSlug] ?? null;
            if (is_array($child)) {
                $children[] = $child;
            }
        }

        return view('shop.category', [
            'site' => $site,
            'category' => $category,
            'snapshot' => $json,
            'products' => $listing['products'],
            'children' => $children,
            'listing' => $listing,
            'categoryContent' => $categoryContent,
        ]);
    }

    private static function sitePageSize(mixed $site): ?int
    {
        $value = $site->shop_page_size ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private static function siteDefaultSort(mixed $site): ?string
    {
        $value = $site->shop_default_sort ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
