<?php

use App\Enums\AgentRole;
use App\Enums\Shop\OrderStatus;
use App\Models\Client;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Shop\Order;
use App\Models\Site;
use App\Models\User;
use Tests\Support\CommerceReads;

/**
 * @return array{0: Site, 1: User}
 */
function sectionSite(bool $admin = false): array
{
    $agent = User::factory()->staff($admin ? AgentRole::Admin : AgentRole::Agent)->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $agent->id,
        'preview_domain' => 'section-spec-'.uniqid(),
        'preview_brand' => 'a',
        'business_name' => 'Section Spec Plumbing',
    ]);
    GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    Preview::factory()->for($site)->create(['slug' => 'section-spec-'.uniqid()]);

    return [$site, $agent];
}

/**
 * @return array{0: Site, 1: User}
 */
function sectionShopSite(bool $admin = false): array
{
    [$actor, $site] = CommerceReads::shopSite();
    if ($admin) {
        $actor->forceFill(['role' => AgentRole::Admin])->save();
    }
    $site->update([
        'preview_domain' => 'section-shop-'.uniqid(),
        'preview_brand' => 'a',
        'business_name' => 'Section Shop Candles',
    ]);
    GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    Preview::factory()->for($site)->create(['slug' => 'section-shop-'.uniqid()]);

    return [$site->fresh(), $actor->fresh()];
}

it('overview returns 200 and mounts the details-panel Livewire components', function () {
    [$site, $agent] = sectionSite();

    $this->actingAs($agent)
        ->get(route('sites.show', $site))
        ->assertOk()
        ->assertSeeLivewire('google-reviews-panel')
        ->assertSeeLivewire('enquiries-inbox');
});

it('each section returns 200 and mounts its Livewire component', function (string $section, array $components) {
    config()->set('site.use_versioned_renderer', true);

    $shopSection = $section === 'ops';
    [$site, $agent] = $shopSection
        ? sectionShopSite($section === 'ops')
        : sectionSite($section === 'ops');

    $response = $this->actingAs($agent)
        ->get(route('sites.section', ['site' => $site, 'section' => $section]))
        ->assertOk();

    foreach ($components as $component) {
        $response->assertSeeLivewire($component);
    }
})->with([
    ['pages', ['page-manager']],
    ['design', ['design-panel', 'layout-picker', 'preview-toggles', 'hero-size-picker', 'logo-picker', 'page-layout-settings', 'logo-size-settings', 'header-style-settings', 'shop.product-fact-groups', 'shop.product-reviews-settings']],
    ['navigation', ['nav-manager']],
    ['personalise', ['personalise-tab']],
    ['chatbot', ['chatbot-manager']],
    ['history', ['site.version-history']],
    ['settings', ['site-domains', 'site-paid-flag', 'site-shop-enabled', 'site-cost-cap', 'site.watermark-toggle']],
    ['enquiries', ['enquiries-inbox']],
    ['ops', ['shop.ai-seed-panel', 'shop.observability-dashboard']],
]);

it('tab=design on the site URL redirects 301 to the design section', function () {
    [$site, $agent] = sectionSite();

    $this->actingAs($agent)
        ->get(route('sites.show', ['site' => $site, 'tab' => 'design']))
        ->assertRedirect(route('sites.section', ['site' => $site, 'section' => 'design']))
        ->assertStatus(301);
});

it('tab=details on the site URL redirects 301 to overview', function () {
    [$site, $agent] = sectionSite();

    $this->actingAs($agent)
        ->get(route('sites.show', ['site' => $site, 'tab' => 'details']))
        ->assertRedirect(route('sites.show', $site))
        ->assertStatus(301);
});

it('an unknown section 404s', function () {
    [$site, $agent] = sectionSite();

    $this->actingAs($agent)
        ->get('/sites/'.$site->id.'/not-a-section')
        ->assertNotFound();
});

it('the Enquiries sidebar item is present on every site and current on that route', function () {
    [$site, $agent] = sectionSite();
    $href = route('sites.section', ['site' => $site, 'section' => 'enquiries']);

    $pages = $this->actingAs($agent)
        ->get(route('sites.section', ['site' => $site, 'section' => 'pages']))
        ->assertOk()
        ->getContent();

    expect($pages)->toContain($href)
        ->and($pages)->toContain('Enquiries');

    $html = $this->actingAs($agent)
        ->get($href)
        ->assertOk()
        ->assertSeeLivewire('enquiries-inbox')
        ->getContent();

    expect($html)->toMatch('/data-current[^>]*href="'.preg_quote($href, '/').'"|href="'.preg_quote($href, '/').'"[^>]*data-current/');
});

