<?php

/*
 * The human layer and the agent fronts answer to INDEPENDENT flags.
 *
 * Before the split, `editor.agent_tools.enabled` gated both: switching agents off also disabled the human
 * Front-1 layer, and enabling humans forced agent access on with them — so a staged rollout (humans first,
 * agents later) was impossible. These pin all four combinations, and the fall-through the split created.
 */

use App\Enums\AgentRole;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\EditorOperationLog;
use App\Models\Site\PageRevision;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\AgentToolsGate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(\App\Http\Middleware\EnsureClientUser::class);
    $this->withoutVite();
});

function flagMatrixActor(): User
{
    return User::factory()->staff(AgentRole::Agent)->create();
}

dataset('flag matrix', [
    'both off' => [false, false, false, false],
    'humans only' => [true, false, true, false],
    'agents only' => [false, true, false, true],
    'both on' => [true, true, true, true],
]);

it('gates each channel on its own flag', function (
    bool $humans,
    bool $agents,
    bool $uiAllowed,
    bool $agentAllowed,
) {
    config([
        'editor.operations.enabled' => $humans,
        'editor.agent_tools.enabled' => $agents,
        'editor.agent_tools.roles' => ['staff'],
    ]);

    $user = flagMatrixActor();
    $gate = app(AgentToolsGate::class);

    expect($gate->enabledFor($user, ActorChannel::Ui))->toBe($uiAllowed)
        ->and($gate->enabledFor($user, ActorChannel::Webmcp))->toBe($agentAllowed)
        ->and($gate->enabledFor($user, ActorChannel::Mcp))->toBe($agentAllowed);
})->with('flag matrix');

it('still role-gates agents, and never role-gates humans', function () {
    config([
        'editor.operations.enabled' => true,
        'editor.agent_tools.enabled' => true,
        'editor.agent_tools.roles' => ['client'], // staff deliberately NOT allowlisted
    ]);

    $staff = flagMatrixActor();
    $gate = app(AgentToolsGate::class);

    // a user who passes SitePolicy is a legitimate human editor — the allowlist is about agents only
    expect($gate->enabledFor($staff, ActorChannel::Ui))->toBeTrue()
        ->and($gate->enabledFor($staff, ActorChannel::Webmcp))->toBeFalse();
});

/*
 * The regression the split created, and the reason PageFieldUpdateController no longer decides delegation
 * on the Ui gate: with humans OFF and agents ON, an agent write must still reach the operations layer.
 * Deciding on the Ui gate would have dropped it into the legacy writer — no gate, no audit row — silently
 * reopening the ungated-write hole.
 */
it('sends an agent write through the layer even when the human layer is off', function () {
    config([
        'editor.operations.enabled' => false,
        'editor.agent_tools.enabled' => true,
        'editor.agent_tools.roles' => ['staff'],
    ]);

    $user = flagMatrixActor();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $content = ['sections' => [['type' => 'hero', 'title' => 'Old', 'subtitle' => 'sub']]];
    $page = GeneratedPage::create([
        'site_id' => $site->id, 'page_type' => 'home', 'content_data' => $content,
        'sort_order' => 1, 'version' => 1, 'status' => \App\Enums\PageStatus::Published,
    ]);
    $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
    $page->update(['published_revision_id' => $revision->id]);

    $this->actingAs($user)
        ->withHeaders([
            'X-Editor-Channel' => 'webmcp',
            'X-Page-Revision-Base' => (string) $revision->id,
        ])
        ->postJson("/sites/{$site->id}/pages/{$page->id}/fields", [
            'section_index' => 0,
            'field_path' => 'title',
            'value' => 'Agent title',
        ])
        ->assertOk();

    // the audit row is the proof it went through the layer rather than the legacy writer
    $this->assertDatabaseHas('editor_operation_log', [
        'site_id' => $site->id,
        'operation' => 'edit_field',
        'actor_user_id' => $user->id,
        'actor_channel' => 'webmcp',
        'result_code' => 'ok',
    ]);
});

