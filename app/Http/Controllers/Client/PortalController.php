<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PortalController extends Controller
{
    public function landing(Request $request): View|RedirectResponse
    {
        $sites = $request->user()->accessibleSites()->get();

        if ($sites->count() === 1) {
            return redirect()->route('client.portal.site', $sites->first());
        }

        if ($sites->count() > 1) {
            return redirect()->route('client.portal.sites');
        }

        return view('client.portal.landing');
    }

    public function sitesIndex(Request $request): View
    {
        return view('client.portal.sites-index', [
            'sites' => $request->user()->accessibleSites()->orderBy('business_name')->get(),
        ]);
    }

    public function pages(Site $site): View
    {
        $this->authorize('view', $site);

        return view('client.portal.pages', ['site' => $site]);
    }

    public function design(Site $site): View
    {
        $this->authorize('view', $site);

        return view('client.portal.design', ['site' => $site]);
    }

    public function navigation(Site $site): View
    {
        $this->authorize('view', $site);

        return view('client.portal.navigation', ['site' => $site]);
    }

    public function chatbot(Site $site): View
    {
        $this->authorize('view', $site);

        return view('client.portal.chatbot', ['site' => $site]);
    }

    public function history(Site $site): View
    {
        abort_unless(config('site.use_versioned_renderer'), 404);
        $this->authorize('view', $site);

        return view('client.portal.history', ['site' => $site]);
    }

    public function reviews(Site $site): View
    {
        // Authorize BEFORE the per-site feature gate: gating first turns
        // native_reviews_enabled into a cross-tenant oracle (404 when a
        // foreign site has reviews off, 403 when on).
        $this->authorize('view', $site);
        abort_unless(config('site.native_reviews_enabled') && $site->native_reviews_enabled, 404);

        return view('client.portal.reviews', ['site' => $site]);
    }

    public function enquiries(Site $site): View
    {
        $this->authorize('view', $site);

        return view('client.portal.enquiries', ['site' => $site]);
    }

    public function shop(Site $site): RedirectResponse
    {
        $this->authorize('view', $site);
        abort_unless($site->portalShopReachable(), 404);

        return redirect()->route('client.portal.shop.products', $site);
    }

    public function shopProducts(Site $site): View
    {
        $this->authorize('view', $site);
        abort_unless($site->portalShopReachable(), 404);

        return view('client.portal.shop-products', ['site' => $site]);
    }

    public function shopStorefront(Site $site): View
    {
        $this->authorize('view', $site);
        abort_unless($site->portalShopReachable(), 404);

        return view('client.portal.shop-storefront', ['site' => $site]);
    }

    public function shopCategories(Site $site): View
    {
        $this->authorize('view', $site);
        abort_unless($site->portalShopReachable(), 404);

        return view('client.portal.shop-categories', ['site' => $site]);
    }

    public function shopReviews(Site $site): View
    {
        $this->authorize('view', $site);
        abort_unless($site->portalShopReachable(), 404);

        return view('client.portal.shop-reviews', ['site' => $site]);
    }

    public function shopProductEdit(Site $site, $product): View
    {
        $this->authorize('view', $site);
        abort_unless($site->portalShopReachable(), 404);

        $product = Product::query()
            ->where('site_id', $site->id)
            ->findOrFail($product);

        return view('client.portal.shop-product-edit', [
            'site' => $site,
            'product' => $product,
        ]);
    }

    public function orders(Site $site): View
    {
        $this->authorize('view', $site);
        abort_unless($site->hasEstablishedShop(), 404); // owed after payment: reachable with orders, flag or not
        abort_unless($site->shopShowsAccountOrders(), 404);

        return view('client.portal.orders', ['site' => $site]);
    }

    public function order(Site $site, $order): View
    {
        $this->authorize('view', $site);
        abort_unless($site->hasEstablishedShop(), 404); // owed after payment: reachable with orders, flag or not
        abort_unless($site->shopShowsAccountOrders(), 404);

        $order = Order::query()
            ->where('site_id', $site->id)
            ->findOrFail($order);

        return view('client.portal.order', [
            'site' => $site,
            'order' => $order,
        ]);
    }

    public function businessInfo(Site $site): View
    {
        $this->authorize('view', $site);

        return view('client.portal.business-info', ['site' => $site]);
    }

}
