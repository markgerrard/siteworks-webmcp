<?php

use App\Enums\AgentRole;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\User;

beforeEach(function () {
    if (! file_exists(public_path('build-editor-preview/manifest.json'))) {
        $this->markTestSkipped('build-editor-preview manifest not built; skipped until Task 7');
    }
    $this->user = User::factory()->staff(AgentRole::Agent)->create();
    $this->site = Site::factory()->create(['created_by_user_id' => $this->user->id]);
    $this->page = GeneratedPage::factory()->for($this->site)->create();
});

it('emits a strict CSP header on editor-preview responses', function () {
    config()->set('domains.editor_preview_domain', 'editor-preview.test');
    config()->set('domains.agent_domain', 'agents.test');
    config()->set('domains.customer_domain', 'app.test');
    config()->set('filesystems.disks.s3.url', 'https://test-bucket.lon1.digitaloceanspaces.com');

    $response = $this->actingAs($this->user)
        ->get(route('editor-preview.show', [
            'site' => $this->site->id,
            'page' => $this->page->id,
        ]));

    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->not->toBeNull();
    expect($csp)->toContain("default-src 'none'");
    expect($csp)->toContain("script-src 'self'");
    expect($csp)->toContain("style-src 'self' 'unsafe-inline'"); // Tailwind utility classes are inline-safe; vendor JIT emits inline <style>
    expect($csp)->toContain("img-src 'self' data: https://test-bucket.lon1.digitaloceanspaces.com");
    expect($csp)->toContain("font-src 'self' data:");
    // Without media-src, default-src 'none' blocks every hero clip in the WYSIWYG
    // iframe: a Spaces video URL inside the preview document would raise a
    // media-src violation.
    expect($csp)->toContain("media-src 'self' https://test-bucket.lon1.digitaloceanspaces.com");
    // Spaces bucket names are self-service, so a wildcard is not a restriction —
    // and this document renders generated content, which is where it matters most.
    expect($csp)->not->toContain('*.digitaloceanspaces.com');
    expect($csp)->toContain("connect-src 'self'");
    expect($csp)->toContain('frame-ancestors https://agents.test https://app.test');
    expect($csp)->toContain('report-uri https://agents.test/csp-report');
});

it('allows iframing from both agent and customer origins, blocks others', function () {
    config()->set('domains.agent_domain', 'agents.test');
    config()->set('domains.customer_domain', 'app.test');

    $response = $this->actingAs($this->user)
        ->get(route('editor-preview.show', [
            'site' => $this->site->id,
            'page' => $this->page->id,
        ]));

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain('frame-ancestors https://agents.test https://app.test');
});