it('shop and orders are reachable on a shopless site (a first category is created there); ops is admin-only', function () {
    [$site, $agent] = sectionSite();

    $this->actingAs($agent)
        ->get(route('sites.shop.products', $site))
        ->assertOk();
    $this->actingAs($agent)
        ->get(route('sites.shop.orders', $site))
        ->assertOk();
    $this->actingAs($agent)
        ->get(route('sites.section', ['site' => $site, 'section' => 'ops']))
        ->assertNotFound();

    [$adminSite, $admin] = sectionSite(admin: true);
    $this->actingAs($admin)
        ->get(route('sites.section', ['site' => $adminSite, 'section' => 'ops']))
        ->assertOk();
});

it('guests are redirected to login from overview and a section', function () {
    [$site] = sectionSite();

    $this->get(route('sites.show', $site))
        ->assertRedirect(route('agent.login'));

    $this->get(route('sites.section', ['site' => $site, 'section' => 'pages']))
        ->assertRedirect(route('agent.login'));
});

it('overview omits settings-class components', function () {
    [$site, $agent] = sectionSite();

    $this->actingAs($agent)
        ->get(route('sites.show', $site))
        ->assertOk()
        ->assertSeeLivewire('google-reviews-panel')
        ->assertSeeLivewire('enquiries-inbox')
        ->assertDontSeeLivewire('site-domains')
        ->assertDontSeeLivewire('site-paid-flag')
        ->assertDontSeeLivewire('site-shop-enabled')
        ->assertDontSeeLivewire('site-cost-cap')
        ->assertDontSeeLivewire('site.watermark-toggle')
        ->assertDontSeeLivewire('page-manager');
});

it('settings mounts configuration components and not page widgets', function () {
    [$site, $agent] = sectionSite();

    $this->actingAs($agent)
        ->get(route('sites.section', ['site' => $site, 'section' => 'settings']))
        ->assertOk()
        ->assertSeeLivewire('site-domains')
        ->assertSeeLivewire('site-paid-flag')
        ->assertSeeLivewire('site-shop-enabled')
        ->assertSeeLivewire('site-cost-cap')
        ->assertSeeLivewire('site.watermark-toggle')
        ->assertDontSeeLivewire('google-reviews-panel')
        ->assertDontSeeLivewire('page-manager');
});

