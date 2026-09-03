<?php

use App\Models\Client;
use App\Models\Site;
use App\Models\User;

/**
 * Client user on the customer domain whose client_id matches the site
 * (copied from tests/Feature/Customer/Portal request setup).
 *
 * @return array{0: Site, 1: User}
 */
function portalDesignSite(): array
{
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);

    return [$site, $client];
}

function portalDesignPillSlice(string $html, string $pill): string
{
    $needle = 'x-show="designPill === \''.$pill.'\'"';
    $start = strpos($html, $needle);
    expect($start)->not->toBeFalse();
    $from = $start;
    $next = strpos($html, 'x-show="designPill', $from + strlen($needle));

    return $next === false ? substr($html, $from) : substr($html, $from, $next - $from);
}

it('the CP Design page offers Layout and Header pills that mount the relocated components', function () {
    [$site, $client] = portalDesignSite();
    $html = $this->actingAs($client)->get(route('client.portal.design', $site))->assertOk()->getContent();
    expect($html)->toContain("designPill === 'layout'")
        ->toContain("designPill === 'header'")
        ->toContain("designPill === 'share_image'");

    $brief = portalDesignPillSlice($html, 'design_brief');
    expect($brief)->not->toContain('Share image');

    $shareImage = portalDesignPillSlice($html, 'share_image');
    expect($shareImage)->toContain('Share image');

    $layout = portalDesignPillSlice($html, 'layout');
    expect($layout)->toContain('About Page Layout')->toContain('Service Page Layout');

    $header = portalDesignPillSlice($html, 'header');
    expect($header)->toContain('Logo Size')->toContain('Chrome &amp; type');

    expect($html)->toContain("designPill === 'storefront'")
        ->and($html)->toContain('Seven sub-tabs')
        ->not->toContain('Six sub-tabs')
        ->not->toContain('Five sub-tabs')
        ->not->toContain('Three sub-tabs');

    expect($html)->toContain('Product facts');

    $blade = file_get_contents(resource_path('views/client/portal/design.blade.php'));
    expect($blade)
        ->toContain(":key=\"'page-layout-about-'.\$site->id\"")
        ->toContain(":key=\"'page-layout-service-'.\$site->id\"")
        ->toContain(":key=\"'logo-size-'.\$site->id\"")
        ->toContain(":key=\"'header-style-'.\$site->id\"");
});
