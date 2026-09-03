<?php

use App\Enums\AgentRole;
use App\Enums\PageKind;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Copied from setupUploadSiteWithAboutAndService() in PageManagerUploadHeroTest
 * (site + published home + about + one service). Returns the agent and home
 * page as well so the legacy-override case can set layout_preset_key.
 *
 * @return array{0: Site, 1: User, 2: GeneratedPage}
 */
function designCardsSite(): array
{
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $agent->id,
        'business_type' => 'Electrician',
    ]);
    BusinessProfile::factory()->for($site)->create(['profile_data' => ['summary' => 'test']]);
    Preview::factory()->for($site)->create();

    $home = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'content_data' => [],
        'sort_order' => 0,
        'version' => 1,
    ]);

    GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'about',
        'content_data' => [],
        'sort_order' => 1,
        'version' => 1,
    ]);

    GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'roofing',
        'kind' => PageKind::Service,
        'content_data' => [],
        'sort_order' => 2,
        'version' => 1,
        'hero_source' => 'dedicated',
    ]);

    return [$site, $agent, $home];
}

function designCardsTabPane(string $html, string $tab): string
{
    $needle = 'x-show="activePage === \''.$tab.'\'"';
    $start = strpos($html, $needle);
    expect($start)->not->toBeFalse();
    $from = $start;
    $next = strpos($html, 'x-show="activePage === \'', $from + strlen($needle));

    return $next === false ? substr($html, $from) : substr($html, $from, $next - $from);
}

it('the Home Sections tab hosts the homepage layout control and none of the site-wide cards', function () {
    [$site, $agent] = designCardsSite();
    $html = Livewire::actingAs($agent)->test('page-manager', ['siteId' => $site->id])->set('activeTab', 'home')->html();
    $pane = designCardsTabPane($html, 'home');
    expect($html)->toContain('Homepage Layout')
        ->toContain('Site-wide layout and header controls now live under Design → Layout and Design → Header.')
        ->not->toContain('About Page Layout')->not->toContain('Service Page Layout')
        ->not->toContain('Logo Size')
        ->not->toContain('Chrome & type');
    expect($pane)->not->toContain('wire:name="page-layout-override"')
        ->not->toContain('Page Layout');
});

it('the Home tab still shows the per-page override when a legacy layout_preset_key is set', function () {
    [$site, $agent, $home] = designCardsSite();
    $home->update(['layout_preset_key' => 'editorial']);
    $html = Livewire::actingAs($agent)->test('page-manager', ['siteId' => $site->id])->set('activeTab', 'home')->html();
    expect($html)->toContain('overrides the site-wide homepage layout')->toContain('Homepage Layout');
});

it('About and service tabs keep the per-page override card', function () {
    [$site, $agent] = designCardsSite();
    foreach (['about', 'roofing'] as $tab) {
        $html = Livewire::actingAs($agent)->test('page-manager', ['siteId' => $site->id])->set('activeTab', $tab)->html();
        // Nested page-layout-override is lazy.bundle, so the parent HTML is the
        // placeholder (wire:name / wire:key), not the inner "Page Layout" heading.
        // All page panes share one Livewire html() payload (Alpine x-show), so
        // scope the Homepage Layout absence to this tab's pane.
        $pane = designCardsTabPane($html, $tab);
        expect($pane)->toContain('wire:name="page-layout-override"')
            ->not->toContain('Homepage Layout');
    }
});
