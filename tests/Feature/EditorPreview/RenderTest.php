<?php

use App\Enums\AgentRole;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\User;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    if (! file_exists(public_path('build-editor-preview/manifest.json'))) {
        $this->markTestSkipped('build-editor-preview manifest not built; skipped until Task 7');
    }

    $this->user = User::factory()->staff(AgentRole::Agent)->create();
    $this->site = Site::factory()->create(['created_by_user_id' => $this->user->id]);
    $this->page = GeneratedPage::factory()->for($this->site)->create();
});

it('renders preview HTML with iframe-side editor injection', function () {
    // The editor-preview route is gated by the `signed` middleware only —
    // the iframe is cross-origin from the agents shell and has no auth
    // cookies. EditorShellController mints the signed URL after authorising
    // the agents-side user; here we mint one directly to drive the test.
    $signed = URL::temporarySignedRoute(
        'editor-preview.show',
        now()->addHour(),
        ['site' => $this->site->id, 'page' => $this->page->id]
    );

    $response = $this->get($signed);

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    $body = $response->getContent();

    // Iframe-side editor markers
    expect($body)->toContain('data-editor-iframe-bridge');

    // Parent-only modules MUST NOT appear in the iframe HTML
    expect($body)->not->toContain('image-picker');
    expect($body)->not->toContain('toolbar');
});

it('renders form selection markers for the editor iframe panel', function () {
    $revision = PageRevision::factory()->for($this->page, 'page')->create([
        'content_data' => ['sections' => [[
            'type' => 'contact_form',
            'title' => 'Contact us',
        ]]],
    ]);
    $this->page->update(['published_revision_id' => $revision->id]);

    $signed = URL::temporarySignedRoute(
        'editor-preview.show',
        now()->addHour(),
        ['site' => $this->site->id, 'page' => $this->page->id]
    );

    $this->get($signed)
        ->assertOk()
        ->assertSee('data-form-editable="page.'.$this->page->id.'.section.0"', false);
});

it('rejects unsigned requests with 403', function () {
    // Authorisation here is signature-based, not session-based: a request
    // without a valid signature must be rejected regardless of who made it.
    $this->get(route('editor-preview.show', [
        'site' => $this->site->id,
        'page' => $this->page->id,
    ]))->assertForbidden();
});

it('rejects requests with a tampered signature', function () {
    $signed = URL::temporarySignedRoute(
        'editor-preview.show',
        now()->addHour(),
        ['site' => $this->site->id, 'page' => $this->page->id]
    );

    // Flip the last character of the signature to invalidate it.
    $tampered = preg_replace_callback(
        '/(signature=)([0-9a-f]+)/',
        fn ($m) => $m[1].substr($m[2], 0, -1).($m[2][-1] === '0' ? '1' : '0'),
        $signed
    );

    $this->get($tampered)->assertForbidden();
});

it('rejects requests with an expired signature', function () {
    $expired = URL::temporarySignedRoute(
        'editor-preview.show',
        now()->subMinute(),
        ['site' => $this->site->id, 'page' => $this->page->id]
    );

    $this->get($expired)->assertForbidden();
});
