<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Browser\ServerManager;
use Tests\TestCase;

/*
 * Parent-side bridge coverage on the agents surface: the postMessage
 * origin gate and the field-changed → admin field-update forward.
 *
 * Uses the same multi-domain serving harness as FormPanelTest: absolute
 * URLs that keep the domain-pinned host but target the pest HTTP server's
 * real port (test domains resolve to 127.0.0.1 via compose extra_hosts).
 *
 * Spoofed messages are synthetic MessageEvents — the MessageEvent
 * constructor accepts an arbitrary `origin`, which is exactly what makes
 * it usable to exercise the bridge's event.origin check from inside the
 * page. A real cross-origin attacker cannot forge event.origin; the test
 * fakes the field the bridge distrusts and asserts it is honoured.
 */

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{0: User, 1: Site, 2: GeneratedPage}
 */
function seedAgentEditorPage(): array
{
    $user = User::factory()->staff(AgentRole::Agent)->create([
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $content = ['sections' => [
        ['type' => 'hero', 'title' => 'Original headline'],
    ]];
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'content_data' => $content,
        'sort_order' => 1,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $revision = PageRevision::create([
        'page_id' => $page->id,
        'content_data' => $content,
        'ai_generated' => false,
        'created_at' => now(),
    ]);
    $page->update(['published_revision_id' => $revision->id]);

    return [$user, $site, $page];
}

function agentEditorUrl(Site $site, GeneratedPage $page): string
{
    $host = config('domains.agent_domain');
    $serverBase = ServerManager::instance()->http()->rewrite('/');
    $port = parse_url($serverBase, PHP_URL_PORT);

    return 'http://'.$host.($port ? ':'.$port : '').route('site.editor-shell', [
        'site' => $site->id,
        'page' => $page->id,
    ], false);
}

test('the parent bridge drops postMessage from a non-iframe origin', function () {
    [$user, $site, $page] = seedAgentEditorPage();

    $this->actingAs($user);
    $shell = visit(agentEditorUrl($site, $page));

    $result = $shell->script(<<<JS
        (() => {
            const before = window.__siteworks_test_probe__.fieldChanges;
            window.dispatchEvent(new MessageEvent('message', {
                data: {
                    protocol: 'siteworks-editor-1',
                    id: 'spoof',
                    type: 'field-changed',
                    payload: { fieldKey: 'page.{$page->id}.section.0.title', value: 'pwned' },
                },
                origin: 'https://evil.example',
            }));
            return { before, after: window.__siteworks_test_probe__.fieldChanges };
        })()
    JS);

    expect($result['after'])->toBe($result['before']);
});

test('the parent bridge forwards genuine field-changed messages to the field-update endpoint', function () {
    [$user, $site, $page] = seedAgentEditorPage();

    $this->actingAs($user);
    $shell = visit(agentEditorUrl($site, $page));

    // Same synthetic-event trick as above, but with the origin the bridge
    // trusts — proves the whole parent-side pipeline: origin gate passes,
    // debounced save fires, POST hits site.admin.field-update, a draft
    // revision lands in the DB.
    //
    // The wait for the (800ms-debounced) POST must happen INSIDE script():
    // the pest HTTP server is in-process, so PHP-side sleeping/polling
    // starves it and the browser's POST is never served. Patch fetch,
    // dispatch, and resolve only once the field-update response lands.
    $status = $shell->script(<<<JS
        new Promise((resolve) => {
            const origFetch = window.fetch;
            window.fetch = async (...args) => {
                const res = await origFetch(...args);
                if (String(args[0]).endsWith('/fields')) {
                    resolve(res.status);
                }
                return res;
            };
            const origin = window.__siteworks_editor_shell_config__.iframeOrigin;
            window.dispatchEvent(new MessageEvent('message', {
                data: {
                    protocol: 'siteworks-editor-1',
                    id: 'genuine',
                    type: 'field-changed',
                    payload: {
                        fieldKey: 'page.{$page->id}.section.0.title',
                        value: 'Bridged headline',
                        revisionId: {$page->published_revision_id},
                    },
                },
                origin,
            }));
            setTimeout(() => resolve('timeout'), 10000);
        })
    JS);

    expect($status)->toBe(200);
    $draftId = $page->fresh()->draft_revision_id;
    expect($draftId)->not->toBeNull();
    $draft = PageRevision::find($draftId);
    expect($draft->content_data['sections'][0]['title'])->toBe('Bridged headline');
});
