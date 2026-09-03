<?php

namespace App\Support;

use Illuminate\Http\Request;

class EditorParentOrigin
{
    /**
     * Allowlisted parent surface for the iframe bridge.
     *
     * Mirrors EditorPreviewController: only the agent and customer
     * hosts are valid; anything else falls back to agent_domain.
     */
    public static function resolve(?string $requested): string
    {
        $requested = (string) $requested;

        $allowed = [
            'https://'.config('domains.agent_domain'),
            'https://'.config('domains.customer_domain'),
        ];

        if (config('demo.enabled')) {
            $demoRoot = rtrim((string) config('app.url'), '/');
            if ($demoRoot !== '') {
                $allowed[] = $demoRoot;
            }
            $customer = (string) config('domains.customer_domain');
            if ($customer !== '') {
                $allowed[] = 'http://'.$customer;
            }
        }

        if (in_array($requested, $allowed, true)) {
            return $requested;
        }

        // Browser tests: pest-plugin-browser serves every domain from one
        // plain-HTTP server on an ephemeral port, so the parent's real
        // origin is http://<domain>:<port>. Accept that form for the two
        // allowlisted hosts ONLY while running the test suite — this
        // branch is dead code in every real environment.
        if (app()->runningUnitTests()) {
            $parts = parse_url($requested);
            $testHosts = [config('domains.agent_domain'), config('domains.customer_domain')];
            if (($parts['scheme'] ?? null) === 'http' && in_array($parts['host'] ?? null, $testHosts, true)) {
                return $requested;
            }
        }

        return 'https://'.config('domains.agent_domain');
    }

    public static function fromRequest(Request $request): string
    {
        // Browser tests: the pest server delivers the parent page over plain
        // HTTP on an EPHEMERAL port, so its true origin is scheme+host+port.
        // The port is the discriminator — simulated feature-test requests
        // arrive on a standard port and keep the pinned-https behaviour.
        if (app()->runningUnitTests() && ! in_array($request->getPort(), [80, 443], true)) {
            return self::resolve($request->getSchemeAndHttpHost());
        }

        if (config('demo.enabled')) {
            $root = rtrim((string) config('app.url'), '/');

            return self::resolve($root !== '' ? $root : $request->getSchemeAndHttpHost());
        }

        // Deliberately NOT getSchemeAndHttpHost(): behind the Cloudflare
        // tunnel the app sees plain http, so the scheme must be pinned.
        return self::resolve('https://'.$request->getHost());
    }
}
