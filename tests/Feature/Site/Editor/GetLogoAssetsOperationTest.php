<?php

use App\Enums\AgentRole;
use App\Enums\LogoAssetVariant;
use App\Models\Client;
use App\Models\LogoConcept;
use App\Models\Site;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\CommerceOperations;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\Operations\GetLogoAssetsOperation;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
    Storage::fake('s3');
});

/** 4×4 PNG used by the existing upload ingest tests. */
function logoAssetsPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAQAAAAEAQMAAACTPww9AAAAIGNIUk0AAHomAACAhAAA+gAAAIDoAAB1MAAA6mAAADqYAAAXcJy6UTwAAAAGUExURf8AAP///0EdNBEAAAABYktHRAH/Ai3eAAAAB3RJTUUH6ggcBTkHx5/rUAAAAAtJREFUCNdjYIAAAAAIAAEvIN0xAAAAAElFTkSuQmCC', true);
}

/**
 * @return array{0: User, 1: Site}
 */
function logoAssetsStaffSite(): array
{
    $actor = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $actor->id]);

    return [$actor, $site];
}

function openLogoAssetsClientChannel(): void
{
    config([
        'editor.agent_tools.roles' => ['staff', 'client'],
        'editor.agent_tools.client_portal_enabled' => true,
    ]);
}

function putLogoPng(LogoConcept $concept): string
{
    $bytes = logoAssetsPng();
    Storage::disk('s3')->put($concept->path, $bytes);

    return $bytes;
}

it('is read-only, site-addressed, and staff+client by allowedRoles', function () {
    $operation = app(GetLogoAssetsOperation::class);

    expect($operation->readOnly())->toBeTrue()
        ->and($operation->address())->toBe('site')
        ->and($operation->allowedRoles())->toBe(['staff', 'client'])
        ->and($operation->wrapInAdminChange())->toBeFalse();
});

it('names get_logo_assets on the sandbox exposure set and the client SANDBOX allowlist', function () {
    expect(config('editor.exposure.sets.sandbox'))->toContain('get_logo_assets')
        ->and(CommerceOperations::SANDBOX)->toContain('get_logo_assets');
});

it('returns has_logo false with a null active and empty variants when the site has no logo', function () {
    [$actor, $site] = logoAssetsStaffSite();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'get_logo_assets',
        [],
    );

    expect($result->ok)->toBeTrue()
        ->and($result->data)->toBe([
            'has_logo' => false,
            'active' => null,
            'variants' => [],
        ]);
});

it('returns has_logo true with a working signed url whose body matches bytes and sha256', function () {
    [$actor, $site] = logoAssetsStaffSite();
    $concept = LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/acme-selected.png',
    ]);
    $bytes = putLogoPng($concept);

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'get_logo_assets',
        [],
    );

    expect($result->ok)->toBeTrue()
        ->and($result->data['has_logo'])->toBeTrue()
        ->and($result->data['active'])->toHaveKeys([
            'download_url', 'filename', 'mime', 'bytes', 'sha256', 'expires_at', 'requires_current_session',
        ])
        ->and($result->data['active']['filename'])->toBe('acme-selected.png')
        ->and($result->data['active']['mime'])->toBe('image/png')
        ->and($result->data['active']['bytes'])->toBe(strlen($bytes))
        ->and($result->data['active']['sha256'])->toBe(hash('sha256', $bytes))
        ->and($result->data['active']['requires_current_session'])->toBeTrue()
        ->and($result->data['active']['width'])->toBe(4)
        ->and($result->data['active']['height'])->toBe(4);

    $download = $this->actingAs($actor)->get($result->data['active']['download_url']);
    $download->assertSuccessful();
    $body = $download->getContent();

    expect(hash('sha256', $body))->toBe($result->data['active']['sha256'])
        ->and(strlen($body))->toBe($result->data['active']['bytes'])
        ->and($download->headers->get('content-type'))->toContain('image/png')
        ->and($download->headers->get('content-disposition'))->toContain('acme-selected.png');
});

it('enumerates overlay and inverted variants that actually exist', function () {
    [$actor, $site] = logoAssetsStaffSite();
    $selected = LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/selected.png',
    ]);
    putLogoPng($selected);

    $overlay = LogoConcept::factory()->for($site)->create([
        'path' => 'logos/overlay.png',
        'metadata' => ['reads_on_dark' => true],
    ]);
    putLogoPng($overlay);
    $site->update(['overlay_logo_concept_id' => $overlay->id]);

    $inverted = LogoConcept::factory()->for($site)->create([
        'path' => 'logos/inverted.png',
        'metadata' => [
            'variant' => 'inverted',
            'inverted_of' => $selected->id,
            'transparent' => true,
            'reads_on_dark' => true,
        ],
    ]);
    putLogoPng($inverted);

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'get_logo_assets',
        [],
    );

    $roles = array_column($result->data['variants'], 'role');

    expect($result->ok)->toBeTrue()
        ->and($result->data['has_logo'])->toBeTrue()
        ->and($roles)->toContain('overlay')
        ->and($roles)->toContain('dark');

    foreach ($result->data['variants'] as $variant) {
        $download = $this->actingAs($actor)->get($variant['download_url']);
        $download->assertSuccessful();
        expect(hash('sha256', $download->getContent()))->toBe($variant['sha256']);
    }
});

