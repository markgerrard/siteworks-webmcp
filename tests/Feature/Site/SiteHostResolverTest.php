<?php

use App\Models\Site;
use App\Services\Site\SiteHostResolver;


beforeEach(function () {
    config(['services.cloudflare.brands' => ['a' => ['apex' => 'example.com', 'subdomain' => 'd']]]);
});

it('resolves a branded preview FQDN by suffix and brand', function () {
    $site = Site::factory()->create(['preview_domain' => 'camino', 'preview_brand' => 'a']);

    expect(app(SiteHostResolver::class)->siteForHost('camino.d.example.com')?->id)->toBe($site->id)
        ->and(app(SiteHostResolver::class)->siteForHost('nested.camino.d.example.com'))->toBeNull()
        ->and(app(SiteHostResolver::class)->siteForHost('other.d.example.com'))->toBeNull();
});

it('resolves a bare host stored as the preview slug (demo host)', function () {
    $site = Site::factory()->create(['preview_domain' => 'localhost', 'preview_brand' => 'a']);

    expect(app(SiteHostResolver::class)->siteForHost('localhost')?->id)->toBe($site->id);
});

it('resolves an active custom domain and ignores an inactive one', function () {
    $active = Site::factory()->create(['custom_domain' => 'cakes.test', 'custom_domain_status' => 'active']);
    Site::factory()->create(['custom_domain' => 'pending.test', 'custom_domain_status' => 'pending']);

    expect(app(SiteHostResolver::class)->siteForHost('cakes.test')?->id)->toBe($active->id)
        ->and(app(SiteHostResolver::class)->siteForHost('pending.test'))->toBeNull();
});
