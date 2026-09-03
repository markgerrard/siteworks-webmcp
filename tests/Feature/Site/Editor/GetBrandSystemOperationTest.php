<?php

use App\Enums\AgentRole;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\CommerceOperations;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\Operations\GetBrandSystemOperation;
use App\Services\Site\ThemeResolver;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
});

/**
 * @return array<string, mixed>
 */
function brandSystemBriefFixture(array $overrides = []): array
{
    return array_replace_recursive([
        'mood' => 'warm-traditional',
        'display_font' => 'fraunces',
        'body_font' => 'source-sans-3',
        'heading_scale' => 'relaxed',
        'spacing_density' => 'generous',
        'corner_style' => 'rounded',
        'palette' => [
            'primary' => '#1f3a5f',
            'accent' => '#8b6b2f',
            'tertiary' => '#f4ede0',
            'surface' => '#ffffff',
            'surface_alt' => '#f8f5ee',
            'border' => '#e4ddcf',
            'text' => '#1a1a1a',
            'text_muted' => '#6b7280',
        ],
        'rationale' => 'Heritage-led palette and serif display fit the business tone.',
    ], $overrides);
}

/**
 * @return array{0: User, 1: Site}
 */
function brandSystemStaffSite(array $siteAttrs = []): array
{
    $actor = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(array_merge([
        'created_by_user_id' => $actor->id,
        'design_brief' => brandSystemBriefFixture(),
    ], $siteAttrs));

    return [$actor, $site];
}

function openBrandSystemClientChannel(): void
{
    config([
        'editor.agent_tools.roles' => ['staff', 'client'],
        'editor.agent_tools.client_portal_enabled' => true,
    ]);
}

it('is read-only, site-addressed, and staff+client by allowedRoles', function () {
    $operation = app(GetBrandSystemOperation::class);

    expect($operation->readOnly())->toBeTrue()
        ->and($operation->address())->toBe('site')
        ->and($operation->allowedRoles())->toBe(['staff', 'client'])
        ->and($operation->wrapInAdminChange())->toBeFalse();
});

it('names get_brand_system on the sandbox exposure set and the client SANDBOX allowlist', function () {
    expect(config('editor.exposure.sets.sandbox'))->toContain('get_brand_system')
        ->and(CommerceOperations::SANDBOX)->toContain('get_brand_system');
});

it('returns palette hexes, font names, layout tokens, mood, and clean rationale from the design brief', function () {
    [$actor, $site] = brandSystemStaffSite();
    $resolver = app(ThemeResolver::class);
    $theme = $resolver->resolve($site, []);
    $tokens = $resolver->renderTokens($theme);

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'get_brand_system',
        [],
    );

    expect($result->ok)->toBeTrue()
        ->and($result->data['palette']['primary'])->toBe('#1f3a5f')
        ->and($result->data['palette']['accent'])->toBe('#8b6b2f')
        ->and($result->data['palette']['surface'])->toBe('#ffffff')
        ->and($result->data['palette']['surface_alt'])->toBe('#f8f5ee')
        ->and($result->data['palette']['text'])->toBe('#1a1a1a')
        ->and($result->data['palette']['text_muted'])->toBe('#6b7280')
        ->and($result->data['palette']['border'])->toBe('#e4ddcf')
        ->and($result->data['palette']['primary_text'])->toBe($tokens['primary_text'])
        ->and($result->data['palette']['accent_text'])->toBe($tokens['accent_text'])
        ->and($result->data['text_safe']['on_primary'])->toBe($tokens['text_on_primary'])
        ->and($result->data['text_safe']['on_accent'])->toBe($tokens['text_on_accent'])
        ->and($result->data['text_safe']['on_surface'])->toBe($tokens['text'])
        ->and($result->data['fonts']['display'])->toBe('Fraunces')
        ->and($result->data['fonts']['body'])->toBe('Source Sans 3')
        ->and($result->data['heading_scale'])->toBe('relaxed')
        ->and($result->data['spacing_density'])->toBe('generous')
        ->and($result->data['corner_style'])->toBe('rounded')
        ->and($result->data['radii']['card'])->toBe($tokens['radius_card'])
        ->and($result->data['radii']['button'])->toBe($tokens['radius_button'])
        ->and($result->data['mood'])->toBe('warm-traditional')
        ->and($result->data['rationale'])->toBe('Heritage-led palette and serif display fit the business tone.')
        ->and($result->data)->not->toHaveKey('tone');
});

