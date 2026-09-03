<?php

use App\Exceptions\Shop\CheckoutException;
use App\Http\Controllers\CspReportController;
use App\Http\Middleware\AgentsSecurityHeaders;
use App\Http\Middleware\CaptureAuthUserToContext;
use App\Http\Middleware\DemoPublicRequestUrl;
use App\Http\Middleware\EnsureAgentRole;
use App\Http\Middleware\EnsureClientUser;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureShopEnabled;
use App\Http\Middleware\RequireActingUser;
use App\Http\Middleware\ResolvePreviewHost;
use App\Http\Middleware\ScopeSessionToStorefrontHost;
use App\Http\Middleware\ShopDomainResolver;
use App\Http\Middleware\Testing\UseRequestAssetOrigin;
use App\Services\Site\EditSessionCookie;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->registered(function (Application $app): void {
        $app->make(Vite::class)->useBuildDirectory(
            match (env('SURFACE', 'all')) {
                'agents' => 'build-agents',
                'customer' => 'build-customer',
                'site-public' => 'build-site-public',
                'editor-preview' => 'build-editor-preview',
                default => 'build',
            }
        );
    })
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            $surface = config('surfaces.current', 'all');

            // Self-hosted font pairs. Registered on every surface because
            // public sites, the staff admin preview, and the editor-preview
            // iframe all render page.blade.php with a same-origin
            // /fonts/{display}+{body}.css link.
            Route::get('/fonts/{display}+{body}.css', \App\Http\Controllers\Site\FontPairCssController::class)
                ->where(['display' => '[a-z0-9-]+', 'body' => '[a-z0-9-]+'])
                ->name('site.fonts.pair');

            if (in_array($surface, ['all', 'agents'], true)) {
                // Browser report-uri POSTs are unauthenticated and must not mint a session per report.
                Route::domain(config('domains.agent_domain'))
                    ->post('/csp-report', CspReportController::class)
                    ->middleware('throttle:csp-report')
                    ->name('csp.report');

                // NOTE: routes/agents.php (staff control-panel) was removed for the
                // OSS demo strip — the agents surface no longer registers any routes.
            }

            if (in_array($surface, ['all', 'customer'], true)) {
                Route::middleware('web')->group(base_path('routes/customer.php'));
            }

            if (in_array($surface, ['all', 'site-public'], true)) {
                Route::middleware('web')->group(base_path('routes/site-public.php'));
            }

            // Personalisation files are linked from shopper pages, agent
            // fulfilment views, client enquiries, and queued mail. Register
            // the signed endpoint on every surface that renders one of those
            // views; the controller applies the site/session authorization.
            if (in_array($surface, ['all', 'agents', 'customer', 'site-public'], true)) {
                Route::middleware('web')->group(base_path('routes/shop-personalisation.php'));
            }

            // Editor-shell + WYSIWYG mutation endpoints — shared between
            // agents and customer surfaces so staff and clients hit the
            // same route names. SitePolicy gates per-user access at the
            // controller layer; no agent.only/client.only middleware here.
            if (in_array($surface, ['all', 'agents', 'customer'], true)) {
                Route::middleware('web')->group(base_path('routes/editor-shell.php'));
            }

            // Editor-preview routes register on agents surface too so
            // EditorShellController can call URL::temporarySignedRoute(
            // 'editor-preview.show', ...) to mint the iframe URL. The
            // domain() binding inside the route file means agents-app
            // never actually serves these — the reverse proxy routes the
            // editor-preview hostname to the preview surface — but the
            // route name has to be in agents-app's routing table for
            // signed-URL generation to work. Customer surface needs the
            // same name resolvable for the same reason.
            if (in_array($surface, ['all', 'agents', 'customer', 'editor-preview'], true)) {
                Route::middleware('web')->group(base_path('routes/editor-preview.php'));
            }

            // Editor MCP HTTP transport (Front 3). Gated on the agent-tools flag.
            if (config('editor.agent_tools.enabled') && file_exists(base_path('routes/mcp.php'))) {
                Route::middleware('web')->group(base_path('routes/mcp.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust published Cloudflare ranges plus configured internal proxies.
        // X-Forwarded-Host and -Port are deliberately untrusted.
        $trustedProxies = require __DIR__.'/../config/trusted_proxies.php';
        $middleware->trustProxies(
            at: array_merge(
                $trustedProxies['cloudflare_v4'] ?? [],
                $trustedProxies['cloudflare_v6'] ?? [],
                $trustedProxies['internal'] ?? [],
            ),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PROTO,
        );
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'shop.domain' => ShopDomainResolver::class,
            'shop.enabled' => EnsureShopEnabled::class,
            'client.only' => EnsureClientUser::class,
            'agent.only' => EnsureAgentRole::class,
            'require.acting_user' => RequireActingUser::class,
        ]);
        $middleware->web(append: [
            CaptureAuthUserToContext::class,
        ]);
        // Global, not in the `web` group, so error responses and guest redirects carry the headers too;
        // the middleware no-ops on hosts that are neither the agent nor the customer surface.
        $middleware->append(AgentsSecurityHeaders::class);
        // No-op outside the test suite — see the class docblock.
        $middleware->prepend(UseRequestAssetOrigin::class);
        // Prefer the matched route's bound domain over the request host (route cache can be stale).
        $middleware->redirectGuestsTo(function ($request) {
            $surface = config('surfaces.current', 'all');

            // Public surface has no agent/client auth — fall through to whatever
            // route('login') exists (Fortify customer login). On the public-only
            // container, route('login') itself won't exist; in that case we 404.
            if ($surface === 'site-public') {
                return Route::has('login')
                    ? route('login')
                    : abort(404);
            }

            $agentDomain = config('domains.agent_domain');
            $routeDomain = $request->route()?->getDomain();

            $wantsAgentLogin = ($routeDomain ?? $request->getHost()) === $agentDomain;

            // Never generate a route this surface does not register: a guest
            // redirect that throws is strictly worse than one that lands on the
            // other login page.
            if ($wantsAgentLogin && Route::has('agent.login')) {
                return route('agent.login');
            }

            if (Route::has('login')) {
                return route('login');
            }

            return Route::has('agent.login')
                ? route('agent.login')
                : abort(404);
        });
        // edit_session is HMAC-signed by our own EditSessionCookie service —
        // exclude it from Laravel's cookie encryption to avoid double-encoding.
        $middleware->encryptCookies(except: [EditSessionCookie::NAME]);
        // /_edit/* endpoints authenticate via the SameSite=Lax HMAC edit_session
        // cookie (set by EditSessionAuth middleware). CSRF tokens require a session,
        // but public hosts don't share sessions with the admin domain.
        //
        // /reviews is the public native-review submission endpoint: public
        // site pages are static HTML with no session, so a CSRF token can
        // never be minted there. Abuse control is throttle:site-reviews +
        // honeypot + pending-only moderation (see SiteReviewSubmitController).
        $middleware->validateCsrfTokens(except: ['_edit/*', 'reviews', 'enquiries', 'mcp/editor']);
        // Hijack preview-host requests before the router so we don't have
        // to define preview-aware variants of every public route.
        $middleware->prepend(ResolvePreviewHost::class);
        // Prepended AFTER ResolvePreviewHost above, so it ends up EARLIER in the stack
        // (prepend pushes to the front) and therefore runs before StartSession, which is
        // the only point at which rewriting session.domain still takes effect.
        $middleware->prepend(ScopeSessionToStorefrontHost::class);
        $middleware->prepend(DemoPublicRequestUrl::class);
        // Exclude Stripe webhook from CSRF verification — Stripe cannot present a token.
        $middleware->validateCsrfTokens(except: ['shop/webhook/stripe']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (CheckoutException $exception, Request $request) {
            $site = $request->attributes->get('resolved_site');

            if (! $site) {
                return null;
            }

            return response()->view('shop.checkout-error', [
                'site' => $site,
            ], 409);
        });
    })->create();
