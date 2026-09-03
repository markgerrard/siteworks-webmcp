<?php

namespace App\Http\Middleware;

use App\Models\Site;
use Closure;
use Illuminate\Http\Request;

class ShopDomainResolver
{
    public function handle(Request $request, Closure $next, string $requires = 'purchasable')
    {
        $host = $request->getHost();

        // Reuse site already resolved by ResolvePreviewHost (avoids a second DB hit).
        $site = $request->attributes->get('resolved_site');

        if (! $site) {
            // Fallback: resolve independently (e.g. in tests or contexts where
            // ResolvePreviewHost did not run). Mirror the active-status guard for
            // custom domains so a pending/inactive domain cannot reach shop routes.
            $site = Site::where('preview_domain', $host)
                ->orWhere(function ($q) use ($host) {
                    $q->where('custom_domain', $host)
                        ->where('custom_domain_status', 'active');
                })
                ->first();
        }

        if (! $site) {
            abort(404);
        }

        // Two gates, because sellability and shop IDENTITY are different questions.
        //
        //  purchasable — is there something to buy right now? Gates browse and cart.
        //                hasPurchasableShop() already includes shop_enabled, so a
        //                flag-off site cannot be browsed or checked out.
        //  established — is this a shop that has taken (or can take) orders? Gates the
        //                surfaces an existing customer is owed AFTER paying: checkout
        //                outcome, account, order history, magic-link claim, data export.
        //                Orders keep these reachable after disablement.
        //
        // The Stripe webhook is deliberately registered OUTSIDE this middleware group and
        // resolves its order from the session metadata, so it stays reachable: gating it
        // would drop payment notifications for a shop whose last product was unpublished
        // mid-flight, charging the customer and never completing the order.
        $allowed = $requires === 'established'
            ? $site->hasEstablishedShop()
            : $site->hasPurchasableShop();

        if (! $allowed) {
            // One PREVIEW-ONLY exception: the /shop landing of a shop-enabled site
            // with nothing purchasable yet (e.g. a fresh clone) renders the
            // storefront empty state instead of 404. Live custom domains keep the
            // gated 404 doctrine (nothing-to-sell browse must not serve), and every
            // other shop route stays gated on preview too.
            // Preview hosts carry the site's preview_domain as the full host or as
            // the first label (slug.<preview apex> style — same match ResolvePreviewHost
            // uses); a custom-domain hit is never treated as preview.
            $hostSlug = strtok($request->host(), '.');
            $isPreviewHost = $site->preview_domain !== null
                && $request->host() !== $site->custom_domain
                && in_array($site->preview_domain, [$request->host(), $hostSlug], true);

            $emptyShopLanding = $requires !== 'established'
                && $request->path() === 'shop'
                && $site->shopEnabled()
                && $isPreviewHost;

            if (! $emptyShopLanding) {
                abort(404);
            }
        }

        $mode = $site->shop_mode ?? 'cart';

        // Enquire has no cart. Quote keeps cart chrome. Neither sells through
        // checkout — but success/cancel stay up for a shopper who already paid
        // (owed-after-payment; same split as shop.enabled:established).
        if ($mode === 'enquire' && preg_match('#^shop/cart(/|$)#', $request->path())) {
            abort(404);
        }

        if (in_array($mode, ['enquire', 'quote'], true)) {
            if (preg_match('#^shop/checkout(/start)?$#', $request->path())) {
                abort(404);
            }
            // success/cancel are owed only to a shopper who paid before the mode changed.
            if (preg_match('#^shop/checkout/(success|cancel)$#', $request->path()) && ! $site->hasTakenOrders()) {
                abort(404);
            }
        }

        $request->attributes->set('resolved_site', $site);
        // A preview host renders drafts. The demo has no preview surface: the
        // host stored as the site's preview_domain IS its live shop, so it must
        // render like any public host — drafts stay hidden until a human publishes.
        $request->attributes->set('is_preview_host', ! config('demo.enabled') && $site->preview_domain === $host);

        // Attach Cache-Tag header for Cloudflare edge invalidation
        return tap($next($request), fn ($response) => $response->headers->set('Cache-Tag', "shop:{$site->id}"));
    }
}
