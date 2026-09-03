<?php

use App\Enums\AgentRole;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Copied from the sites.show setup in SiteEditorLazyLoadingTest /
 * AdminEditEntryLinksTest: staff agent, owned site, home page, preview
 * (the Design tab is gated on latestPreview).
 *
 * @return array{0: Site, 1: User}
 */
function designTabsSite(): array
{
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $agent->id,
        'preview_domain' => 'design-tabs-spec',
        'preview_brand' => 'a',
    ]);
    GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    Preview::factory()->for($site)->create(['slug' => 'design-tabs-spec']);

    return [$site, $agent];
}

function designTabsPillSlice(string $html, string $pill): string
{
    $needle = 'x-show="designPill === \''.$pill.'\'"';
    $start = strpos($html, $needle);
    expect($start)->not->toBeFalse();
    $from = $start;
    $after = $from + strlen($needle);
    $next = strpos($html, 'x-show="designPill', $after);
    if ($next === false) {
        $next = strpos($html, 'x-show="tab === \'', $after);
    }

    return $next === false ? substr($html, $from) : substr($html, $from, $next - $from);
}

it('the Design tab offers Layout and Header pills that mount the relocated components', function () {
    [$site, $agent] = designTabsSite();
    $html = $this->actingAs($agent)->get(route('sites.section', ['site' => $site, 'section' => 'design']))->assertOk()->getContent();
    expect($html)->toContain("designPill === 'layout'")
        ->toContain("designPill === 'header'")
        ->toContain("designPill === 'share_image'")
        ->toContain("designPill === 'storefront'");

    $brief = designTabsPillSlice($html, 'design_brief');
    expect($brief)->not->toContain('Share image');

    $shareImage = designTabsPillSlice($html, 'share_image');
    expect($shareImage)->toContain('Share image');

    $layout = designTabsPillSlice($html, 'layout');
    expect($layout)->toContain('About Page Layout')->toContain('Service Page Layout');

    $header = designTabsPillSlice($html, 'header');
    expect($header)->toContain('Logo Size')->toContain('Chrome &amp; type')
        ->not->toContain("x-show=\"tab === 'navigation'\"");

    $storefront = designTabsPillSlice($html, 'storefront');
    expect($storefront)->toContain('Product facts');
});
