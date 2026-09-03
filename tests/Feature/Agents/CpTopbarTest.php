<?php

use App\Enums\AgentRole;
use App\Enums\SiteStatus;
use App\Models\Client;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\User;

function cpBannerCount(string $html): int
{
    preg_match_all('/\brole="banner"/', $html, $matches);

    return count($matches[0]);
}

function cpTopbarChunk(string $html): string
{
    expect($html)->toContain('data-cp-topbar');

    preg_match('/<[^>]*\bdata-cp-topbar\b[\s\S]*?<\/(?:header|ui-header)>/', $html, $match);

    expect($match[0] ?? null)->not->toBeEmpty('top bar element was not found');

    return $match[0];
}

function cpSidebarChunk(string $html): string
{
    expect($html)->toContain('data-cp-sidebar');

    preg_match('/<[^>]*\bdata-cp-sidebar\b[\s\S]*?<\/(?:aside|nav|ui-sidebar)>/', $html, $match);

    expect($match[0] ?? null)->not->toBeEmpty('sidebar element was not found');

    return $match[0];
}

it('renders a single banner top bar on the agents control panel', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();

    $html = $this->actingAs($agent)
        ->get(route('dashboard'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-cp-topbar')
        ->and(cpBannerCount($html))->toBe(1)
        ->and(substr_count($html, 'data-cp-topbar'))->toBe(1)
        ->and(strpos($html, 'data-cp-topbar'))->toBeLessThan(strpos($html, 'data-cp-sidebar'));
});

it('renders a single banner top bar on the client control panel', function () {
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);

    $html = $this->actingAs($client)
        ->get(route('client.portal.site', $site))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-cp-topbar')
        ->and(cpBannerCount($html))->toBe(1)
        ->and(substr_count($html, 'data-cp-topbar'))->toBe(1)
        ->and(strpos($html, 'data-cp-topbar'))->toBeLessThan(strpos($html, 'data-cp-sidebar'));
});

it('moves the SiteWorks logo into the agents top bar and out of the sidebar', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();

    $html = $this->actingAs($agent)
        ->get(route('dashboard'))
        ->assertOk()
        ->getContent();

    $topbar = cpTopbarChunk($html);

    expect($topbar)->toContain('data-cp-brand')
        ->and($topbar)->toContain('SiteWorks')
        ->and(substr_count($html, 'data-cp-brand'))->toBe(1)
        ->and(substr_count($html, '/images/sw-mark'))->toBe(1);
});

it('moves the SiteWorks logo into the client top bar and out of the sidebar', function () {
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);

    $html = $this->actingAs($client)
        ->get(route('client.portal.site', $site))
        ->assertOk()
        ->getContent();

    $topbar = cpTopbarChunk($html);

    expect($topbar)->toContain('data-cp-brand')
        ->and($topbar)->toContain('SiteWorks')
        ->and(substr_count($html, 'data-cp-brand'))->toBe(1)
        ->and(substr_count($html, '/images/sw-mark'))->toBe(1);
});

it('places the assistant toggle and user menu in the agents top bar', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create(['name' => 'Ada Agent']);

    $html = $this->actingAs($agent)
        ->get(route('dashboard'))
        ->assertOk()
        ->getContent();

    $topbar = cpTopbarChunk($html);

    $sidebar = cpSidebarChunk($html);

    expect($topbar)->toContain('data-cp-assistant-toggle')
        ->and($topbar)->toContain('data-test="logout-button"')
        ->and($topbar)->toContain('Ada Agent')
        ->and($sidebar)->not->toContain('data-cp-assistant-toggle')
        ->and($sidebar)->not->toContain('data-test="logout-button"');
});

it('places the assistant toggle and user menu in the client top bar', function () {
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'name' => 'Cara Client',
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);

    $html = $this->actingAs($client)
        ->get(route('client.portal.site', $site))
        ->assertOk()
        ->getContent();

    $topbar = cpTopbarChunk($html);

    $sidebar = cpSidebarChunk($html);

    expect($topbar)->toContain('data-cp-assistant-toggle')
        ->and($topbar)->toContain('data-test="logout-button"')
        ->and($topbar)->toContain('Cara Client')
        ->and($sidebar)->not->toContain('data-cp-assistant-toggle')
        ->and($sidebar)->not->toContain('data-test="logout-button"');
});

function cpMainChunk(string $html): string
{
    preg_match('/<(?:main|ui-main)\b[\s\S]*?<\/(?:main|ui-main)>/', $html, $match);

    if (! empty($match[0])) {
        return $match[0];
    }

    $topbar = cpTopbarChunk($html);
    $pos = strpos($html, $topbar);

    return substr($html, $pos + strlen($topbar));
}

/**
 * @return array{site: Site, agent: User, home: GeneratedPage}
 */