it('falls back to ThemeResolver tokens and omits mood and rationale when there is no brief', function () {
    [$actor, $site] = brandSystemStaffSite(['design_brief' => null, 'theme' => 'trades-bold']);
    $resolver = app(ThemeResolver::class);
    $theme = $resolver->resolve($site, []);
    $tokens = $resolver->renderTokens($theme);

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'get_brand_system',
        [],
    );

    expect($result->ok)->toBeTrue()
        ->and($result->data['palette']['primary'])->toBe($tokens['primary'])
        ->and($result->data['fonts']['display'])->toBe('Inter')
        ->and($result->data['heading_scale'])->toBe('balanced')
        ->and($result->data)->not->toHaveKey('mood')
        ->and($result->data)->not->toHaveKey('rationale')
        ->and($result->data)->not->toHaveKey('tone');
});

it('emits tone from the business profile when present and strips internal rationale annotations', function () {
    [$actor, $site] = brandSystemStaffSite([
        'design_brief' => brandSystemBriefFixture([
            'rationale' => 'Heritage-led palette. [INTERNAL: used demo-model] Warm serif.',
        ]),
    ]);
    BusinessProfile::factory()->for($site)->create([
        'profile_data' => ['tone' => 'friendly, trustworthy'],
    ]);

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'get_brand_system',
        [],
    );

    expect($result->ok)->toBeTrue()
        ->and($result->data['tone'])->toBe('friendly, trustworthy')
        ->and($result->data['rationale'])->toBe('Heritage-led palette. Warm serif.')
        ->and($result->data['rationale'])->not->toContain('INTERNAL')
        ->and($result->data['rationale'])->not->toContain('demo-model');
});

it('only emits palette and text_safe keys that exist as hexes', function () {
    [$actor, $site] = brandSystemStaffSite();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'get_brand_system',
        [],
    );

    expect($result->ok)->toBeTrue();

    foreach ($result->data['palette'] as $key => $value) {
        expect($value)->toMatch('/^#[0-9a-fA-F]{6}$/', "palette.{$key}");
    }
    foreach ($result->data['text_safe'] as $key => $value) {
        expect($value)->toMatch('/^#[0-9a-fA-F]{6}$/', "text_safe.{$key}");
    }
});

it('lets a client run get_brand_system on their own site over Webmcp', function () {
    openBrandSystemClientChannel();
    $tenant = Client::factory()->create();
    $actor = User::factory()->create(['client_id' => $tenant->id, 'role' => null]);
    $site = Site::factory()->create([
        'client_id' => $tenant->id,
        'design_brief' => brandSystemBriefFixture(),
    ]);

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'get_brand_system',
        [],
    );

    expect($result->ok)->toBeTrue()
        ->and($result->data['palette']['primary'])->toBe('#1f3a5f');
});

it('refuses a client running get_brand_system against another tenant site', function () {
    openBrandSystemClientChannel();
    $actor = User::factory()->create(['client_id' => Client::factory()->create()->id, 'role' => null]);
    $other = Site::factory()->create(['client_id' => Client::factory()->create()->id]);

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $other, ActorChannel::Webmcp),
        'get_brand_system',
        [],
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden');
});

it('refuses a client when the portal flag is off even with the role allowlist open', function () {
    config(['editor.agent_tools.roles' => ['staff', 'client']]);
    $tenant = Client::factory()->create();
    $actor = User::factory()->create(['client_id' => $tenant->id, 'role' => null]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'get_brand_system',
        [],
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden')
        ->and($result->error['message'])->toBe('Agent tools are disabled for this actor.');
});
