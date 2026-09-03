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
 * Editor-preview isolation coverage: what a script running INSIDE the
 * preview iframe cannot do. The iframe is sandbox="allow-scripts
 * allow-same-origin" on a separate origin (editor-preview domain), so the
 * defenses under test are the sandbox attribute (no allow-top-navigation)
 * and cross-origin separation — NOT the CSP, which pest-plugin-browser
 * disables (see the skipped test below).
 *
 * Scripts are injected via withinFrame; that is equivalent to an XSS
 * payload that made it into draft content, minus the delivery step.
 */

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{0: User, 1: Site, 2: GeneratedPage}
 */
function seedPreviewXssPage(): array
{
    $user = User::factory()->staff(AgentRole::Agent)->create([
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $content = ['sections' => [
        ['type' => 'hero', 'title' => 'Isolation test'],
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

function agentShellUrl(Site $site, GeneratedPage $page): string
{
    $host = config('domains.agent_domain');
    $serverBase = ServerManager::instance()->http()->rewrite('/');
    $port = parse_url($serverBase, PHP_URL_PORT);

    return 'http://'.$host.($port ? ':'.$port : '').route('site.editor-shell', [
        'site' => $site->id,
        'page' => $page->id,
    ], false);
}

it('EditorPreviewXss: script inside the iframe cannot navigate the top frame', function () {
    [$user, $site, $page] = seedPreviewXssPage();

    $this->actingAs($user);
    $shell = visit(agentShellUrl($site, $page));

    $attempt = null;
    $shell->withinFrame('#editor-preview-iframe', function ($iframe) use (&$attempt) {
        // sandbox without allow-top-navigation: assigning top.location
        // from inside the frame must throw, not navigate.
        $attempt = $iframe->script(<<<'JS'
            (() => {
                try {
                    window.top.location.href = 'https://evil.example/pwned';
                    return 'navigated';
                } catch (e) {
                    return 'blocked:' + e.name;
                }
            })()
        JS);
    });

    expect($attempt)->toStartWith('blocked:');

    $topUrl = $shell->script('window.location.href');
    expect($topUrl)->toContain('/sites/'.$site->id.'/pages/'.$page->id.'/editor');
    expect($topUrl)->not->toContain('evil.example');
});

it('EditorPreviewXss: the iframe is cross-origin and cannot see agents cookies', function () {
    [$user, $site, $page] = seedPreviewXssPage();

    $this->actingAs($user);
    $shell = visit(agentShellUrl($site, $page));

    // Parent side: cross-origin means no contentDocument access.
    $parentReach = $shell->script(<<<'JS'
        (() => {
            const iframe = document.getElementById('editor-preview-iframe');
            try {
                return iframe.contentDocument === null ? '<unreachable>' : 'reachable';
            } catch (e) {
                return '<unreachable>';
            }
        })()
    JS);
    expect($parentReach)->toBe('<unreachable>');

    // Iframe side: the agents session cookie is host-scoped to the agents
    // domain, so the editor-preview origin must not carry it.
    $sessionCookie = config('session.cookie');
    $iframeCookies = null;
    $shell->withinFrame('#editor-preview-iframe', function ($iframe) use (&$iframeCookies) {
        $iframeCookies = $iframe->script('document.cookie');
    });
    expect((string) $iframeCookies)->not->toContain($sessionCookie);
});

it('EditorPreviewXss: XSS in draft cannot post to admin endpoints', function () {
    $this->markTestSkipped(
        'The defense is CSP connect-src \'self\' on the editor-preview response, but '
        .'pest-plugin-browser hardcodes bypassCSP:true in its browser context '
        .'(vendor BrowserFactory/Client), so CSP is not enforced in this harness and '
        .'the assertion would be vacuous. Covered indirectly: the iframe is cross-origin '
        .'(no admin cookies, see the cookie test) and admin writes require CSRF + session.'
    );
});

it('EditorPreviewXss: CSP report endpoint logs violations', function () {
    $logPath = storage_path('logs/csp-violations-'.now()->format('Y-m-d').'.log');
    if (file_exists($logPath)) {
        unlink($logPath);
    }

    $payload = [
        'csp-report' => [
            'document-uri' => 'https://editor-preview.test/sites/1/pages/1',
            'violated-directive' => 'script-src',
            'blocked-uri' => 'https://evil.example/x.js',
        ],
    ];

    $this->postJson(route('csp.report'), $payload, [
        'Content-Type' => 'application/csp-report',
    ])->assertNoContent();

    // Marker exists in the daily log
    expect(file_exists($logPath))->toBeTrue();
    expect(file_get_contents($logPath))->toContain('csp-violation');
});
