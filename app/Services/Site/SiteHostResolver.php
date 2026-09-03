<?php

namespace App\Services\Site;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Resolves the Site a public request is addressed to from its Host
 * header. Preview-subdomain matching reads local brand config only —
 * there is no Cloudflare API in the public demo.
 */
class SiteHostResolver
{
    public function resolve(Request $request): ?Site
    {
        /** @var ?Site $site */
        $site = $request->attributes->get('resolved_site');
        if ($site) {
            return $site;
        }

        return $this->siteForHost(strtolower($request->getHost()));
    }

    public function siteForHost(string $host): ?Site
    {
        // Branded preview FQDN (<slug>.<sub>.<apex>): strip the longest matching
        // suffix and look the slug up under that brand — the platform behaviour.
        foreach ($this->hostSuffixMap() as $suffix => $brand) {
            if ($suffix === '' || ! str_ends_with($host, $suffix)) {
                continue;
            }
            $slug = substr($host, 0, -strlen($suffix));
            if ($slug === '' || str_contains($slug, '.')) {
                return null;
            }

            return Site::where('preview_brand', $brand)->where('preview_domain', $slug)->first();
        }

        // Bare host stored as the preview slug (the demo's `localhost`), then an active custom domain.
        return Site::where('preview_domain', $host)->first()
            ?? Site::where('custom_domain', $host)->where('custom_domain_status', 'active')->first();
    }

    /**
     * @return array<string, string> suffix => brand, longest first
     */
    public function hostSuffixMap(): array
    {
        $map = [];
        foreach (array_keys((array) config('services.cloudflare.brands', [])) as $brand) {
            if (! is_string($brand) || $brand === '') {
                continue;
            }
            $suffix = $this->hostSuffix($brand);
            if ($suffix === '') {
                continue;
            }
            $map[$suffix] = $brand;
        }
        uksort($map, fn ($a, $b) => strlen($b) <=> strlen($a));

        return $map;
    }

    public function hostSuffix(string $brand): string
    {
        $cfg = (array) (config('services.cloudflare.brands.'.$brand) ?? []);
        $apex = (string) ($cfg['apex'] ?? '');
        $sub = trim((string) ($cfg['subdomain'] ?? ''), '.');
        if ($apex === '') {
            return '';
        }

        return $sub === '' ? ".{$apex}" : ".{$sub}.{$apex}";
    }

    public function previewFqdn(string $slug, string $brand): string
    {
        $cfg = (array) (config('services.cloudflare.brands.'.$brand) ?? []);
        $apex = trim((string) ($cfg['apex'] ?? 'localhost'), '.');
        $sub = trim((string) ($cfg['subdomain'] ?? ''), '.');
        if ($apex === '') {
            // No brand apex configured (the hosted demo): the slug IS the host.
            return $slug;
        }

        return $sub === '' ? "{$slug}.{$apex}" : "{$slug}.{$sub}.{$apex}";
    }

    /**
     * @param  \Closure(string): bool  $isTaken
     */
    public function allocateSlug(string $base, \Closure $isTaken, int $maxAttempts = 100): string
    {
        $candidate = Str::slug($base);
        if ($candidate === '') {
            $candidate = 'site-'.Str::random(6);
        }

        $try = $candidate;
        for ($n = 2; $isTaken($try); $n++) {
            if ($n > $maxAttempts) {
                return $candidate.'-'.strtolower(Str::random(4));
            }
            $try = "{$candidate}-{$n}";
        }

        return $try;
    }

    /**
     * Whether search engines may be invited to index the site AT THIS HOST.
     *
     * Preview-subdomain hosts are never indexable. A site is crawlable only on its active custom domain.
     */
    public function isIndexableHost(Request $request, Site $site): bool
    {
        $host = strtolower($request->getHost());

        foreach ($this->hostSuffixMap() as $suffix => $brand) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        return $site->custom_domain !== null
            && strtolower($site->custom_domain) === $host
            && $site->custom_domain_status === 'active';
    }
}