function cpSiteContextFixture(): array
{
    config(['site.use_versioned_renderer' => true]);

    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $agent->id,
        'assigned_to_user_id' => $agent->id,
        'business_name' => 'Verdant Bloom',
        'business_type' => 'florist and botanical gifts',
        'location' => 'York, United Kingdom',
        'status' => SiteStatus::Review,
        'preview_domain' => 'verdant-bloom-'.uniqid(),
        'preview_brand' => 'a',
    ]);
    $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    Preview::factory()->for($site)->create(['slug' => 'verdant-bloom-'.uniqid()]);
    $site->refresh();
    test()->actingAs($agent);

    return compact('site', 'agent', 'home');
}

it('renders the site-context cluster in the agents top bar on a site route', function () {
    ['site' => $site, 'home' => $home] = cpSiteContextFixture();

    $html = $this->get(route('sites.show', $site))
        ->assertOk()
        ->getContent();

    $topbar = cpTopbarChunk($html);
    $viewHref = 'https://'.$site->publicHost().'/_edit/view-live';
    $editHref = route('site.editor-shell', ['site' => $site->id, 'page' => $home->id]);

    expect($topbar)->toContain('data-cp-site-context')
        ->and($topbar)->toContain('Verdant Bloom')
        ->and($topbar)->toContain(route('sites.show', $site))
        // Staff keep the pipeline status badge on the agents CP.
        ->and($topbar)->toContain('data-cp-site-status')
        ->and($topbar)->toContain('Review')
        ->and($topbar)->toContain($viewHref)
        ->and($topbar)->toContain($editHref)
        ->and(cpBannerCount($html))->toBe(1)
        ->and(substr_count($html, 'data-cp-topbar'))->toBe(1)
        ->and(substr_count($html, 'data-cp-site-context'))->toBe(1);
});

it('omits the site-context cluster on agents routes without a site', function (string $url) {
    $agent = User::factory()->staff(AgentRole::Agent)->create();

    $html = $this->actingAs($agent)
        ->get($url)
        ->assertOk()
        ->getContent();

    $topbar = cpTopbarChunk($html);

    expect($topbar)->not->toContain('data-cp-site-context')
        ->and($topbar)->not->toContain('data-cp-view-site')
        ->and($topbar)->not->toContain('data-cp-edit-site')
        ->and(cpBannerCount($html))->toBe(1);
})->with([
    'dashboard' => fn () => route('dashboard'),
    'sites index' => fn () => route('sites.index'),
    'clients index' => fn () => route('clients.index'),
]);

it('renders the site-context cluster and View/Edit site actions in the client portal top bar', function () {
    config(['site.use_versioned_renderer' => true]);

    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create([
        'client_id' => $tenant->id,
        'business_name' => 'Client Side Florist',
        'status' => SiteStatus::Review,
        'preview_domain' => 'client-side-florist-'.uniqid(),
        'preview_brand' => 'a',
    ]);
    $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    Preview::factory()->for($site)->create(['slug' => 'client-side-florist-'.uniqid()]);
    $site->refresh();

    $html = $this->actingAs($client)
        ->get(route('client.portal.site', $site))
        ->assertOk()
        ->getContent();

    $topbar = cpTopbarChunk($html);
    $viewHref = 'https://'.$site->publicHost().'/_edit/view-live';
    $editHref = route('site.editor-shell', ['site' => $site->id, 'page' => $home->id]);
    $agentsShowUrl = route('sites.show', $site);

    expect($topbar)->toContain('data-cp-site-context')
        ->and($topbar)->toContain('Client Side Florist')
        // The pipeline status badge is staff-only — a client sees just the
        // site name, never the internal Review/Draft/Published concept.
        ->and($topbar)->not->toContain('data-cp-site-status')
        ->and($topbar)->toContain('data-cp-view-site')
        ->and($topbar)->toContain($viewHref)
        ->and($topbar)->toContain('data-cp-edit-site')
        ->and($topbar)->toContain($editHref)
        ->and($topbar)->not->toContain('href="'.$agentsShowUrl.'"')
        ->and($topbar)->toContain('href="'.route('client.portal.site', $site).'"')
        ->and(cpBannerCount($html))->toBe(1)
        ->and(substr_count($html, 'data-cp-topbar'))->toBe(1)
        ->and(substr_count($html, 'data-cp-site-context'))->toBe(1);
});

it('drops the page-header strip from main and keeps the page title', function () {
    ['site' => $site] = cpSiteContextFixture();

    $html = $this->get(route('sites.show', $site))
        ->assertOk()
        ->getContent();

    $main = cpMainChunk($html);

    expect($main)->toContain('Overview')
        ->and($main)->not->toContain('data-cp-site-context')
        ->and($main)->not->toContain('data-cp-view-site')
        ->and($main)->not->toContain('data-cp-edit-site')
        ->and($main)->not->toContain(' › ')
        ->and($main)->not->toContain('florist and botanical gifts')
        ->and($main)->not->toContain('York, United Kingdom')
        ->and($main)->not->toContain('View site')
        ->and($main)->not->toContain('Edit Site');
});
