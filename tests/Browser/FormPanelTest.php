<?php

use App\Enums\PageStatus;
use App\Models\Client;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Browser\ServerManager;
use Tests\TestCase;

/*
 * Customer-surface browser coverage for the form panel.
 *
 * Livewire::test() disables lazy loading, so a passing feature test does
 * not prove what a client actually sees. This file must run against the
 * customer host. It drives the preview iframe: click the form, add a
 * field, save, then assert the reloaded iframe contains the new input.
 *
 * Multi-domain serving harness: pest-plugin-browser serves single-origin
 * (127.0.0.1:<port>), so customerEditorUrl() builds an absolute URL that
 * keeps the domain-pinned host but targets the server's real port. The
 * container maps the phpunit.xml test domains to 127.0.0.1 (compose
 * extra_hosts), and the app side accepts the resulting http://host:port
 * origins in its origin allowlists / CSPs while running tests only —
 * see EditorParentOrigin, AgentsSecurityHeaders, EditorPreviewCsp and
 * the Testing\UseRequestAssetOrigin middleware.
 */

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{0: User, 1: Site, 2: GeneratedPage}
 */
function seedCustomerContactForm(): array
{
    $client = Client::factory()->create();
    $user = User::factory()->create([
        'client_id' => $client->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $client->id]);
    $content = ['sections' => [
        ['type' => 'hero', 'title' => 'Hi'],
        ['type' => 'contact_form', 'title' => 'Contact us', 'submit_label' => 'Send', 'fields' => []],
    ]];
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'contact',
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

function customerEditorUrl(Site $site, GeneratedPage $page): string
{
    // Absolute URLs bypass pest-plugin-browser's single-origin rewrite, so
    // build one that still carries the customer host (the route is domain-
    // pinned) but points at the plugin's real HTTP server: plain http on
    // its ephemeral port. Requires the test domains to resolve to
    // 127.0.0.1 inside the container (compose extra_hosts).
    $host = config('domains.customer_domain');
    $serverBase = ServerManager::instance()->http()->rewrite('/');
    $port = parse_url($serverBase, PHP_URL_PORT);

    return 'http://'.$host.($port ? ':'.$port : '').route('site.editor-shell', [
        'site' => $site->id,
        'page' => $page->id,
    ], false);
}

test('a client can add a field from the form panel on the customer surface', function () {
    [$user, $site, $page] = seedCustomerContactForm();

    $this->actingAs($user);
    $shell = visit(customerEditorUrl($site, $page));

    $shell->withinFrame('#editor-preview-iframe', function ($iframe) {
        $iframe->click('[data-form-editable]');
    });

    $shell->assertSee('Edit form');
    $shell->click('#form-panel-add-field');
    // The panel renders one label input per existing field — target the row
    // the add-field click just appended (always last).
    $shell->fill('[data-field-label] >> nth=-1', 'Job postcode');
    $shell->click('#form-panel-save');

    // script() is a Playwright evaluate: a Promise-valued expression is
    // awaited. The iframe is cross-origin, so read its HTML from inside the
    // frame after waiting for the save-triggered reload.
    $html = $shell->script(<<<'JS'
        new Promise((resolve) => {
            const iframe = document.getElementById('editor-preview-iframe');
            const done = () => setTimeout(() => resolve('reloaded'), 500);
            iframe.addEventListener('load', done, { once: true });
            setTimeout(() => resolve('timeout'), 8000);
        })
    JS);
    $iframeHtml = '';
    $shell->withinFrame('#editor-preview-iframe', function ($iframe) use (&$iframeHtml) {
        $iframeHtml = $iframe->script('document.documentElement.outerHTML');
    });
    $html = $iframeHtml;

    expect($html)->toContain('name="job_postcode"');
});

test('clicking the form body opens the review', function () {
    [$user, $site, $page] = seedCustomerContactForm();

    $this->actingAs($user);
    $shell = visit(customerEditorUrl($site, $page));

    $shell->withinFrame('#editor-preview-iframe', function ($iframe) {
        $iframe->click('input[name="name"]');
    });

    $shell->assertSee('Edit form');
});

test('clicking an inline-editable control does not open the review', function () {
    [$user, $site, $page] = seedCustomerContactForm();

    $this->actingAs($user);
    $shell = visit(customerEditorUrl($site, $page));

    $shell->withinFrame('#editor-preview-iframe', function ($iframe) {
        $iframe->click('[data-editable-field="submit_label"]');
    });

    $shell->assertDontSee('Edit form');
});
