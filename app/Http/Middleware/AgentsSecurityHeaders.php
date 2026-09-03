<?php

namespace App\Http\Middleware;

use App\Support\MediaOrigins;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser isolation headers for the agents (staff) host.
 *
 * HSTS is intentionally omitted: every public hostname is already
 * terminated at Cloudflare, which is the correct place to set
 * Strict-Transport-Security. Emitting it from PHP would fight the
 * edge policy and is unnecessary on the internal HTTP hop.
 *
 * Editor-preview keeps its own EditorPreviewCsp — this middleware
 * no-ops on any host other than the agent domain.
 */
class AgentsSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $isAgentHost = $host === config('domains.agent_domain');
        $isCustomerHost = $host === config('domains.customer_domain');

        // The customer surface (client portal + editor shell) carries its own isolation headers
        // and CSP (customerPolicy), differing from the agents one only where the surfaces load different things.
        if (! $isAgentHost && ! $isCustomerHost) {
            return $next($request);
        }

        // Public-site HTML (CDN Alpine/lucide + un-nonced inline scripts). Never
        // linked from staff chrome; tests/bookmarks only. The strict nonce policy
        // would annihilate the page, so this route gets a RELAXED policy — not no
        // policy. It is the one staff-origin route rendering content derived from
        // generated site data, so it is the last place to leave unpoliced.
        //
        // Only script-src/style-src conflict on this page; connect-src stays strict so an
        // injected script cannot exfiltrate over fetch/XHR.
        // This middleware is GLOBAL, so it runs BEFORE routing and $request->route()
        // is still null here — routeIs() would always be false. Match on the path for
        // the pre-routing decision (whether to mint a nonce), then confirm against
        // the resolved route afterwards.
        // ALWAYS mint the nonce on this host. Deciding pre-routing whether to skip it
        // meant a path that matched the preview glob but resolved to no route (404,
        // 405, guest redirect) got the strict policy with an EMPTY `'nonce-'`, which
        // authorises nothing and would kill any inline script on that response.
        // Minting one that the relaxed policy simply never references costs nothing.
        //
        // Minted on the CUSTOMER host as well now that it has a nonce policy. It was
        // `''` there, which meant `@cspNonce` in the editor shell and the nonce
        // @fluxAppearance is handed both rendered EMPTY on that surface — invisible
        // while there was no CSP, fatal the moment there is one.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        // The resolved route is authoritative in BOTH directions. Leaving the path
        // match to stand when routing produced no route handed the relaxed policy —
        // 'unsafe-inline' plus two CDN script origins — to every 404, 405 and guest
        // redirect under `sites/<anything>/pages/<anything>/preview`, since Laravel's
        // `*` matches slashes too. Default-deny must not be opt-out-able by path.
        $relaxed = $request->route() !== null && $request->routeIs('site.admin.preview');

        // One-container demo: the editor-preview iframe document shares the portal host,
        // so the portal's DENY/CSP would blank it. EditorPreviewCsp sets that route's own
        // headers. On the private platform the route lives on its own host and never
        // reaches this middleware.
        if (config('demo.enabled') && $request->routeIs('editor-preview.show')) {
            return $response;
        }

        if ($isAgentHost) {
            $response->headers->set(
                'Content-Security-Policy',
                $relaxed ? $this->legacyPreviewPolicy() : $this->policy($nonce)
            );
        } elseif ($isCustomerHost) {
            $response->headers->set('Content-Security-Policy', $this->customerPolicy($nonce));
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Permissions-Policy', implode(', ', [
            'accelerometer=()',
            // (self), not (). Hero Video Studio autoplays a muted current-clip
            // preview and replays past versions from x-on:mouseover, which is not
            // user activation.
            //
            // Chromium short-circuits the Permissions-Policy autoplay check for MUTED
            // media, so a bare autoplay=() would not in fact break the studio's
            // players today. (self) is still the right value — it is
            // required the moment a clip gains audio, the muted short-circuit is
            // Chromium-specific, and denying autoplay to embedded third-party frames
            // is all this directive was ever buying on an origin that embeds none.
            'autoplay=(self)',
            'camera=()',
            'display-capture=()',
            'encrypted-media=()',
            'fullscreen=()',
            'geolocation=()',
            'gyroscope=()',
            'magnetometer=()',
            'microphone=()',
            'midi=()',
            'payment=()',
            'picture-in-picture=()',
            'publickey-credentials-get=()',
            'screen-wake-lock=()',
            'usb=()',
            'xr-spatial-tracking=()',
        ]));
        // X-Powered-By is emitted at the SAPI layer (expose_php). Removing it
        // from Symfony's header bag is a no-op; turn it off via expose_php=Off
        // or nginx fastcgi_hide_header X-Powered-By.

        return $response;
    }

    private function policy(string $nonce): string
    {
        $agentOrigin = 'https://'.config('domains.agent_domain');
        $editorPreviewOrigin = 'https://'.config('domains.editor_preview_domain');
        // Browser tests frame the preview from the pest HTTP server:
        // plain http on an ephemeral port. Test-suite only; dead code
        // in every real environment.
        if (app()->runningUnitTests()) {
            $editorPreviewOrigin .= ' http://'.config('domains.editor_preview_domain').':*';
        }
        // Under the pest browser server, Vite asset URLs are force-rooted to
        // http://127.0.0.1:<port> — allow that origin for assets/XHR so the
        // editor bundles load. Test-suite only; empty in every real env.
        $testAssetOrigin = app()->runningUnitTests() ? ' http://127.0.0.1:*' : '';
        $mediaOrigins = MediaOrigins::asSourceList();

        return implode('; ', [
            "default-src 'self'",
            // Flux/Alpine evaluate x-data via new Function(); the CSP Alpine
            // build is not what Flux ships. Nonce still blocks untrusted
            // injected scripts; unsafe-eval is the residual Alpine surface.
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'{$testAssetOrigin}",
            "style-src 'self' 'nonce-{$nonce}' 'unsafe-inline' https://fonts.bunny.net https://cdn.jsdelivr.net{$testAssetOrigin}",
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data: blob: {$mediaOrigins} https://unpkg.com",
            "media-src 'self' {$mediaOrigins}",
            "font-src 'self' data: https://fonts.bunny.net",
            "connect-src 'self'{$testAssetOrigin}",
            "frame-src {$editorPreviewOrigin}",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "report-uri {$agentOrigin}/csp-report",
        ]);
    }

    /**
     * Strict nonce policy for the customer surface (client portal + editor shell).
     *
     * Deliberately NOT the agents policy re-used: the two surfaces load different
     * third-party origins, and a shared policy would grant each host the other's.
     * Derived from what the customer views actually reference:
     *
     *  - script-src: same shape as agents. 'unsafe-eval' is Flux/Alpine evaluating
     *    x-data via new Function(); the client layout and its help panel are Alpine.
     *  - style-src / font-src fonts.bunny.net: partials/head loads the brand fonts,
     *    and livewire/design-panel loads a per-font stylesheet from the same host.
     *    cdn.jsdelivr.net is NOT here — flatpickr is on the agents /sites page only.
     *  - img-src unpkg.com: livewire/page-manager renders lucide-static icon SVGs
     *    straight from unpkg, and page-manager IS on the portal's Pages tab.
     *  - frame-src: the editor shell embeds the editor-preview origin in an iframe,
     *    and clients reach that shell from the portal.
     *  - connect-src 'self': nothing on this surface fetches cross-origin. The
     *    nominatim lookup in contact-editor is a server-side Http:: call, not a
     *    browser fetch, so it is not governed by this directive.
     *
     * report-uri points at the AGENTS origin because /csp-report is bound to that
     * domain (bootstrap/app.php). Report POSTs are not CORS-gated, and the
     * editor-preview policy already reports cross-origin the same way.
     */
    private function customerPolicy(string $nonce): string
    {
        $agentOrigin = 'https://'.config('domains.agent_domain');
        $editorPreviewOrigin = 'https://'.config('domains.editor_preview_domain');
        // Browser tests frame the preview from the pest HTTP server:
        // plain http on an ephemeral port. Test-suite only; dead code
        // in every real environment.
        if (app()->runningUnitTests()) {
            $editorPreviewOrigin .= ' http://'.config('domains.editor_preview_domain').':*';
        }
        if (config('demo.enabled')) {
            $demoRoot = rtrim((string) config('app.url'), '/');
            $editorPreviewOrigin .= " 'self'".($demoRoot !== '' ? ' '.$demoRoot : '');
            // The preview nav's Shop link frames the live storefront (see PageRenderer).
            $editorPreviewOrigin .= ' https://'.config('demo.site_host');
        }
        // Under the pest browser server, Vite asset URLs are force-rooted to
        // http://127.0.0.1:<port> — allow that origin for assets/XHR so the
        // editor bundles load. Test-suite only; empty in every real env.
        $testAssetOrigin = app()->runningUnitTests() ? ' http://127.0.0.1:*' : '';
        $mediaOrigins = MediaOrigins::asSourceList();

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'{$testAssetOrigin}",
            "style-src 'self' 'nonce-{$nonce}' 'unsafe-inline' https://fonts.bunny.net{$testAssetOrigin}",
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data: blob: {$mediaOrigins} https://unpkg.com",
            "media-src 'self' {$mediaOrigins}",
            "font-src 'self' data: https://fonts.bunny.net",
            "connect-src 'self'{$testAssetOrigin}",
            "frame-src {$editorPreviewOrigin}",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "report-uri {$agentOrigin}/csp-report",
        ]);
    }

    /**
     * Relaxed policy for the legacy same-origin admin preview.
     *
     * That route renders generated public-site HTML, which loads Alpine from
     * /vendor/alpine.min.js and lucide from /vendor/lucide.min.js, plus
     * Flatpickr from jsDelivr and un-nonced inline scripts — so script-src
     * and style-src have to give way. Nothing else does. connect-src in
     * particular is what stops an injected script exfiltrating over the
     * network API, and the preview makes no cross-origin requests of its own.
     *
     * The CDN hosts mirror what resources/views/site/page.blade.php actually
     * loads (jsDelivr Flatpickr; unpkg Leaflet on contact pages). Fonts are
     * same-origin. Alpine and Lucide are vendored under /vendor/.
     */
    private function legacyPreviewPolicy(): string
    {
        $agentOrigin = 'https://'.config('domains.agent_domain');
        $mediaOrigins = MediaOrigins::asSourceList();
        // See the main policy blocks — pest browser server asset origin.
        $testAssetOrigin = app()->runningUnitTests() ? ' http://127.0.0.1:*' : '';

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://unpkg.com",
            // unpkg in style-src too: site/sections/details.blade.php loads
            // leaflet.css from there for the contact map. The first version of this
            // policy allowed unpkg for scripts only, so a preview of any page with a
            // details section and coordinates rendered its map unstyled.
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com",
            // OpenStreetMap tiles, likewise for the map.
            "img-src 'self' data: blob: {$mediaOrigins} https://unpkg.com https://*.tile.openstreetmap.org",
            "media-src 'self' {$mediaOrigins}",
            "font-src 'self' data:",
            "connect-src 'self'{$testAssetOrigin}",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "report-uri {$agentOrigin}/csp-report",
        ]);
    }
}
