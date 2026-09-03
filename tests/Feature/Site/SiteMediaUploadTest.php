<?php

use App\Exceptions\UnsupportedImageException;
use App\Models\Client;
use App\Models\Site;
use App\Models\SiteMedia;
use App\Models\User;
use App\Services\Images\ImageOptimiserService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    // TODO(sso-future): remove when site management routes move to agent domain.
    $this->withoutMiddleware(\App\Http\Middleware\EnsureClientUser::class);
});

/**
 * Minimal valid 1×1 PNG (67 bytes) — avoids needing the GD extension in CI.
 */
function minimalPng(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    );
}

test('valid PNG upload returns 200 with url', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);

    $file = UploadedFile::fake()->createWithContent('photo.png', minimalPng());

    $this->actingAs($user)
        ->postJson(route('site.admin.media-upload', ['site' => $site->id]), ['file' => $file])
        ->assertOk()
        ->assertJsonStructure(['path', 'url']);
});

test('SVG upload is rejected with 422', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);

    $svg = UploadedFile::fake()->createWithContent(
        'evil.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    );

    $this->actingAs($user)
        ->postJson(route('site.admin.media-upload', ['site' => $site->id]), ['file' => $svg])
        ->assertUnprocessable();
});

test('an ingest rejection carries the complete typed receipt envelope', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $file = UploadedFile::fake()->createWithContent('photo.png', minimalPng());

    $this->mock(ImageOptimiserService::class, function ($mock): void {
        $mock->shouldReceive('optimise')
            ->once()
            ->andThrow(new UnsupportedImageException('Distinct ingest rejection'));
    });

    $this->actingAs($user)
        ->postJson(route('site.admin.media-upload', ['site' => $site->id]), ['file' => $file])
        ->assertUnprocessable()
        ->assertExactJson([
            'ok' => false,
            'error' => ['code' => 'validation', 'message' => 'Distinct ingest rejection'],
            'state' => [
                'site_id' => $site->id,
                'page_id' => null,
                'draft_revision_id' => null,
                'composition_revision' => 0,
                'pending_publish' => false,
                'structure_epoch' => null,
            ],
            'receipt' => [
                'new_revision' => null,
                'effective' => null,
                'changed' => [],
                'warnings' => [],
                'publishable' => false,
                'preview' => 'not_applicable',
            ],
            'message' => 'Distinct ingest rejection',
        ]);
});

test('unauthenticated upload is rejected', function () {
    $site = Site::factory()->create();

    $file = UploadedFile::fake()->createWithContent('photo.png', minimalPng());

    $this->postJson(route('site.admin.media-upload', ['site' => $site->id]), ['file' => $file])
        ->assertUnauthorized();
});

test('unauthorised user cannot upload to another owners site', function () {
    $owner = User::factory()->staff()->create();
    $other = User::factory()->create(['client_id' => null, 'role' => null]);
    $site = Site::factory()->create(['client_id' => null]);

    $file = UploadedFile::fake()->createWithContent('photo.png', minimalPng());

    $this->actingAs($other)
        ->postJson(route('site.admin.media-upload', ['site' => $site->id]), ['file' => $file])
        ->assertForbidden(); // role=null users reach the policy layer, which 403s (agent.only only redirects when the route is on the agent subdomain)
});

/*
 * Third instance of the channel-laundering defect (with edit_field and update_form): tools.js maps
 * `upload_image` to this route's JSON/base64 path, and the controller hardcoded ActorChannel::Ui — so an
 * agent upload skipped the role allowlist (AgentToolsGate short-circuits to true for Ui) and was audited
 * as a human upload.
 */
test('an agent upload is audited as webmcp on behalf of the human', function () {
    config([
        'editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true,
        'editor.agent_tools.roles' => ['staff'],
    ]);
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->withHeaders(['X-Editor-Channel' => 'webmcp'])
        ->postJson(route('site.admin.media-upload', ['site' => $site->id]), [
            'data_base64' => base64_encode(minimalPng()),
            'composition_revision' => 0,
        ]);

    $response->assertOk();

    $this->assertDatabaseHas('editor_operation_log', [
        'site_id' => $site->id,
        'operation' => 'upload_image',
        'actor_user_id' => $user->id,
        'actor_channel' => 'webmcp',
        'result_code' => 'ok',
    ]);
});

test('an agent upload is refused when the actor role is outside agent_tools.roles', function () {
    config([
        'editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true,
        'editor.agent_tools.roles' => ['client'],
    ]);
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);

    $this->actingAs($user)
        ->withHeaders(['X-Editor-Channel' => 'webmcp'])
        ->postJson(route('site.admin.media-upload', ['site' => $site->id]), [
            'data_base64' => base64_encode(minimalPng()),
            'composition_revision' => 0,
        ])
        ->assertForbidden()
        ->assertExactJson([
            'ok' => false,
            'error' => ['code' => 'forbidden', 'message' => 'Agent tools are disabled for this actor.'],
            'state' => [
                'site_id' => $site->id,
                'page_id' => null,
                'draft_revision_id' => null,
                'composition_revision' => 0,
                'pending_publish' => false,
                'structure_epoch' => null,
            ],
            'receipt' => [
                'new_revision' => null,
                'effective' => null,
                'changed' => [],
                'warnings' => [],
                'publishable' => false,
                'preview' => 'not_applicable',
            ],
        ]);
});

