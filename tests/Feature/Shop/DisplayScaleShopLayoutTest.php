<?php

use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;

/**
 * @return array{0: \App\Models\Site}
 */
function displayScaleShopSite(string $host, ?string $displayScaleOverride): array
{
    [$site] = shopModeMatrixSite($host, 'cart');

    $theme = ['key' => 'trades-bold'];
    if ($displayScaleOverride !== null) {
        $theme['display_scale_override'] = $displayScaleOverride;
    }

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => $theme,
        ],
        'page_revisions' => [],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    return [$site];
}

test('grand cart-mode shop layout emits the xl shell inset attribute and media rule', function () {
    displayScaleShopSite('grand-shell.example', 'grand');

    $html = shopModeMatrixGet('grand-shell.example', '/shop');

    expect($html)->toContain('data-display-scale="grand"')
        ->toContain('@media (min-width: 1280px) { body[data-display-scale="grand"] .site-shell-container { padding-left: 4rem; padding-right: 4rem; } }');
});

test('standard cart-mode shop layout omits the display-scale attribute and media rule', function () {
    displayScaleShopSite('std-shell.example', null);

    $html = shopModeMatrixGet('std-shell.example', '/shop');

    expect($html)->not->toContain('data-display-scale')
        ->not->toContain('body[data-display-scale');
});
