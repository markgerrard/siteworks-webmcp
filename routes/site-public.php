<?php

use App\Http\Controllers\PreviewController;
use Illuminate\Support\Facades\Route;

// Public preview routes - no auth, no Boost browser logger.
// Served from the public surface (siteworks-<env>-site-public).
Route::withoutMiddleware(\Laravel\Boost\Middleware\InjectBoost::class)->group(function () {
    Route::get('/preview/{slug}', [PreviewController::class, 'show'])->name('preview.show');
    Route::get('/preview/{slug}/{page}', [PreviewController::class, 'show'])->name('preview.page')
        ->where('page', '[a-z0-9-]+(?:/[a-z0-9-]+){0,3}');
});

// Demo-only between-takes reset (DEMO_MODE + DEMO_RESET_TOKEN, else 404).
Route::get('/demo/reset', \App\Http\Controllers\Demo\DemoResetController::class)
    ->middleware('throttle:10,1')
    ->name('demo.reset');

// Native on-site review submission (flag-gated; 404 while dark).
// Same host-resolution + throttle posture as the other public-edit routes.
Route::post('/reviews', \App\Http\Controllers\Site\SiteReviewSubmitController::class)
    ->middleware('throttle:site-reviews')
    ->name('site.reviews.submit');

// Quote/contact form submission (lead_form + contact_form sections).
// Stores always; emails the owner only when the site has an
// enquiry_notification_email configured.
Route::post('/enquiries', \App\Http\Controllers\Site\SiteEnquirySubmitController::class)
    ->middleware('throttle:site-enquiries')
    ->name('site.enquiries.submit');

// Same-origin edit endpoints served from the public preview/custom host.
// DORMANT SURFACE — nothing in the product reaches these editing endpoints:
// the on-domain editor was superseded by the iframe shell, and its client
// half silently discards every edit. The one exception is /_edit/view-live
// below, which every "View site" link in the app depends on. Do not delete
// that one.
//
// Authentication is provided by the edit_session cookie (EditSessionAuth
// middleware). These endpoints stay same-origin with the public site for
// now; a future change may move the editor preview to a separate origin
// and tighten the iframe sandbox + CSP.
Route::middleware(['web', \App\Http\Middleware\EditSessionAuth::class])->prefix('_edit')->group(function () {
    Route::post('fields/{page}', \App\Http\Controllers\Site\PublicEditFieldController::class)
        ->name('site.public-edit.field-update');
    Route::post('publish', \App\Http\Controllers\Site\PublicEditPublishController::class)
        ->name('site.public-edit.publish');
    Route::get('publish-summary', \App\Http\Controllers\Site\PublicEditPublishSummaryController::class)
        ->name('site.public-edit.publish-summary');
    Route::post('discard-all', \App\Http\Controllers\Site\PublicEditDiscardAllController::class)
        ->name('site.public-edit.discard-all');
    Route::post('media', \App\Http\Controllers\Site\PublicEditMediaUploadController::class)
        ->name('site.public-edit.media-upload');
    Route::post('exit', \App\Http\Controllers\Site\PublicEditExitController::class)
        ->name('site.public-edit.exit');
});

// GET endpoint to jump OUT of edit mode and land on the live public site.
Route::get('/_edit/view-live', [\App\Http\Controllers\Site\PublicEditExitController::class, 'viewLive'])
    ->middleware('web')
    ->name('site.public-edit.view-live');

// New versioned public site routes, behind a `__site_v2` prefix while the
// versioned renderer is being rolled out; the prefix is removed at cutover.
Route::prefix('__site_v2')->withoutMiddleware(\Laravel\Boost\Middleware\InjectBoost::class)->group(function () {
    Route::get('/', [\App\Http\Controllers\Site\PublicSiteController::class, 'home']);
    Route::get('/{slug}', [\App\Http\Controllers\Site\PublicSiteController::class, 'page'])
        ->where('slug', '[a-z0-9-]+(?:/[a-z0-9-]+){0,3}');
});