it('refuses an agent write when agents are off, without falling back to the legacy writer', function () {
    config([
        'editor.operations.enabled' => true,
        'editor.agent_tools.enabled' => false,
    ]);

    $user = flagMatrixActor();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $content = ['sections' => [['type' => 'hero', 'title' => 'Old', 'subtitle' => 'sub']]];
    $page = GeneratedPage::create([
        'site_id' => $site->id, 'page_type' => 'home', 'content_data' => $content,
        'sort_order' => 1, 'version' => 1, 'status' => \App\Enums\PageStatus::Published,
    ]);
    $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
    $page->update(['published_revision_id' => $revision->id]);

    $this->actingAs($user)
        ->withHeaders([
            'X-Editor-Channel' => 'webmcp',
            'X-Page-Revision-Base' => (string) $revision->id,
        ])
        ->postJson("/sites/{$site->id}/pages/{$page->id}/fields", [
            'section_index' => 0,
            'field_path' => 'title',
            'value' => 'Agent title',
        ])
        ->assertForbidden();

    // refused, not quietly written by the legacy path
    expect($page->fresh()->draft_revision_id)->toBeNull()
        ->and(EditorOperationLog::query()->where('result_code', 'forbidden')->exists())->toBeTrue();
});

/*
 * A declared agent channel must never reach the database without its own gate. Declaring a channel
 * must never make the gate WEAKER than omitting it.
 */
it('refuses a declared-agent multipart upload when agents are off', function () {
    config([
        'editor.operations.enabled' => true,
        'editor.agent_tools.enabled' => false,
    ]);
    \Illuminate\Support\Facades\Storage::fake('public');

    $user = flagMatrixActor();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

    $this->actingAs($user)
        ->withHeaders(['X-Editor-Channel' => 'webmcp'])
        ->postJson(route('site.admin.media-upload', ['site' => $site->id]), [
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('photo.png', $png),
        ])
        ->assertForbidden();

    expect(\App\Models\SiteMedia::query()->where('site_id', $site->id)->exists())->toBeFalse();
});

it('refuses a declared-agent multipart upload when the actor role is outside the allowlist', function () {
    config([
        'editor.operations.enabled' => true,
        'editor.agent_tools.enabled' => true,
        'editor.agent_tools.roles' => ['client'],
    ]);
    \Illuminate\Support\Facades\Storage::fake('public');

    $user = flagMatrixActor(); // staff, deliberately not allowlisted
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

    $this->actingAs($user)
        ->withHeaders(['X-Editor-Channel' => 'webmcp'])
        ->postJson(route('site.admin.media-upload', ['site' => $site->id]), [
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('photo.png', $png),
        ])
        ->assertForbidden();
});

it('still allows a human multipart upload with agents off', function () {
    config([
        'editor.operations.enabled' => true,
        'editor.agent_tools.enabled' => false,
    ]);
    \Illuminate\Support\Facades\Storage::fake('public');

    $user = flagMatrixActor();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

    $this->actingAs($user)
        ->postJson(route('site.admin.media-upload', ['site' => $site->id]), [
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('photo.png', $png),
        ])
        ->assertOk();
});

/*
 * publish_summary is the one agent-reachable read that never entered the layer: no gate, no audit row,
 * in every flag combination.
 */
it('gates and logs an agent publish_summary read', function () {
    config([
        'editor.operations.enabled' => true,
        'editor.agent_tools.enabled' => true,
        'editor.agent_tools.roles' => ['staff'],
    ]);

    $user = flagMatrixActor();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);

    $this->actingAs($user)
        ->withHeaders(['X-Editor-Channel' => 'webmcp'])
        ->getJson(route('site.admin.publish-summary', ['site' => $site->id]))
        ->assertOk();

    $this->assertDatabaseHas('editor_operation_log', [
        'site_id' => $site->id,
        'operation' => 'publish_summary',
        'actor_channel' => 'webmcp',
    ]);
});

it('refuses an agent publish_summary read when agents are off', function () {
    config([
        'editor.operations.enabled' => true,
        'editor.agent_tools.enabled' => false,
    ]);

    $user = flagMatrixActor();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);

    $this->actingAs($user)
        ->withHeaders(['X-Editor-Channel' => 'webmcp'])
        ->getJson(route('site.admin.publish-summary', ['site' => $site->id]))
        ->assertForbidden();
});