it('section pages share the sites page chrome with breadcrumb (full width since T39)', function () {
    [$site, $agent] = sectionSite();

    $html = $this->actingAs($agent)
        ->get(route('sites.section', ['site' => $site, 'section' => 'pages']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain(__('Sites'))
        ->and($html)->toContain($site->business_name)
        ->and($html)->not->toContain('max-w-6xl')
        ->and($html)->not->toContain("x-on:click=\"tab = 'pages'\"");
});

it('design keeps its inner pill alpine', function () {
    [$site, $agent] = sectionSite();

    $html = $this->actingAs($agent)
        ->get(route('sites.section', ['site' => $site, 'section' => 'design']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain("designPill === 'layout'")
        ->toContain("designPill === 'header'")
        ->toContain('About Page Layout')
        ->toContain('Chrome &amp; type');
});

it('the site group is absent on the sites index and dashboard', function () {
    [$site, $agent] = sectionSite();
    $pagesHref = route('sites.section', ['site' => $site, 'section' => 'pages']);

    $this->actingAs($agent)
        ->get(route('sites.index'))
        ->assertOk()
        ->assertDontSee($pagesHref, false);

    $this->actingAs($agent)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee($pagesHref, false);
});

it('the site group is present on a section page with the current item marked', function () {
    [$site, $agent] = sectionSite();
    $pagesHref = route('sites.section', ['site' => $site, 'section' => 'pages']);
    $settingsHref = route('sites.section', ['site' => $site, 'section' => 'settings']);

    $html = $this->actingAs($agent)
        ->get(route('sites.section', ['site' => $site, 'section' => 'pages']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain($site->business_name)
        ->and($html)->toContain($pagesHref)
        ->and($html)->toContain($settingsHref)
        ->and($html)->toMatch('/data-current[^>]*href="'.preg_quote($pagesHref, '/').'"|href="'.preg_quote($pagesHref, '/').'"[^>]*data-current/');
});

it('shop and orders sidebar items are present for every site; ops only for admins', function () {
    [$plain, $agent] = sectionSite();

    $plainHtml = $this->actingAs($agent)
        ->get(route('sites.section', ['site' => $plain, 'section' => 'pages']))
        ->assertOk()
        ->getContent();

    expect($plainHtml)->toContain(route('sites.shop.products', $plain))
        ->and($plainHtml)->toContain(route('sites.shop.orders', $plain))
        ->and($plainHtml)->not->toContain(route('sites.section', ['site' => $plain, 'section' => 'ops']));

    [$adminSite, $admin] = sectionSite(admin: true);
    $adminHtml = $this->actingAs($admin)
        ->get(route('sites.section', ['site' => $adminSite, 'section' => 'pages']))
        ->assertOk()
        ->getContent();
    expect($adminHtml)->toContain(route('sites.section', ['site' => $adminSite, 'section' => 'ops']));
});

it('settings markup contains the save-bar and a marked client assignment form', function () {
    [$site, $agent] = sectionSite();

    $html = $this->actingAs($agent)
        ->get(route('sites.section', ['site' => $site, 'section' => 'settings']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-save-bar')
        ->and($html)->toContain(__('Unsaved changes'))
        ->and($html)->toContain(__('Discard'))
        ->and($html)->toContain(__('Save'))
        ->and($html)->toContain(route('sites.assignClient', $site));
});

test('assigning a client returns the agent to the settings section', function () {
    [$site, $admin] = sectionSite(admin: true);
    $client = Client::factory()->create();

    $this->actingAs($admin)
        ->patch(route('sites.assignClient', $site), ['client_id' => $client->id])
        ->assertRedirect(route('sites.section', ['site' => $site, 'section' => 'settings']));
});

/**
 * First <ui-disclosure> whose heading text matches.
 */
function agentsSidebarGroup(string $html, string $heading): string
{
    preg_match_all('/<ui-disclosure\b[^>]*>.*?<\/ui-disclosure>/s', $html, $matches);

    foreach ($matches[0] as $block) {
        if (str_contains($block, '>'.$heading.'<') || str_contains($block, '>'.e($heading).'<')) {
            return $block;
        }
    }

    expect($matches[0] ?? [])->not->toBeEmpty("no ui-disclosure with heading {$heading}");

    return '';
}

function agentsSidebarGroupOpenTag(string $group): string
{
    $start = strpos($group, '<ui-disclosure');
    if ($start === false) {
        return '';
    }

    $depth = 0;
    $quote = null;
    $length = strlen($group);
    for ($i = $start; $i < $length; $i++) {
        $ch = $group[$i];
        if ($quote !== null) {
            if ($ch === $quote) {
                $quote = null;
            }

            continue;
        }
        if ($ch === '"' || $ch === "'") {
            $quote = $ch;
        } elseif ($ch === '[') {
            $depth++;
        } elseif ($ch === ']') {
            $depth = max(0, $depth - 1);
        } elseif ($ch === '>' && $depth === 0) {
            return substr($group, $start, $i - $start + 1);
        }
    }

    return '';
}

it('nests content sections under an expandable Content group that opens when a child is current', function () {
    [$site, $agent] = sectionSite();

    $html = $this->actingAs($agent)
        ->get(route('sites.section', ['site' => $site, 'section' => 'pages']))
        ->assertOk()
        ->getContent();

    $content = agentsSidebarGroup($html, __('Content'));

    expect($html)->toContain('siteworks.nav.content')
        ->and($content)->toContain('open')
        ->and($content)->toContain('data-nav-current="1"')
        ->and($content)->toContain(route('sites.section', ['site' => $site, 'section' => 'pages']))
        ->and($content)->toContain(route('sites.section', ['site' => $site, 'section' => 'navigation']))
        ->and($content)->toContain(route('sites.section', ['site' => $site, 'section' => 'design']))
        ->and($content)->toContain(route('sites.section', ['site' => $site, 'section' => 'personalise']))
        ->and($content)->toContain(route('sites.section', ['site' => $site, 'section' => 'chatbot']));
});

it('carries the request current-child as the Flux group initial-open value', function () {
    [$site, $agent] = sectionSite();

    $html = $this->actingAs($agent)
        ->get(route('sites.section', ['site' => $site, 'section' => 'pages']))
        ->assertOk()
        ->getContent();

    $content = agentsSidebarGroup($html, __('Content'));
    $openTag = agentsSidebarGroupOpenTag($content);

    expect($content)->toContain('data-nav-current="1"')
        ->and($openTag)->toMatch('/\sopen[\s>]/');
});

it('leaves Content closed on Overview so stored-closed can restore', function () {
    [$site, $agent] = sectionSite();

    $html = $this->actingAs($agent)
        ->get(route('sites.show', $site))
        ->assertOk()
        ->getContent();

    $content = agentsSidebarGroup($html, __('Content'));
    $openTag = agentsSidebarGroupOpenTag($content);

    expect($content)->toContain('data-nav-current="0"')
        ->and($openTag)->not->toMatch('/\sopen[\s>]/');
});

it('stamps nav group open classes from localStorage in the layout head before Flux init', function () {
    [$site, $agent] = sectionSite();

    $html = $this->actingAs($agent)
        ->get(route('sites.show', $site))
        ->assertOk()
        ->getContent();

    expect($html)->toContain("localStorage.getItem('siteworks.nav.content')")
        ->and($html)->toContain("localStorage.getItem('siteworks.nav.shop')")
        ->and($html)->toContain('nav-content-open')
        ->and($html)->toContain('nav-shop-open')
        ->and($html)->toContain("classList.contains('nav-'")
        ->and($html)->toContain("setAttribute('open'");
});

it('highlights the current parent row with the same weight as an active item', function () {
    [$site, $agent] = sectionShopSite();

    $html = $this->actingAs($agent)
        ->get(route('sites.shop.products', $site))
        ->assertOk()
        ->getContent();

    $shop = agentsSidebarGroup($html, __('Shop'));

    expect($shop)->toMatch('/data-current="true"|data-current(?:=["\']true["\'])?/')
        ->and($shop)->toContain('data-[current=true]:[&>button]:bg-white')
        ->and($shop)->toContain('data-[current=true]:[&>button]:text-(--color-accent-content)');
});

it('nests shop destinations under an expandable Shop group that opens when a child is current', function () {
    [$site, $agent] = sectionShopSite();

    $html = $this->actingAs($agent)
        ->get(route('sites.shop.products', $site))
        ->assertOk()
        ->getContent();

    $shop = agentsSidebarGroup($html, __('Shop'));

    expect($html)->toContain('siteworks.nav.shop')
        ->and($shop)->toContain('open')
        ->and($shop)->toContain('data-nav-current="1"')
        ->and($shop)->toContain(__('Products'))
        ->and($shop)->toContain(__('Categories'))
        ->and($shop)->toContain(__('Orders'))
        ->and($shop)->toContain(__('Storefront'))
        ->and($shop)->not->toContain(__('Ops'));
});

it('opens the Shop group and highlights it when Orders is current', function () {
    [$site, $agent] = sectionShopSite();

    $html = $this->actingAs($agent)
        ->get(route('sites.shop.orders', $site))
        ->assertOk()
        ->getContent();

    $shop = agentsSidebarGroup($html, __('Shop'));
    $content = agentsSidebarGroup($html, __('Content'));

    expect($shop)->toContain('open')
        ->and($shop)->toContain('data-nav-current="1"')
        ->and($content)->toContain('data-nav-current="0"')
        ->and($content)->not->toContain('data-nav-current="1"');
});

it('hides the Shop group when the flag is off and the site has no orders', function () {
    [$site, $agent] = sectionSite();
    $site->update(['shop_enabled' => false]);

    $html = $this->actingAs($agent)
        ->get(route('sites.section', ['site' => $site, 'section' => 'pages']))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('data-nav-persist="siteworks.nav.shop"')
        ->and($html)->not->toContain(route('sites.shop.products', $site))
        ->and($html)->not->toContain(route('sites.shop.orders', $site));
});

it('keeps Orders in the Shop group when the flag is off but the site has taken orders', function () {
    [$site, $agent] = sectionShopSite();
    Order::create([
        'site_id' => $site->id,
        'number' => 'NAV-OWED-1',
        'email' => 'buyer@example.com',
        'name' => 'Buyer',
        'status' => OrderStatus::Paid->value,
        'refund_status' => 'none',
        'subtotal_cents' => 900,
        'shipping_cents' => 0,
        'tax_cents' => 0,
        'shipping_tax_cents' => 0,
        'total_cents' => 900,
        'tax_country_code' => 'GB',
        'shipping_address_json' => [],
        'shipping_method_label' => 'Std',
        'placed_at' => now(),
    ]);
    $site->update(['shop_enabled' => false]);

    $html = $this->actingAs($agent)
        ->get(route('sites.section', ['site' => $site, 'section' => 'pages']))
        ->assertOk()
        ->getContent();

    $shop = agentsSidebarGroup($html, __('Shop'));

    expect($shop)->toContain(route('sites.shop.orders', $site))
        ->and($shop)->not->toContain(__('Products'))
        ->and($shop)->not->toContain(__('Storefront'));
});

it('shows Ops inside the Shop group for admins only', function () {
    [$site, $admin] = sectionShopSite(admin: true);

    $html = $this->actingAs($admin)
        ->get(route('sites.section', ['site' => $site, 'section' => 'pages']))
        ->assertOk()
        ->getContent();

    $shop = agentsSidebarGroup($html, __('Shop'));

    expect($shop)->toContain(route('sites.section', ['site' => $site, 'section' => 'ops']))
        ->and($shop)->toContain(__('Ops'));
});