// Public shop routes — resolved by custom/preview domain via ShopDomainResolver
// middleware. Customer-facing surface served from site-public-app, same as the
// rest of the live customer site. ShopDomainResolver short-circuits when the
// host doesn't map to a Shop site, so the routes are inert on non-shop sites.
Route::middleware('shop.domain')->group(function () {
    Route::get('/enquire', [\App\Http\Controllers\Shop\EnquireController::class, 'show'])->name('shop.enquire');
    Route::get('/shop', [\App\Http\Controllers\Shop\ShopController::class, 'index'])->name('shop.index');
    Route::get('/products/{slug}', [\App\Http\Controllers\Shop\ProductController::class, 'show'])->name('shop.product');
    Route::get('/products/{slug}/review', [\App\Http\Controllers\Shop\ProductReviewController::class, 'create'])->name('shop.product.review');
    Route::post('/products/{slug}/reviews', [\App\Http\Controllers\Shop\ProductReviewController::class, 'store'])
        ->middleware('throttle:shop-product-reviews')
        ->name('shop.product.reviews.store');
    Route::get('/collections/{path}', [\App\Http\Controllers\Shop\ShopController::class, 'category'])
        ->where('path', '[a-z0-9-/]+')
        ->name('shop.category');
    Route::get('/shop/p/{slug}', function (\Illuminate\Http\Request $request, string $slug) {
        abort_if(\App\Support\Shop\ShopUrls::isReservedSlug($slug), 404);
        $site = $request->attributes->get('resolved_site');
        $target = $slug;
        if ($site) {
            $target = \App\Support\Shop\ShopSlug::currentProductSlug($site->id, $slug);
        }
        abort_if(\App\Support\Shop\ShopUrls::isReservedSlug($target), 404);

        $query = $request->getQueryString();

        return redirect(\App\Support\Shop\ShopUrls::product($target).($query ? '?'.$query : ''), 301);
    });
    Route::get('/shop/c/{path}', function (\Illuminate\Http\Request $request, string $path) {
        abort_if(\App\Support\Shop\ShopUrls::isReservedPath($path), 404);

        $query = $request->getQueryString();

        return redirect(\App\Support\Shop\ShopUrls::collection($path).($query ? '?'.$query : ''), 301);
    })->where('path', '[a-z0-9-/]+');
    Route::get('/shop/search', \App\Http\Controllers\Shop\SearchController::class)->name('shop.search');

    // Cart routes
    Route::get('/shop/fulfilment/check', [\App\Http\Controllers\Shop\FulfilmentController::class, 'check'])->name('shop.fulfilment.check');

    Route::get('/shop/cart', [\App\Http\Controllers\Shop\CartController::class, 'show'])->name('shop.cart');
    Route::post('/shop/cart/add', [\App\Http\Controllers\Shop\CartController::class, 'add']);
    Route::patch('/shop/cart/{itemId}', [\App\Http\Controllers\Shop\CartController::class, 'update']);
    Route::patch('/shop/cart/{itemId}/personalisation', [\App\Http\Controllers\Shop\CartController::class, 'updatePersonalisation']);
    Route::delete('/shop/cart/{itemId}', [\App\Http\Controllers\Shop\CartController::class, 'remove']);
    Route::get('/shop/quote', [\App\Http\Controllers\Shop\QuoteController::class, 'show'])->name('shop.quote');
    Route::post('/shop/quote', [\App\Http\Controllers\Shop\QuoteController::class, 'submit'])
        ->middleware('throttle:site-quote-requests')
        ->name('shop.quote.submit');
    Route::get('/shop/quote/sent', [\App\Http\Controllers\Shop\QuoteController::class, 'sent'])->name('shop.quote.sent');

    // Checkout entry needs something to sell.
    Route::get('/shop/checkout', [\App\Http\Controllers\Shop\CheckoutController::class, 'show'])->name('shop.checkout');
    Route::post('/shop/checkout/start', [\App\Http\Controllers\Shop\CheckoutController::class, 'start']);

});

