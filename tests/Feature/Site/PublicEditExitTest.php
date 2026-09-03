<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\User;
use App\Services\Site\EditSessionCookie;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedSiteWithEditSession(User $user): array
{
    $site = Site::factory()->create(['preview_domain' => 'xy-edit-exit-test']);
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'content_data' => [],
        'sort_order' => 0,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $rev = PageRevision::create(['page_id' => $page->id, 'content_data' => [], 'ai_generated' => false, 'created_at' => now()]);
    $page->update(['published_revision_id' => $rev->id]);
    Preview::factory()->create(['site_id' => $site->id]);

    $cookie = app(EditSessionCookie::class)->make(
        [
            'user_id' => $user->id,
            'site_id' => $site->id,
            'page_id' => $page->id,
            'expires_at' => time() + 3600,
            'csrf' => 'test-csrf',
        ],
        request()->getHost(),
    );

    return ['site' => $site, 'page' => $page, 'cookie' => $cookie];
}

test('PublicEditExitController returns ok + a forget-cookie directive for the edit_session cookie', function () {
    // Invoke the controller directly — middleware integration is covered
    // by the rest of the _edit route tests; this test isolates the
    // cookie-forget behaviour.
    $controller = app(\App\Http\Controllers\Site\PublicEditExitController::class);
    $request = \Illuminate\Http\Request::create('/_edit/exit', 'POST');
    $response = $controller($request);

    expect($response->getStatusCode())->toBe(200);
    expect(json_decode($response->getContent(), true))->toBe(['ok' => true]);

    $cookies = $response->headers->getCookies();
    $forget = collect($cookies)->first(fn ($c) => $c->getName() === EditSessionCookie::NAME);
    expect($forget)->not->toBeNull();
    // Laravel's Cookie::forget emits a null/empty value with an expiry
    // in the past so the browser evicts the cookie immediately.
    expect($forget->getValue() ?: '')->toBe('');
    expect($forget->getExpiresTime())->toBeLessThan(time());
});

test('POST /_edit/exit route is registered under _edit prefix + EditSessionAuth middleware', function () {
    $routes = collect(\Illuminate\Support\Facades\Route::getRoutes())
        ->first(fn ($r) => $r->uri() === '_edit/exit' && in_array('POST', $r->methods()));

    expect($routes)->not->toBeNull();
    // Confirmed middleware includes EditSessionAuth
    expect($routes->middleware())->toContain(\App\Http\Middleware\EditSessionAuth::class);
});

test('POST /_edit/exit rejects unauthenticated callers via middleware (no cookie → not 2xx)', function () {
    $response = $this->postJson('/_edit/exit');
    expect($response->status())->toBeGreaterThanOrEqual(400);
});
