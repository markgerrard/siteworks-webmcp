<?php

use App\Enums\AgentRole;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\User;
use App\Services\Site\PageRenderer;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    if (! file_exists(public_path('build-editor-preview/manifest.json'))) {
        $this->markTestSkipped('build-editor-preview manifest not built');
    }

    config()->set('domains.editor_preview_domain', 'editor-preview.test');
    config()->set('domains.agent_domain', 'agents.test');
    config()->set('domains.customer_domain', 'app.test');

    $this->user = User::factory()->staff(AgentRole::Agent)->create();
    $this->site = Site::factory()->create(['created_by_user_id' => $this->user->id]);
    $this->page = GeneratedPage::factory()->for($this->site)->create();
});

/**
 * Extract the parentOrigin value from the iframe config that
 * EditorPreviewController injects via Js::from() — which wraps the JSON in
 * JSON.parse('…') and unicode-escapes quotes. Decode it and pull the field
 * out so the assertions don't depend on Js::from's escaping format.
 */
function configParentOrigin(string $body): ?string
{
    if (! preg_match("/__siteworks_editor_iframe_config__ = JSON\\.parse\\('([^']+)'\\)/", $body, $m)) {
        return null;
    }
    // Js::from output is JSON.parse('…') with unicode-escaped quotes ({"…})
    // and JS-string-escaped slashes (\/). Two-pass decode: first treat the
    // captured payload as a JSON string to resolve " → ", then json_decode
    // the resulting object literal.
    $inner = json_decode('"'.str_replace('"', '\\"', $m[1]).'"', true);
    if (! is_string($inner)) {
        return null;
    }
    $config = json_decode($inner, true);

    return is_array($config) ? ($config['parentOrigin'] ?? null) : null;
}

it('echoes a customer parent_origin from the signed URL into the iframe config', function () {
    $signed = URL::temporarySignedRoute(
        'editor-preview.show',
        now()->addHour(),
        [
            'site' => $this->site->id,
            'page' => $this->page->id,
            'parent_origin' => 'https://app.test',
        ]
    );

    $response = $this->get($signed);

    $response->assertOk();
    expect(configParentOrigin($response->getContent()))->toBe('https://app.test');
});

it('echoes an agent parent_origin from the signed URL into the iframe config', function () {
    $signed = URL::temporarySignedRoute(
        'editor-preview.show',
        now()->addHour(),
        [
            'site' => $this->site->id,
            'page' => $this->page->id,
            'parent_origin' => 'https://agents.test',
        ]
    );

    $response = $this->get($signed);

    $response->assertOk();
    expect(configParentOrigin($response->getContent()))->toBe('https://agents.test');
});

it('falls back to agent_domain when parent_origin is missing', function () {
    $signed = URL::temporarySignedRoute(
        'editor-preview.show',
        now()->addHour(),
        ['site' => $this->site->id, 'page' => $this->page->id]
    );

    $response = $this->get($signed);

    $response->assertOk();
    expect(configParentOrigin($response->getContent()))->toBe('https://agents.test');
});

it('threads parent_origin into signed nav URLs so cross-page nav keeps the same surface', function () {
    // Without this, in-iframe navigation from one page to another mints
    // signed URLs that the destination controller falls back to
    // agent_domain on — and the iframe bridge then drops every postMessage
    // when the parent is on the customer surface. This test asserts that
    // every editor-preview URL emitted by signed-nav rendering carries the
    // same parent_origin as the request that produced the page. We unit-
    // test the renderer directly because building a full multi-page
    // composition for the integration path is heavy.
    $renderer = app(PageRenderer::class);

    $reflection = new ReflectionClass($renderer);
    $method = $reflection->getMethod('buildPageHref');
    $method->setAccessible(true);

    $href = $method->invoke(
        $renderer,
        pageId: 99,
        pageType: 'about',
        isHomepage: false,
        mode: 'admin-edit',
        publicPrefix: '',
        siteId: $this->site->id,
        signedNav: true,
        parentOrigin: 'https://app.test',
    );

    expect($href)->toContain('editor-preview');
    expect($href)->toContain('/pages/99');
    expect($href)->toContain('parent_origin=https%3A%2F%2Fapp.test');
    expect($href)->toContain('signature=');

    // And without a parent_origin the URL is unchanged in shape but skips
    // the param — preserving back-compat with any caller that hasn't been
    // updated yet.
    $hrefNoOrigin = $method->invoke(
        $renderer,
        pageId: 99,
        pageType: 'about',
        isHomepage: false,
        mode: 'admin-edit',
        publicPrefix: '',
        siteId: $this->site->id,
        signedNav: true,
        parentOrigin: null,
    );
    expect($hrefNoOrigin)->not->toContain('parent_origin=');
    expect($hrefNoOrigin)->toContain('signature=');
});

it('rejects an unrecognised parent_origin even with a valid signature', function () {
    // A malicious agent crafting their own signed URL via a leaked APP_KEY
    // still cannot widen the iframe bridge to a third-party origin — the
    // controller allowlists against the configured surface domains.
    $signed = URL::temporarySignedRoute(
        'editor-preview.show',
        now()->addHour(),
        [
            'site' => $this->site->id,
            'page' => $this->page->id,
            'parent_origin' => 'https://evil.example',
        ]
    );

    $response = $this->get($signed);

    $response->assertOk();
    expect(configParentOrigin($response->getContent()))->toBe('https://agents.test');
});
