<?php

use App\Enums\AgentRole;
use App\Http\Middleware\EnsureClientUser;
use App\Models\Site;
use App\Models\User;

// Sites routes live on the agent subdomain (agent.only). Bypass EnsureClientUser
// so tests run without a domain-level SSO redirect.
// TODO(sso-future): remove when site management routes move to agent domain.
beforeEach(function () {
    $this->withoutMiddleware(EnsureClientUser::class);
});

test('unauthenticated user is redirected to login from /sites', function () {
    $this->get(route('sites.index'))->assertRedirect(route('agent.login'));
});

test('authenticated agent sees their own sites but not other agents sites', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $other = User::factory()->staff(AgentRole::Agent)->create();

    Site::factory()->create(['created_by_user_id' => $agent->id, 'business_name' => 'My Plumbing']);
    Site::factory()->create(['created_by_user_id' => $other->id, 'business_name' => 'Their Roofing']);

    $this->actingAs($agent)
        ->get(route('sites.index'))
        ->assertOk()
        ->assertSee('My Plumbing')
        ->assertDontSee('Their Roofing');
});

test('q param filters by business_name case-insensitively', function () {
    $agent = User::factory()->staff()->create();

    Site::factory()->create(['created_by_user_id' => $agent->id, 'business_name' => 'Acme Roofing']);
    Site::factory()->create(['created_by_user_id' => $agent->id, 'business_name' => 'Beta Builders']);

    $this->actingAs($agent)
        ->get(route('sites.index', ['q' => 'acme']))
        ->assertOk()
        ->assertSee('Acme Roofing')
        ->assertDontSee('Beta Builders');
});

test('agent param filters by created_by_user_id', function () {
    $admin = User::factory()->staff(AgentRole::Admin)->create();
    $agentA = User::factory()->staff(AgentRole::Agent)->create(['name' => 'Agent Alpha']);
    $agentB = User::factory()->staff(AgentRole::Agent)->create(['name' => 'Agent Beta']);

    Site::factory()->create(['created_by_user_id' => $agentA->id, 'business_name' => 'Alpha Site']);
    Site::factory()->create(['created_by_user_id' => $agentB->id, 'business_name' => 'Beta Site']);

    $this->actingAs($admin)
        ->get(route('sites.index', ['agent' => $agentA->id]))
        ->assertOk()
        ->assertSee('Alpha Site')
        ->assertDontSee('Beta Site');
});

test('type param filters by site_type slug', function () {
    $agent = User::factory()->staff()->create();

    Site::factory()->create(['created_by_user_id' => $agent->id, 'business_name' => 'Splash Plumbing', 'site_type' => 'plumber']);
    Site::factory()->create(['created_by_user_id' => $agent->id, 'business_name' => 'Bright Electric', 'site_type' => 'electrician']);

    $this->actingAs($agent)
        ->get(route('sites.index', ['type' => 'plumber']))
        ->assertOk()
        ->assertSee('Splash Plumbing')
        ->assertDontSee('Bright Electric');
});

test('type=none matches sites with null site_type', function () {
    $agent = User::factory()->staff()->create();

    Site::factory()->create(['created_by_user_id' => $agent->id, 'business_name' => 'No Type Ltd', 'site_type' => null]);
    Site::factory()->create(['created_by_user_id' => $agent->id, 'business_name' => 'Typed Ltd', 'site_type' => 'plumber']);

    $this->actingAs($agent)
        ->get(route('sites.index', ['type' => 'none']))
        ->assertOk()
        ->assertSee('No Type Ltd')
        ->assertDontSee('Typed Ltd');
});

test('region param filters by region slug and none matches null', function () {
    $agent = User::factory()->staff()->create();

    Site::factory()->create(['created_by_user_id' => $agent->id, 'business_name' => 'York Works', 'region' => 'yorkshire']);
    Site::factory()->create(['created_by_user_id' => $agent->id, 'business_name' => 'London Works', 'region' => 'london']);
    Site::factory()->create(['created_by_user_id' => $agent->id, 'business_name' => 'Nowhere Works', 'region' => null]);

    $this->actingAs($agent)
        ->get(route('sites.index', ['region' => 'yorkshire']))
        ->assertOk()
        ->assertSee('York Works')
        ->assertDontSee('London Works')
        ->assertDontSee('Nowhere Works');

    $this->actingAs($agent)
        ->get(route('sites.index', ['region' => 'none']))
        ->assertOk()
        ->assertSee('Nowhere Works')
        ->assertDontSee('York Works')
        ->assertDontSee('London Works');
});

