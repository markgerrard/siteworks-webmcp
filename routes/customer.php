<?php

use App\Enums\LogoAssetVariant;
use App\Http\Controllers\Client\PortalController;
use App\Http\Controllers\Site\LogoAssetDownloadController;
use App\Http\Controllers\Site\ShopProductsExportController;
use App\Http\Controllers\Site\ShopProductsExportDownloadController;
use App\Support\AuthLanding;
use Illuminate\Support\Facades\Route;

Route::domain(config('domains.customer_domain'))->group(function () {
    Route::get('/', function () {
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        return redirect()->to(AuthLanding::for(auth()->user()));
    })->name('home');

    Route::middleware(['auth', 'verified', 'client.only'])->group(function () {
        Route::view('account', 'client.account')->name('client.account');
        Route::view('team', 'client.team')->name('client.team');

        Route::get('portal', [PortalController::class, 'landing'])->name('client.portal.landing');
        Route::get('sites', [PortalController::class, 'sitesIndex'])->name('client.portal.sites');
        // The site's canonical URL serves Pages — Overview was redundant
        // (the sidebar already shows status + the View/Edit CTAs that
        // were Overview's main reason to exist). Removed.
        Route::get('sites/{site}', [PortalController::class, 'pages'])->name('client.portal.site');
        Route::get('sites/{site}/design', [PortalController::class, 'design'])->name('client.portal.design');
        Route::get('sites/{site}/navigation', [PortalController::class, 'navigation'])->name('client.portal.navigation');
        Route::get('sites/{site}/chatbot', [PortalController::class, 'chatbot'])->name('client.portal.chatbot');
        Route::get('sites/{site}/history', [PortalController::class, 'history'])->name('client.portal.history');
        Route::get('sites/{site}/reviews', [PortalController::class, 'reviews'])->name('client.portal.reviews');
        Route::get('sites/{site}/enquiries', [PortalController::class, 'enquiries'])->name('client.portal.enquiries');
        Route::get('sites/{site}/products/categories', [PortalController::class, 'shopCategories'])->name('client.portal.shop.categories');
        Route::get('sites/{site}/products/export', ShopProductsExportController::class)->name('client.portal.shop.products.export');
        // Target of the client-side export_products (WebMCP) signed URL. Domain-scoped
        // to the customer host so the minted URL lands on app.siteworks (not the staff
        // agents origin behind CF Access). `signed` proves the mint; the controller still
        // authorises via SitePolicy `view` for the acting client.
        Route::get('sites/{site}/products/export-download', ShopProductsExportDownloadController::class)
            ->middleware('signed')
            ->name('client.portal.shop.products.export-download');
        Route::get('sites/{site}/logo/{variant}/download', LogoAssetDownloadController::class)
            ->middleware('signed')
            ->whereIn('variant', LogoAssetVariant::values())
            ->name('client.portal.logo.download');
        Route::get('sites/{site}/products', [PortalController::class, 'shopProducts'])->name('client.portal.shop.products');
        Route::get('sites/{site}/products/reviews', [PortalController::class, 'shopReviews'])->name('client.portal.shop.reviews');
        Route::get('sites/{site}/storefront', [PortalController::class, 'shopStorefront'])->name('client.portal.shop.storefront');
        Route::get('sites/{site}/shop', [PortalController::class, 'shop'])->name('client.portal.shop');
        Route::get('sites/{site}/shop/products/{product}/edit', [PortalController::class, 'shopProductEdit'])->name('client.portal.shop.products.edit');
        Route::get('sites/{site}/orders', [PortalController::class, 'orders'])->name('client.portal.orders');
        Route::get('sites/{site}/orders/{order}', [PortalController::class, 'order'])->name('client.portal.orders.show');
        Route::get('sites/{site}/business-info', [PortalController::class, 'businessInfo'])->name('client.portal.business-info');

    });
});