// Surfaces an existing customer is owed AFTER paying. Gated on the site being a shop at
// all, NOT on it having stock right now: a merchant archiving their last product must not
// 404 the return URL of a shopper who has just been charged, nor lock existing customers
// out of their own order history.
Route::middleware('shop.domain:established')->group(function () {
    Route::get('/shop/checkout/success', [\App\Http\Controllers\Shop\CheckoutController::class, 'success']);
    Route::get('/shop/checkout/cancel', [\App\Http\Controllers\Shop\CheckoutController::class, 'cancel']);

    // Customer auth routes
    Route::get('/shop/account/login', [\App\Http\Controllers\Shop\CustomerAuthController::class, 'loginForm'])->name('shop.account.login');
    Route::post('/shop/account/login', [\App\Http\Controllers\Shop\CustomerAuthController::class, 'requestLink'])
        ->middleware('throttle:shop-account-login');
    Route::get('/shop/account/verify', [\App\Http\Controllers\Shop\CustomerAuthController::class, 'verify'])->name('shop.account.verify');
    Route::post('/shop/account/logout', [\App\Http\Controllers\Shop\CustomerAuthController::class, 'logout']);

    // Customer account routes (auth required)
    Route::middleware('auth:customer')->group(function () {
        Route::get('/shop/account', [\App\Http\Controllers\Shop\AccountController::class, 'index'])->name('shop.account');
        Route::get('/shop/account/orders', [\App\Http\Controllers\Shop\AccountController::class, 'orders'])->name('shop.account.orders');
        Route::get('/shop/account/orders/{orderId}', [\App\Http\Controllers\Shop\AccountController::class, 'order'])->name('shop.account.order');
        Route::get('/shop/account/enquiries', [\App\Http\Controllers\Shop\AccountController::class, 'enquiries'])->name('shop.account.enquiries');
        Route::get('/shop/account/settings', function () {
            return view('shop.account.settings', ['site' => request()->attributes->get('resolved_site')]);
        })->name('shop.account.settings');

        Route::get('/shop/account/addresses', [\App\Http\Controllers\Shop\CustomerAddressController::class, 'index'])
            ->name('shop.account.addresses');
        Route::middleware('throttle:shop-account-write')->group(function () {
            Route::post('/shop/account/addresses', [\App\Http\Controllers\Shop\CustomerAddressController::class, 'store']);
            Route::post('/shop/account/addresses/{id}', [\App\Http\Controllers\Shop\CustomerAddressController::class, 'update'])
                ->whereNumber('id');
            Route::post('/shop/account/addresses/{id}/delete', [\App\Http\Controllers\Shop\CustomerAddressController::class, 'destroy'])
                ->whereNumber('id');
            Route::post('/shop/account/addresses/{id}/default/{kind}', [\App\Http\Controllers\Shop\CustomerAddressController::class, 'setDefault'])
                ->whereNumber('id')
                ->whereIn('kind', ['shipping', 'billing']);
        });
    });

    // Data export download (signed URL)
    Route::get('/shop/account/download-export', function (\Illuminate\Http\Request $request) {
        abort_unless($request->hasValidSignature(), 403);
        $path = storage_path('app/exports/'.ltrim($request->query('filename'), '/'));
        abort_unless(file_exists($path), 404);

        return response()->download($path);
    })->name('shop.account.download-export');

    // Claim route (signed URL from receipt email)
    Route::get('/shop/account/claim', function (\Illuminate\Http\Request $request, \App\Services\Shop\CustomerAuthService $svc) {
        abort_unless($request->hasValidSignature(), 403);
        $site = $request->attributes->get('resolved_site');
        abort_unless($site, 404);

        $email = (string) $request->query('email');
        try {
            $svc->requestLinkFor($site->id, $email, $request->ip());
        } catch (\App\Exceptions\Shop\CustomerDeletedException $e) {
            // silently ignore — don't reveal deletion status
        }

        return view('shop.account.claim-sent', ['site' => $site, 'email' => $email]);
    })->name('shop.account.claim');
});

// Stripe webhook — outside shop.domain group, CSRF excluded in bootstrap/app.php.
Route::post('/shop/webhook/stripe', \App\Http\Controllers\Shop\StripeWebhookController::class)
    ->name('shop.webhook.stripe');

// Public hosts: ResolvePreviewHost passthroughs sitemap.xml to this route.
// Strip session/cookie middleware so crawler hits stay cacheable (no
// Set-Cookie) and do not mint a DB session row per request.
$withoutSessionCookies = [
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    // CSRF cookie write calls $request->session() — must drop with StartSession.
    \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
    \Laravel\Boost\Middleware\InjectBoost::class,
];

Route::get('/sitemap.xml', \App\Http\Controllers\Site\SitemapController::class)
    ->withoutMiddleware($withoutSessionCookies)
    ->name('site.sitemap');

// Public hosts: same passthrough + host/version gates as sitemap.xml.
Route::get('/robots.txt', \App\Http\Controllers\Site\RobotsController::class)
    ->withoutMiddleware($withoutSessionCookies)
    ->name('site.robots');
