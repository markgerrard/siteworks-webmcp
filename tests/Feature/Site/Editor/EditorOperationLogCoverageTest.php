<?php

/*
 * The four logging gaps to close, plus the fifth needed to make the log coherent.
 *
 * Five editor actions never reached `editor_operation_log`, because they deliberately do not route through
 * the operations layer: human form saves, multipart uploads, portrait uploads, publish/discard, and
 * `edit_field`'s legacy branch. None was a security gap — all sit behind SitePolicy — but the log could not
 * be read as "everything that happened to this site". Recording is NOT flag-gated: an audit trail that
 * switches off with a feature flag is not an audit trail, so every case below runs with BOTH flags off.
 */

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\EditorOperationLog;
use App\Models\Site\PageRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    // both fronts OFF on purpose — the audit trail must not depend on either flag
    config([
        'editor.operations.enabled' => false,
        'editor.agent_tools.enabled' => false,
    ]);
    Storage::fake('public');
    $this->withoutMiddleware(\App\Http\Middleware\EnsureClientUser::class);
    $this->withoutVite();
});

function logCoverageSite(array $sections): array
{
    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $content = ['sections' => $sections];
    $page = GeneratedPage::create([
        'site_id' => $site->id, 'page_type' => 'home', 'content_data' => $content,
        'sort_order' => 1, 'version' => 1, 'status' => PageStatus::Published,
    ]);
    $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
    $page->update(['published_revision_id' => $revision->id]);
    test()->actingAs($user);

    return [$user, $site, $page, $revision];
}

function loggedOnce(string $operation, int $siteId, string $channel = 'ui'): bool
{
    return EditorOperationLog::query()
        ->where('site_id', $siteId)
        ->where('operation', $operation)
        ->where('actor_channel', $channel)
        ->count() === 1;
}

test('a human text edit on the legacy path is logged', function () {
    [$user, $site, $page, $revision] = logCoverageSite([['type' => 'hero', 'title' => 'Old', 'subtitle' => 'sub']]);

    $this->withHeaders(['X-Page-Revision-Base' => (string) $revision->id])
        ->postJson(route('site.admin.field-update', ['site' => $site->id, 'page' => $page->id]), [
            'section_index' => 0, 'field_path' => 'title', 'value' => 'Human title',
        ])->assertOk();

    expect(loggedOnce('edit_field', $site->id))->toBeTrue();
});

test('a human form save is logged', function () {
    [$user, $site, $page, $revision] = logCoverageSite([
        ['type' => 'contact_form', 'title' => 'Contact us', 'submit_label' => 'Send', 'fields' => []],
    ]);

    $this->withHeaders(['X-Page-Revision-Base' => (string) $revision->id])
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/0", [
            'fields' => [['label' => 'Job postcode', 'type' => 'text', 'required' => true]],
        ])->assertOk();

    expect(loggedOnce('update_form', $site->id))->toBeTrue();
});

test('a multipart file upload is logged', function () {
    [$user, $site] = logCoverageSite([['type' => 'hero', 'title' => 'A']]);

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

    $this->postJson(route('site.admin.media-upload', ['site' => $site->id]), [
        'file' => UploadedFile::fake()->createWithContent('photo.png', $png),
    ])->assertOk();

    expect(loggedOnce('upload_image', $site->id))->toBeTrue();
});

test('a publish is logged', function () {
    [$user, $site] = logCoverageSite([['type' => 'hero', 'title' => 'A']]);

    $this->postJson(route('site.admin.publish', ['site' => $site->id]), [])->assertOk();

    expect(loggedOnce('publish', $site->id))->toBeTrue();
});

test('a discard-all is logged', function () {
    [$user, $site] = logCoverageSite([['type' => 'hero', 'title' => 'A']]);

    $this->postJson(route('site.admin.discard-all', ['site' => $site->id]), [])->assertOk();

    expect(loggedOnce('discard_all', $site->id))->toBeTrue();
});

test('a stale form save is logged with its failure code, not silently dropped', function () {
    [$user, $site, $page] = logCoverageSite([
        ['type' => 'contact_form', 'title' => 'Contact us', 'submit_label' => 'Send', 'fields' => []],
    ]);

    $this->withHeaders(['X-Page-Revision-Base' => '999999'])
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/0", [
            'fields' => [['label' => 'Job postcode', 'type' => 'text', 'required' => true]],
        ])->assertStatus(409);

    expect(EditorOperationLog::query()
        ->where('site_id', $site->id)
        ->where('operation', 'update_form')
        ->where('result_code', 'stale_revision')
        ->exists())->toBeTrue();
});
