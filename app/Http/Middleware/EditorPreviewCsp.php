<?php

namespace App\Http\Middleware;

use App\Support\MediaOrigins;
use Closure;
use Illuminate\Http\Request;

class EditorPreviewCsp
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $agentOrigin = 'https://'.config('domains.agent_domain');
        $customerOrigin = 'https://'.config('domains.customer_domain');

        // Both agents and customer origins are legitimate parent frames for the editor iframe.
        $frameAncestors = $agentOrigin === $customerOrigin
            ? $agentOrigin
            : "{$agentOrigin} {$customerOrigin}";

        // Browser tests: the pest HTTP server frames this page from
        // http://<domain>:<ephemeral port>. CSP host-sources support a port
        // wildcard, so allow the plain-HTTP forms of the same two hosts —
        // ONLY while running the test suite; dead code everywhere real.
        if (app()->runningUnitTests()) {
            $frameAncestors .= ' http://'.config('domains.agent_domain').':*'
                .' http://'.config('domains.customer_domain').':*';
        }
        if (config('demo.enabled')) {
            $demoRoot = rtrim((string) config('app.url'), '/');
            if ($demoRoot !== '') {
                $frameAncestors .= ' '.$demoRoot;
            }
        }

        // Under the pest browser server, Vite asset URLs are force-rooted to
        // http://127.0.0.1:<port> — allow that origin for assets/XHR so the
        // preview bundles load. Test-suite only; empty in every real env.
        $testAssetOrigin = app()->runningUnitTests() ? ' http://127.0.0.1:*' : '';

        // Editor-preview renders PageRenderer / site/page.blade.php, which
        // now self-hosts fonts, compiled Tailwind, and Alpine
        // (public/vendor/alpine.min.js). Remaining CDNs:
        // jsDelivr (pinned Flatpickr) and unpkg (Leaflet on
        // contact pages). The dormant resources/views/preview/ templates
        // still mention bunny/Play CDN/lucide@latest; they are not what
        // this origin serves.
        //
        // Tighter CSP (hashes/nonces, no 'unsafe-inline') is a future hardening pass,
        // because cross-origin + frame-ancestors already blocks the main
        // attack vectors (cookie reads, top-frame nav, admin POSTs from
        // injected scripts).
        $jsdelivr = 'https://cdn.jsdelivr.net';
        $unpkg = 'https://unpkg.com';
        $mediaOrigins = MediaOrigins::asSourceList();

        $directives = [
            "default-src 'none'",
            // 'unsafe-eval' is required by Alpine.js — it uses `new Function(...)`
            // to evaluate x-data / x-show / x-if expressions at runtime. Cross-origin
            // + frame-ancestors still block the high-impact attack vectors (cookie
            // reads, top-frame nav, admin POSTs from injected scripts), so the
            // residual eval surface is bounded to what the preview HTML's own
            // Alpine usage already needs.
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' {$jsdelivr} {$unpkg}{$testAssetOrigin}",
            "style-src 'self' 'unsafe-inline' {$jsdelivr} {$unpkg}{$testAssetOrigin}",
            "img-src 'self' data: {$mediaOrigins}",
            // Without media-src, default-src 'none' blocks every hero clip inside the WYSIWYG iframe.
            "media-src 'self' {$mediaOrigins}",
            "font-src 'self' data:",
            "connect-src 'self'{$testAssetOrigin}",
            "frame-ancestors {$frameAncestors}",
            "form-action 'none'",
            "base-uri 'none'",
            "report-uri {$agentOrigin}/csp-report",
        ];

        $header = config('editor_preview.csp_mode') === 'report-only'
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        $response->headers->set($header, implode('; ', $directives));
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