it('mints the client-portal download URL for a client actor, not the staff agents origin', function () {
    openLogoAssetsClientChannel();
    $tenant = Client::factory()->create();
    $actor = User::factory()->create(['client_id' => $tenant->id, 'role' => null]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);
    $concept = LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/client.png',
    ]);
    putLogoPng($concept);

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'get_logo_assets',
        [],
    );

    expect($result->ok)->toBeTrue();
    $url = $result->data['active']['download_url'];

    expect($url)->toContain(config('domains.customer_domain'))
        ->and($url)->not->toContain(config('domains.agent_domain'))
        ->and($url)->toContain('/logo/'.LogoAssetVariant::Selected->value.'/download');

    $this->actingAs($actor)->get($url)->assertSuccessful();
});

it('mints the staff agents download URL for a staff actor', function () {
    [$actor, $site] = logoAssetsStaffSite();
    $concept = LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/staff.png',
    ]);
    putLogoPng($concept);

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'get_logo_assets',
        [],
    );

    expect($result->ok)->toBeTrue()
        ->and($result->data['active']['download_url'])->toContain(config('domains.agent_domain'))
        ->and($result->data['active']['download_url'])->not->toContain(config('domains.customer_domain'));
});

it('403s the signed download for a different tenant even with a valid signature', function () {
    // Mint as a CLIENT (customer-domain download route) and fetch as a different-tenant
    // client — the real cross-tenant path. (Staff mint → agents route fetched by a client
    // is an impossible surface mix in production; the op mints the client route for clients.)
    openLogoAssetsClientChannel();
    $tenant = Client::factory()->create();
    $actor = User::factory()->create(['client_id' => $tenant->id, 'role' => null]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);
    $concept = LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/secret.png',
    ]);
    putLogoPng($concept);

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'get_logo_assets',
        [],
    );

    $strangerTenant = Client::factory()->create();
    $stranger = User::factory()->create(['client_id' => $strangerTenant->id, 'role' => null]);

    $this->actingAs($stranger)->get($result->data['active']['download_url'])->assertForbidden();
});

it('refuses an expired signed logo download url', function () {
    [$actor, $site] = logoAssetsStaffSite();
    $concept = LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/expiring.png',
    ]);
    putLogoPng($concept);

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'get_logo_assets',
        [],
    );
    $this->travel(6)->minutes();

    $this->actingAs($actor)->get($result->data['active']['download_url'])->assertForbidden();
});

it('404s a path-like variant key so the download is not a path-traversal surface', function () {
    [$actor, $site] = logoAssetsStaffSite();

    $this->actingAs($actor)
        ->get('/sites/'.$site->id.'/logo/../secret/download')
        ->assertNotFound();
});

it('403s a client signed logo download after the client-portal flag is turned off', function () {
    openLogoAssetsClientChannel();
    $tenant = Client::factory()->create();
    $actor = User::factory()->create(['client_id' => $tenant->id, 'role' => null]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);
    $concept = LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/revoked.png',
    ]);
    putLogoPng($concept);

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'get_logo_assets',
        [],
    );

    expect($result->ok)->toBeTrue();
    $url = $result->data['active']['download_url'];

    config(['editor.agent_tools.client_portal_enabled' => false]);

    $this->actingAs($actor)->get($url)->assertForbidden();
});

it('still serves a staff signed logo download when the client-portal flag is off', function () {
    [$actor, $site] = logoAssetsStaffSite();
    $concept = LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/staff-flag-off.png',
    ]);
    putLogoPng($concept);

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'get_logo_assets',
        [],
    );

    expect($result->ok)->toBeTrue();

    config(['editor.agent_tools.client_portal_enabled' => false]);

    $this->actingAs($actor)->get($result->data['active']['download_url'])->assertSuccessful();
});

it('lets a client run get_logo_assets on their own site over Webmcp', function () {
    openLogoAssetsClientChannel();
    $tenant = Client::factory()->create();
    $actor = User::factory()->create(['client_id' => $tenant->id, 'role' => null]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'get_logo_assets',
        [],
    );

    expect($result->ok)->toBeTrue()
        ->and($result->data['has_logo'])->toBeFalse();
});

it('refuses a client running get_logo_assets against another tenant site', function () {
    openLogoAssetsClientChannel();
    $actor = User::factory()->create(['client_id' => Client::factory()->create()->id, 'role' => null]);
    $other = Site::factory()->create(['client_id' => Client::factory()->create()->id]);

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $other, ActorChannel::Webmcp),
        'get_logo_assets',
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
        'get_logo_assets',
        [],
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden')
        ->and($result->error['message'])->toBe('Agent tools are disabled for this actor.');
});
