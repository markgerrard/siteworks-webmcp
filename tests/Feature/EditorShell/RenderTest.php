<?php

use App\Enums\AgentRole;
use App\Models\Client;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();

    $this->user = User::factory()->staff(AgentRole::Agent)->create();
    $this->site = Site::factory()->for($this->user)->create();
    $this->page = GeneratedPage::factory()->for($this->site)->create();
});

it('renders the editor shell with a sandboxed iframe pointing at editor-preview origin', function () {
    // Domain config is force-set by phpunit.xml (APP_EDITOR_PREVIEW_DOMAIN)
    // and bound onto the editor-preview route at load time. Read it back
    // here rather than overriding — config()->set after route boot wouldn't
    // change the bound domain anyway.
    $editorPreviewDomain = config('domains.editor_preview_domain');

    $response = $this->actingAs($this->user)
        ->get(route('site.editor-shell', [
            'site' => $this->site->id,
            'page' => $this->page->id,
        ]));

    $response->assertOk();
    $body = $response->getContent();

    expect($body)->toContain('<iframe');
    // sandbox="allow-scripts allow-same-origin" — same-origin is required so
    // the iframe can fetch its own ESM bundle from the editor-preview host;
    // the cross-origin separation from the agents shell still isolates
    // session cookies. See the comment in editor-shell.blade.php.
    expect($body)->toContain('sandbox="allow-scripts allow-same-origin"');
    expect($body)->not->toContain('allow-forms');
    expect($body)->not->toContain('allow-popups');
    expect($body)->not->toContain('allow-top-navigation');
    expect($body)->not->toContain('allow-modals');
    expect($body)->toContain('//'.$editorPreviewDomain.'/sites/'.$this->site->id);
});

it('rejects users with no claim on the site (403)', function () {
    // Behaviour: editor-shell no longer requires the
    // agent.only middleware — clients can now drive the editor on the
    // customer surface for their own sites. Users with no claim on the
    // site (no matching client_id, not staff) are rejected at the
    // SitePolicy@view layer with a 403 instead of an SSO redirect.
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('site.editor-shell', [
            'site' => $this->site->id,
            'page' => $this->page->id,
        ]))
        ->assertForbidden();
});

it('admits a client whose client_id matches the site', function () {
    $client = Client::factory()->create();
    $clientUser = User::factory()->create([
        'client_id' => $client->id,
        'role' => null,
    ]);
    $clientSite = Site::factory()->create(['client_id' => $client->id]);
    $clientPage = GeneratedPage::factory()->for($clientSite)->create();

    $this->actingAs($clientUser)
        ->get(route('site.editor-shell', [
            'site' => $clientSite->id,
            'page' => $clientPage->id,
        ]))
        ->assertOk();
});

it('hands the review route templates with a trailing section placeholder', function () {
    $response = $this->actingAs($this->user)
        ->get(route('site.editor-shell', [
            'site' => $this->site->id,
            'page' => $this->page->id,
        ]));

    $response->assertOk();
    $body = $response->getContent();

    // Js::from() emits JSON.parse('{\u0022key\u0022:...}') so a raw route()
    // string will not appear. The keys and the /0 placeholder must.
    expect($body)->toContain('formDefinitionUrl')
        ->and($body)->toContain('formUpdateUrl')
        ->and($body)->toContain('\\\\\/sites\\\\\/'.$this->site->id.'\\\\\/pages\\\\\/0\\\\\/form\\\\\/0')
        ->and($body)->not->toContain('\\\\\/sites\\\\\/'.$this->site->id.'\\\\\/pages\\\\\/'.$this->page->id.'\\\\\/form\\\\\/0');
});

it('hands the inline field-update route as a page-id template', function () {
    $other = GeneratedPage::factory()->for($this->site)->create();

    $response = $this->actingAs($this->user)
        ->get(route('site.editor-shell', [
            'site' => $this->site->id,
            'page' => $this->page->id,
        ]));

    $response->assertOk();
    $body = $response->getContent();

    // Same placeholder shape as formDefinitionUrl / formUpdateUrl: the
    // client substitutes the page id from the inline marker, because the
    // iframe may have navigated away from the page the shell opened on.
    expect($body)->toContain('fieldUpdateUrl')
        ->and($body)->toContain('\\\\\/sites\\\\\/'.$this->site->id.'\\\\\/pages\\\\\/0\\\\\/fields')
        ->and($body)->not->toContain('\\\\\/sites\\\\\/'.$this->site->id.'\\\\\/pages\\\\\/'.$this->page->id.'\\\\\/fields')
        ->and($body)->not->toContain('\\\\\/sites\\\\\/'.$this->site->id.'\\\\\/pages\\\\\/'.$other->id.'\\\\\/fields');
});
