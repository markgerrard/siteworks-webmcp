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
 * Iframe-side bridge coverage: the preview announces itself to the parent
 * with a protocol-versioned "ready" envelope.
 *
 * The signed preview URL is minted by EditorShellController, so the test
 * goes through the shell (same multi-domain pattern as FormPanelTest)
 * rather than visiting editor-preview.show directly — visiting directly
 * would mean re-implementing the signing against the test server's
 * ephemeral port. The original ready fires before a listener can be
 * installed, so the test installs one and reloads the iframe to replay
 * the handshake.
 */

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{0: User, 1: Site, 2: GeneratedPage}
 */
function seedPreviewBridgePage(): array
{
    $user = User::factory()->staff(AgentRole::Agent)->create([
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $content = ['sections' => [
        ['type' => 'hero', 'title' => 'Bridge handshake'],
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

function previewBridgeShellUrl(Site $site, GeneratedPage $page): string
{
    $host = config('domains.agent_domain');
    $serverBase = ServerManager::instance()->http()->rewrite('/');
    $port = parse_url($serverBase, PHP_URL_PORT);

    return 'http://'.$host.($port ? ':'.$port : '').route('site.editor-shell', [
        'site' => $site->id,
        'page' => $page->id,
    ], false);
}

it('iframe emits a "ready" postMessage on load with the correct envelope', function () {
    [$user, $site, $page] = seedPreviewBridgePage();

    $this->actingAs($user);
    $shell = visit(previewBridgeShellUrl($site, $page));

    $message = $shell->script(<<<'JS'
        new Promise((resolve) => {
            window.addEventListener('message', (e) => {
                if (e.data?.protocol === 'siteworks-editor-1' && e.data?.type === 'ready') {
                    resolve({ data: e.data, origin: e.origin });
                }
            });
            const iframe = document.getElementById('editor-preview-iframe');
            iframe.src = iframe.src; // replay the load → ready handshake
            setTimeout(() => resolve('timeout'), 10000);
        })
    JS);

    expect($message)->not->toBe('timeout');
    expect($message['origin'])->toBe($shell->script(
        'window.__siteworks_editor_shell_config__.iframeOrigin'
    ));
    expect($message['data']['protocol'])->toBe('siteworks-editor-1');
    expect($message['data']['type'])->toBe('ready');
    expect($message['data']['id'])->toBeString()->not->toBe('');
    expect($message['data']['payload']['siteId'])->toBe($site->id);
    expect($message['data']['payload']['pageId'])->toBe($page->id);
});