test('type and region filter options come from config not DISTINCT free text', function () {
    $agent = User::factory()->staff()->create();

    Site::factory()->create([
        'created_by_user_id' => $agent->id,
        'business_name' => 'Oddball Co',
        'business_type' => 'UniqueTypeXYZ123',
        'location' => 'UniqueLocXYZ123',
        'site_type' => null,
        'region' => null,
    ]);

    $this->actingAs($agent)
        ->get(route('sites.index'))
        ->assertOk()
        ->assertSee('Driveways & Patios')
        ->assertSee('SaaS')
        ->assertSee('Unclassified')
        ->assertSee('North East')
        ->assertSee('Yorkshire')
        ->assertDontSee('UniqueTypeXYZ123')
        ->assertDontSee('UniqueLocXYZ123')
        ->assertDontSee('name="location"', false);
});

test('type column shows the site_type label or a dash', function () {
    $agent = User::factory()->staff()->create();

    Site::factory()->create(['created_by_user_id' => $agent->id, 'business_name' => 'Labelled Co', 'site_type' => 'saas']);
    Site::factory()->create(['created_by_user_id' => $agent->id, 'business_name' => 'Dash Co', 'site_type' => null]);

    $html = $this->actingAs($agent)
        ->get(route('sites.index'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Labelled Co')
        ->and($html)->toContain('SaaS')
        ->and($html)->toContain('Dash Co')
        ->and($html)->toContain('—');
});

test('sort=site_type&dir=asc orders rows by site_type slug', function () {
    $agent = User::factory()->staff()->create();

    Site::factory()->create(['created_by_user_id' => $agent->id, 'business_name' => 'Zed Co', 'site_type' => 'roofer']);
    Site::factory()->create(['created_by_user_id' => $agent->id, 'business_name' => 'Aye Co', 'site_type' => 'plumber']);

    $this->actingAs($agent)
        ->get(route('sites.index', ['sort' => 'site_type', 'dir' => 'asc']))
        ->assertOk()
        ->assertSeeInOrder(['Aye Co', 'Zed Co']);
});

test('from and to params filter by created_at date range', function () {
    $agent = User::factory()->staff()->create();

    Site::factory()->create([
        'created_by_user_id' => $agent->id,
        'business_name' => 'Early Bird',
        'created_at' => '2025-06-15',
    ]);
    Site::factory()->create([
        'created_by_user_id' => $agent->id,
        'business_name' => 'Late Comer',
        'created_at' => '2027-03-10',
    ]);

    $this->actingAs($agent)
        ->get(route('sites.index', ['from' => '2026-01-01', 'to' => '2026-12-31']))
        ->assertOk()
        ->assertDontSee('Early Bird')
        ->assertDontSee('Late Comer');
});

test('sort=business_name&dir=asc orders rows alphabetically', function () {
    $agent = User::factory()->staff()->create();

    Site::factory()->create(['created_by_user_id' => $agent->id, 'business_name' => 'Zeta Works']);
    Site::factory()->create(['created_by_user_id' => $agent->id, 'business_name' => 'Acme Corp']);

    $this->actingAs($agent)
        ->get(route('sites.index', ['sort' => 'business_name', 'dir' => 'asc']))
        ->assertOk()
        ->assertSeeInOrder(['Acme Corp', 'Zeta Works']);
});

test('sort=invalid fails validation', function () {
    $agent = User::factory()->staff()->create();

    // Web GET requests redirect back with session errors on validation failure
    $this->actingAs($agent)
        ->get(route('sites.index', ['sort' => 'invalid']))
        ->assertSessionHasErrors(['sort']);
});

test('pagination links carry the query string when filters are active', function () {
    $agent = User::factory()->staff(AgentRole::Admin)->create();

    // Create 25 sites all matching "acme" so pagination kicks in (>20 per page)
    Site::factory()->count(25)->create([
        'created_by_user_id' => $agent->id,
        'business_name' => 'Acme Company',
    ]);

    $response = $this->actingAs($agent)
        ->get(route('sites.index', ['q' => 'acme']))
        ->assertOk();

    $response->assertSee('q=acme', escape: false);
});

test('combined filters work without error', function () {
    $agent = User::factory()->staff()->create();

    Site::factory()->create([
        'created_by_user_id' => $agent->id,
        'business_name' => 'Acme Roofing',
        'business_type' => 'roofing',
        'site_type' => 'roofer',
    ]);

    $this->actingAs($agent)
        ->get(route('sites.index', [
            'q' => 'acme',
            'type' => 'roofer',
            'sort' => 'business_name',
            'dir' => 'asc',
        ]))
        ->assertOk()
        ->assertSee('Acme Roofing');
});
