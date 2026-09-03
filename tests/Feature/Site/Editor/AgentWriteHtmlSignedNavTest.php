<?php

/*
 * Front 3 half. Every editor write rendered its result html with signedNav: true,
 * so the envelope carried one 8-hour `editor-preview` signed URL per nav item. Those URLs are the ONLY
 * proof of authorization the preview route asks for — standing credentials good for their lifetime.
 * Handed to an external model (MCP `include_html: true`, or a WebMCP tool result) they leave the tenant
 * boundary into a third party's logs. Signed nav is a UI-channel affordance: only the human's own iframe
 * navigates with them.
 */

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
    $this->withoutVite();
});

function seedSignedNavSite(): array
{
    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $content = ['sections' => [
        ['type' => 'hero', 'title' => 'A'],
        ['type' => 'cta', 'title' => 'B'],
    ]];

    $pages = [];
    foreach (['home', 'about'] as $index => $type) {
        $page = GeneratedPage::create([
            'site_id' => $site->id,
            'page_type' => $type,
            'content_data' => $content,
            'sort_order' => $index,
            'version' => 1,
            'status' => PageStatus::Published,
        ]);
        $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
        $page->update(['published_revision_id' => $revision->id]);
        $pages[$type] = $page->fresh();
    }

    return [$user, $site, $pages['home']];
}

function editFieldHtmlFor(ActorChannel $channel): string
{
    [$user, $site, $page] = seedSignedNavSite();

    $result = app(EditorOperations::class)->run(
        new EditorContext($user, $site, $channel),
        'edit_field',
        [
            'page_id' => $page->id,
            'stored_index' => 0,
            'field_path' => 'title',
            'value' => 'Written by '.$channel->value,
            'revision_base' => $page->published_revision_id,
        ],
    );

    expect($result->ok)->toBeTrue();

    return (string) $result->data['html'];
}

it('still mints signed preview nav for the UI channel', function () {
    $html = editFieldHtmlFor(ActorChannel::Ui);

    expect($html)->toContain('Written by ui')
        ->and($html)->toContain('signature=');
});

it('renders agent-channel write html with no signed preview URL', function (ActorChannel $channel) {
    $html = editFieldHtmlFor($channel);

    expect($html)->toContain('Written by '.$channel->value)
        ->and($html)->not->toContain('signature=')
        ->and($html)->not->toContain('editor-preview');
})->with([
    'webmcp' => ActorChannel::Webmcp,
    'mcp' => ActorChannel::Mcp,
]);