test('a human upload is still recorded as the ui channel', function () {
    config([
        'editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true,
        'editor.agent_tools.roles' => ['staff'],
    ]);
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson(route('site.admin.media-upload', ['site' => $site->id]), [
            'data_base64' => base64_encode(minimalPng()),
            'composition_revision' => 0,
        ])
        ->assertOk();

    $this->assertDatabaseHas('editor_operation_log', [
        'site_id' => $site->id,
        'operation' => 'upload_image',
        'actor_channel' => 'ui',
    ]);
});

/**
 * @return array{0: User, 1: Site}
 */
function clientMediaUploadSite(): array
{
    $tenant = Client::factory()->create();
    $actor = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'email_verified_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);

    return [$actor, $site];
}

test('a client multipart upload is refused when the client-portal setting is off', function () {
    config([
        'editor.operations.enabled' => true,
        'editor.agent_tools.enabled' => true,
        'editor.agent_tools.roles' => ['staff', 'client'],
        'editor.agent_tools.client_portal_enabled' => false,
    ]);
    [$actor, $site] = clientMediaUploadSite();
    $file = UploadedFile::fake()->createWithContent('photo.png', minimalPng());

    $this->actingAs($actor)
        ->withHeaders(['X-Editor-Channel' => 'webmcp'])
        ->postJson(route('site.admin.media-upload', ['site' => $site->id]), ['file' => $file])
        ->assertForbidden()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'forbidden')
        ->assertJsonPath('error.message', 'Agent tools are disabled for this actor.');

    expect(SiteMedia::query()->where('site_id', $site->id)->exists())->toBeFalse();
});

test('a client multipart upload without an editor channel header is refused when the portal setting is off', function () {
    config([
        'editor.operations.enabled' => true,
        'editor.agent_tools.enabled' => true,
        'editor.agent_tools.roles' => ['staff', 'client'],
        'editor.agent_tools.client_portal_enabled' => false,
    ]);
    [$actor, $site] = clientMediaUploadSite();
    $file = UploadedFile::fake()->createWithContent('photo.png', minimalPng());

    $this->actingAs($actor)
        ->postJson(route('site.admin.media-upload', ['site' => $site->id]), ['file' => $file])
        ->assertForbidden()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'forbidden')
        ->assertJsonPath('error.message', 'Agent tools are disabled for this actor.');

    expect(SiteMedia::query()->where('site_id', $site->id)->exists())->toBeFalse();
});

test('a client multipart upload is allowed when the portal channel is open', function () {
    config([
        'editor.operations.enabled' => true,
        'editor.agent_tools.enabled' => true,
        'editor.agent_tools.roles' => ['staff', 'client'],
        'editor.agent_tools.client_portal_enabled' => true,
    ]);
    [$actor, $site] = clientMediaUploadSite();
    $file = UploadedFile::fake()->createWithContent('photo.png', minimalPng());

    $this->actingAs($actor)
        ->withHeaders(['X-Editor-Channel' => 'webmcp'])
        ->postJson(route('site.admin.media-upload', ['site' => $site->id]), ['file' => $file])
        ->assertOk()
        ->assertJsonStructure(['path', 'url']);

    expect(SiteMedia::query()->where('site_id', $site->id)->exists())->toBeTrue();
});

test('a client of a different tenant cannot upload media to this site', function () {
    config([
        'editor.operations.enabled' => true,
        'editor.agent_tools.enabled' => true,
        'editor.agent_tools.roles' => ['staff', 'client'],
        'editor.agent_tools.client_portal_enabled' => true,
    ]);
    [, $site] = clientMediaUploadSite();
    [$stranger] = clientMediaUploadSite();
    $file = UploadedFile::fake()->createWithContent('photo.png', minimalPng());

    $this->actingAs($stranger)
        ->withHeaders(['X-Editor-Channel' => 'webmcp'])
        ->postJson(route('site.admin.media-upload', ['site' => $site->id]), ['file' => $file])
        ->assertForbidden();

    expect(SiteMedia::query()->where('site_id', $site->id)->exists())->toBeFalse();
});

test('staff multipart upload is unchanged when the client-portal setting is off', function () {
    config([
        'editor.operations.enabled' => true,
        'editor.agent_tools.enabled' => true,
        'editor.agent_tools.roles' => ['staff'],
        'editor.agent_tools.client_portal_enabled' => false,
    ]);
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $file = UploadedFile::fake()->createWithContent('photo.png', minimalPng());

    $this->actingAs($user)
        ->postJson(route('site.admin.media-upload', ['site' => $site->id]), ['file' => $file])
        ->assertOk()
        ->assertJsonStructure(['path', 'url']);
});
