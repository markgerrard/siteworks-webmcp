<?php

use App\Enums\AgentRole;
use App\Models\Client;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\User;

function agentsSurfaceHost(): string
{
    return (string) config('domains.agent_domain');
}

function getOnAgentsSurface(string $path)
{
    $host = agentsSurfaceHost();

    return test()->withServerVariables(['HTTP_HOST' => $host])
        ->get('http://'.$host.$path);
}

/**
 * Every <script> the CSP would refuse, given a rendered response.
 *
 * The rule is precise because `script-src` here is `'self' 'nonce-…' 'unsafe-eval'`
 * with NO host allowlist:
 *   - inline (no src)          -> must carry the response nonce
 *   - src same-origin          -> authorised by 'self'; nonce optional. Cloudflare
 *                                 injects /cdn-cgi/scripts/… email-decode at the
 *                                 edge with no nonce, and that is legitimately fine.
 *   - src cross-origin         -> must carry the nonce; nothing else authorises it
 *   - non-executable type      -> not script at all, skip
 *
 * The previous version only checked inline scripts and decided "has a nonce" by
 * substring, so it was defeated three ways at once by the review:
 * `<script data-src="/x.js">evil()</script>` (the `\bsrc` boundary matched
 * `data-src`), `<script data-note='@cspNonce'>` (compiles to `data-note=' nonce="…"'`
 * and the substring matched inside another attribute's value), and a cross-origin
 * `src` with no nonce at all, which CSP refuses while the test skipped it.
 *
 * @return array<int, string> offending script tags
 */
function scriptsCspWouldRefuse(string $html, string $csp, string $responseHost): array
{
    preg_match("/script-src [^;]*'nonce-([^']+)'/", $csp, $nonceMatch);
    $nonce = $nonceMatch[1] ?? null;

    expect($nonce)->not->toBeNull('response carries no script-src nonce to compare against');

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors(false);

    $nonExecutable = ['application/json', 'application/ld+json', 'text/template', 'text/x-template'];
    $offenders = [];

    foreach ($dom->getElementsByTagName('script') as $script) {
        $type = strtolower(trim($script->getAttribute('type')));

        if ($type !== '' && in_array($type, $nonExecutable, true)) {
            continue;
        }

        // getAttribute() reads the real attribute, so a nonce-looking string inside
        // some other attribute's value cannot satisfy this.
        if ($script->getAttribute('nonce') === $nonce) {
            continue;
        }

        $src = trim($script->getAttribute('src'));

        if ($src !== '') {
            // WHATWG treats a backslash in the authority as a slash, so
            // `https:/\evil.example/x.js` loads from evil.example while parse_url()
            // reports no host at all — which read as "relative, therefore 'self'".
            // NB the class must be [\\/] IN THE REGEX, so the PHP literal needs four
            // backslashes: '[\\/]' would compile to an escaped slash and never match
            // a backslash at all — which is how the first version of this fix shipped
            // broken and was caught by its own red case.
            $normalised = preg_replace('#^([a-z][a-z0-9+.-]*:)?[\\\\/]{2,}#i', '$1//', $src) ?? $src;

            $parts = parse_url($normalised);
            $host = $parts['host'] ?? null;

            // 'self' is ORIGIN-exact — scheme, host AND port. Comparing hostnames
            // alone passed `https://same-host:1337/x.js`, which CSP refuses.
            $port = $parts['port'] ?? null;
            $sameOrigin = $host === null
                || (strcasecmp((string) $host, $responseHost) === 0 && $port === null);

            if ($sameOrigin) {
                continue;
            }
        }

        $offenders[] = $src !== '' ? "<script src=\"{$src}\">" : '<script> (inline, no nonce)';
    }

    return $offenders;
}

function assertNoRefusedScripts(string $html, string $csp, string $host, string $page): void
{
    expect(scriptsCspWouldRefuse($html, $csp, $host))->toBe([],
        "On {$page}, these scripts would be refused by the page's own CSP.");

    // An uncompiled directive glued to a tag name renders as an element whose name
    // contains '@' — the shape that killed the editor shell.
    expect($html)->not->toMatch('/<[a-zA-Z][a-zA-Z0-9.:_-]*@/');
}

test('every script on the agents login page is one the CSP would run', function () {
    $response = getOnAgentsSurface('/login');
    $response->assertOk();

    assertNoRefusedScripts(
        (string) $response->getContent(),
        (string) $response->headers->get('Content-Security-Policy'),
        agentsSurfaceHost(),
        '/login',
    );
});

test('every script on an authenticated staff page is one the CSP would run', function () {
    $user = User::factory()->staff(AgentRole::Agent)->create();
    $host = agentsSurfaceHost();

    $response = test()->actingAs($user)
        ->withServerVariables(['HTTP_HOST' => $host])
        ->get('http://'.$host.'/sites');

    $response->assertOk();

    assertNoRefusedScripts(
        (string) $response->getContent(),
        (string) $response->headers->get('Content-Security-Policy'),
        $host,
        '/sites',
    );
});

test('the editor shell — the page that actually broke — is checked too', function () {
    // Two rounds ago this page shipped dead for days: <script@cspNonce> rendered as
    // an unknown element, the shell config never ran, and the toolbar was empty.
    // It was still not in the tested set until the review pointed that out.
    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $host = agentsSurfaceHost();

    $response = test()->actingAs($user)
        ->withServerVariables(['HTTP_HOST' => $host])
        ->get('http://'.$host.'/sites/'.$site->id.'/pages/'.$page->id.'/editor');

    $response->assertOk();

    $html = (string) $response->getContent();

    assertNoRefusedScripts(
        $html,
        (string) $response->headers->get('Content-Security-Policy'),
        $host,
        'the editor shell',
    );

    // And the specific regression: the shell config must be a real script, not text.
    expect($html)->toContain('__siteworks_editor_shell_config__')
        ->and($html)->not->toContain('script@');
});

test('agent login response carries nonce CSP and baseline isolation headers', function () {
    $response = getOnAgentsSurface('/login');

    $response->assertOk();

    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->not->toBeNull()
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->toContain("default-src 'self'")
        ->and($csp)->toMatch("/script-src 'self' 'nonce-[^']+' 'unsafe-eval'/")
        ->and($csp)->toContain("report-uri https://".agentsSurfaceHost().'/csp-report');

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Permissions-Policy'))->toContain('camera=()')
        ->and($response->headers->get('Permissions-Policy'))->toContain('geolocation=()')
        ->and($response->headers->has('Strict-Transport-Security'))->toBeFalse();
});

test('agents CSP allows inline style attributes, Spaces media, and unpkg icons', function () {
    $csp = getOnAgentsSurface('/login')->headers->get('Content-Security-Policy');

    expect($csp)->toContain("style-src-attr 'unsafe-inline'")
        ->and($csp)->toMatch("/style-src 'self' 'nonce-[^']+'/")
        ->and($csp)->toContain('https://unpkg.com');
});

test('media origins are pinned to the configured bucket, never a Spaces wildcard', function () {
    // Set explicitly rather than read from the ambient env: AWS_URL is unset under
    // phpunit, so an assertion derived from config would silently compare against
    // an empty string and pass on any policy at all.
    config(['filesystems.disks.s3.url' => 'https://test-bucket.lon1.digitaloceanspaces.com']);
    \Illuminate\Support\Facades\Storage::forgetDisk('s3');

    $csp = getOnAgentsSurface('/login')->headers->get('Content-Security-Policy');

    // Spaces bucket names are self-service, so `*.digitaloceanspaces.com` lets any
    // attacker-registered bucket serve as a GET exfiltration target, while
    // connect-src 'self' refuses the equivalent fetch().
    expect($csp)->not->toContain('*.digitaloceanspaces.com')
        ->and($csp)->toContain("media-src 'self' https://test-bucket.lon1.digitaloceanspaces.com "
            .'https://test-bucket.lon1.cdn.digitaloceanspaces.com')
        ->and($csp)->toContain('img-src \'self\' data: blob: https://test-bucket.lon1.digitaloceanspaces.com');
});

test('media origins fall back to bucket plus endpoint when no explicit URL is set', function () {
    // A COMPLETE disk config: with no explicit url, the origin comes from the disk's
    // own URL generator, which needs a constructible adapter.
    config(['filesystems.disks.s3' => [
        'driver' => 's3',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'region' => 'lon1',
        'bucket' => 'fallback-bucket',
        'url' => null,
        'endpoint' => 'https://lon1.digitaloceanspaces.com',
        'use_path_style_endpoint' => false,
    ]]);
    \Illuminate\Support\Facades\Storage::forgetDisk('s3');

    $csp = getOnAgentsSurface('/login')->headers->get('Content-Security-Policy');

    expect($csp)->toContain('https://fallback-bucket.lon1.digitaloceanspaces.com')
        ->and($csp)->not->toContain('*.digitaloceanspaces.com');
});

test('autoplay is allowed for this origin so the Hero Video Studio can preview clips', function () {
    $policy = (string) getOnAgentsSurface('/login')->headers->get('Permissions-Policy');

    // The studio autoplays a muted current-clip preview and replays past versions
    // from x-on:mouseover, which is not user activation. A bare autoplay=() denies
    // both; (self) still denies embedded third-party frames.
    expect($policy)->toContain('autoplay=(self)')
        ->and($policy)->not->toContain('autoplay=()')
        // The rest of the allowlist stays denied.
        ->and($policy)->toContain('camera=()')
        ->and($policy)->toContain('microphone=()')
        ->and($policy)->toContain('geolocation=()');
});

test('agents responses are not store-cached and omit the withdrawn FLoC permission', function () {
    $response = getOnAgentsSurface('/login');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Permissions-Policy'))->not->toContain('interest-cohort');
});

test('agent login HTML stamps the CSP nonce on Flux and Vite tags', function () {
    $response = getOnAgentsSurface('/login');

    $response->assertOk();
    $response->assertSee('Sign in with Microsoft');

    preg_match("/script-src 'self' 'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $matches);
    expect($matches[1] ?? null)->not->toBeEmpty();

    $nonce = $matches[1];
    $html = $response->getContent();

    expect($html)->toContain('nonce="'.$nonce.'"');
});

test('authenticated staff dashboard still renders with Livewire-capable markup', function () {
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $host = agentsSurfaceHost();

    $response = $this->actingAs($staff)
        ->withServerVariables(['HTTP_HOST' => $host])
        ->get('http://'.$host.'/dashboard');

    $response->assertOk();
    $response->assertSee('Total Sites');

    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain("frame-ancestors 'none'");
    expect($response->getContent())->toContain('nonce="');
});

test('editor-preview responses keep EditorPreviewCsp and do not get agents frame-ancestors none', function () {
    if (! file_exists(public_path('build-editor-preview/manifest.json'))) {
        $this->markTestSkipped('build-editor-preview manifest not built');
    }

    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $page = GeneratedPage::factory()->for($site)->create();

    config()->set('domains.editor_preview_domain', 'editor-preview.test');
    config()->set('domains.agent_domain', 'agents.test');
    config()->set('domains.customer_domain', 'app.test');

    $response = $this->actingAs($user)
        ->get(route('editor-preview.show', [
            'site' => $site->id,
            'page' => $page->id,
        ]));

    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain('frame-ancestors https://agents.test https://app.test')
        ->and($csp)->not->toContain("frame-ancestors 'none'");
    expect($response->headers->get('X-Content-Type-Options'))->toBeNull();
});

test('the customer host receives the baseline isolation headers', function () {
    $host = (string) config('domains.customer_domain');

    // `/` not `/login`: Fortify's login is bound to the PRIMARY domain, which the test
    // env sets to a different value than the customer domain (no deployment does).
    $response = $this->withServerVariables(['HTTP_HOST' => $host])
        ->get('http://'.$host.'/');

    // This surface serves the client portal AND the editor shell under an
    // authenticated session, so it must carry the same baseline isolation
    // headers as any other authenticated route, not just the editor route.
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->get('Permissions-Policy'))->toContain('camera=()');
});

test('the customer host receives a strict nonce CSP', function () {
    $host = (string) config('domains.customer_domain');

    // `/` not `/login`: Fortify's login is bound to the PRIMARY domain, which the test
    // env sets to a different value than the customer domain (no deployment does).
    $response = $this->withServerVariables(['HTTP_HOST' => $host])
        ->get('http://'.$host.'/');

    $csp = (string) $response->headers->get('Content-Security-Policy');

    // An empty `'nonce-'` authorises nothing, so assert a REAL one — that is the
    // shape that shipped broken on the agents surface and was caught by its own test.
    expect($csp)->toMatch("/script-src 'self' 'nonce-[^']+'/")
        ->and($csp)->toContain("default-src 'self'")
        ->and($csp)->toContain("connect-src 'self'")
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->toContain("form-action 'self'")
        ->and($csp)->toContain("base-uri 'self'")
        ->and($csp)->toContain("object-src 'none'");
});

test('every script on the client portal is one the customer CSP would run', function () {
    $client = Client::factory()->create();
    $user = User::factory()->create([
        'client_id' => $client->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $client->id]);

    $host = (string) config('domains.customer_domain');

    // The portal's Pages tab: the deepest client-facing page, and the one that
    // pulls in page-manager. Rendered output, not the policy string — a policy
    // that reads correct and a page that dies under it is exactly what shipped
    // twice on the agents surface.
    $response = test()->actingAs($user)
        ->withServerVariables(['HTTP_HOST' => $host])
        ->get('http://'.$host.'/sites/'.$site->id);

    $response->assertOk();

    assertNoRefusedScripts(
        (string) $response->getContent(),
        (string) $response->headers->get('Content-Security-Policy'),
        $host,
        'the portal Pages tab',
    );
});

test('every script on the customer-surface editor shell is one the CSP would run', function () {
    if (! file_exists(public_path('build-customer/manifest.json'))) {
        test()->markTestSkipped('build-customer manifest not built');
    }

    $client = Client::factory()->create();
    $user = User::factory()->create([
        'client_id' => $client->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $client->id]);
    $page = GeneratedPage::factory()->for($site)->create();

    $host = (string) config('domains.customer_domain');

    // The shell's `<script @cspNonce>` config block is the exact tag that rendered
    // as page text on the agents surface and left the editor dead for two commits.
    // On this host the nonce was minted as '' until the surface got a policy.
    $response = test()->actingAs($user)
        ->withServerVariables(['HTTP_HOST' => $host])
        ->get('http://'.$host.'/sites/'.$site->id.'/pages/'.$page->id.'/editor');

    $response->assertOk();

    assertNoRefusedScripts(
        (string) $response->getContent(),
        (string) $response->headers->get('Content-Security-Policy'),
        $host,
        'the customer-surface editor shell',
    );
});

test('unrelated hosts are still left alone', function () {
    $response = $this->withServerVariables(['HTTP_HOST' => 'public-site.example'])
        ->get('http://public-site.example/');

    expect($response->headers->get('X-Frame-Options'))->toBeNull()
        ->and($response->headers->get('Content-Security-Policy'))->toBeNull();
});

test('legacy admin preview gets a relaxed CSP and keeps every other isolation header', function () {
    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $revision = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Preview']]],
    ]);
    $page->update(['published_revision_id' => $revision->id]);

    $host = agentsSurfaceHost();
    $path = '/sites/'.$site->id.'/pages/'.$page->id.'/preview';

    $response = $this->actingAs($user)
        ->withServerVariables(['HTTP_HOST' => $host])
        ->get('http://'.$host.$path);

    $response->assertOk();

    // The strict nonce CSP would annihilate this page: it renders public-site HTML
    // with CDN Alpine/lucide and un-nonced inline scripts. It gets a RELAXED policy
    // — not no policy. This is the one staff-origin route that renders generated
    // site content, so leaving it unpoliced was the review's objection to the
    // first version of this fix.
    $csp = (string) $response->headers->get('Content-Security-Policy');

    // Assert something ONLY the relaxed policy satisfies. Every assertion below used
    // to be satisfied by the STRICT policy too ('unsafe-inline' appears in its
    // style-src, jsdelivr and unpkg in its style-src/img-src), so this test passed
    // for an hour while the route was actually being served the strict policy.
    expect($csp)->toMatch("/script-src[^;]*'unsafe-inline'/")
        ->and($csp)->not->toContain('nonce-');

    expect($csp)->not->toBe('')
        // Relaxed exactly where the page needs it...
        ->and($csp)->toContain("'unsafe-inline'")
        ->and($csp)->toContain('https://cdn.jsdelivr.net')
        ->and($csp)->toContain('https://unpkg.com')
        // Public-site HTML now self-hosts fonts; bunny is not loaded here.
        ->and($csp)->not->toContain('fonts.bunny.net')
        // ...and nowhere else. connect-src is what keeps an injected script from
        // exfiltrating over the network API.
        ->and($csp)->toContain("connect-src 'self'")
        ->and($csp)->toContain("form-action 'self'")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->toContain("base-uri 'self'")
        // The map assets that view actually loads. Asserting `toContain('unpkg')`
        // alone was satisfied by script-src while style-src lacked it, which is how
        // the unstyled-map break stayed invisible.
        ->and($csp)->toMatch('/style-src[^;]*https:\/\/unpkg\.com/')
        ->and($csp)->toMatch('/img-src[^;]*https:\/\/\*\.tile\.openstreetmap\.org/');

    // Everything else must survive. This is the one staff-origin route that renders
    // content derived from generated site data, so dropping nosniff / frame options
    // / referrer policy here is strictly worse than dropping them anywhere else.
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Permissions-Policy'))->toContain('camera=()');
});

test('staff views do not use native HTML event-handler attributes', function () {
    $files = [
        resource_path('views/sites/index.blade.php') => ['x-on:change'],
        resource_path('views/livewire/page-manager.blade.php') => ['x-on:click', 'x-on:error'],
        resource_path('views/livewire/personalise-tab.blade.php') => ['x-on:click'],
        resource_path('views/livewire/project-item-card.blade.php') => ['x-on:click'],
        resource_path('views/livewire/home-hero-video-studio.blade.php') => ['x-on:mouseover', 'x-on:mouseout'],
    ];

    foreach ($files as $file => $required) {
        expect($file)->toBeFile();
        $contents = (string) file_get_contents($file);

        expect($contents)->not->toMatch('/\son(?:change|click|error|mouseover|mouseout)\s*=/');

        foreach ($required as $needle) {
            expect($contents)->toContain($needle);
        }
    }
});

test('isolation headers are present on 404s and redirects, not just 200s', function () {
    // AgentsSecurityHeaders must run on unmatched routes and exception-derived
    // responses too, not just on 200s reached via the `web` group: a middleware
    // registered only on the route-scoped group never runs for a 404, and an
    // exception-derived response unwinds past it, so 404s, redirects and 405s
    // would otherwise carry no CSP, no nosniff, no frame options and no
    // referrer policy — including the guest login redirect.
    $host = agentsSurfaceHost();

    $notFound = test()->withServerVariables(['HTTP_HOST' => $host])
        ->get('http://'.$host.'/no-such-page-'.uniqid());

    $notFound->assertNotFound();

    expect($notFound->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($notFound->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($notFound->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($notFound->headers->get('Content-Security-Policy'))->not->toBeNull();

    // And the guest redirect, which is produced by an exception.
    $redirect = test()->withServerVariables(['HTTP_HOST' => $host])
        ->get('http://'.$host.'/sites');

    $redirect->assertRedirect();

    expect($redirect->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($redirect->headers->get('X-Frame-Options'))->toBe('DENY');
});

test('the customer surface also gets headers on a 404', function () {
    $host = (string) config('domains.customer_domain');

    $response = test()->withServerVariables(['HTTP_HOST' => $host])
        ->get('http://'.$host.'/no-such-page-'.uniqid());

    $response->assertNotFound();

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY');
});

test('a path that looks like the preview but resolves to no route gets the strict policy with a real nonce', function () {
    // Laravel's `*` matches slashes, so `sites/<anything>/pages/<anything>/preview`
    // is a wide, attacker-selectable path space. The CSP decision must be based on
    // the route that actually matched, not on a pre-routing path guess — otherwise
    // any 404, 405 or guest redirect under that glob would get the permissive
    // preview policy ('unsafe-inline' plus two CDN script origins).
    $host = agentsSurfaceHost();

    $response = test()->withServerVariables(['HTTP_HOST' => $host])
        ->get('http://'.$host.'/sites/44/pages/206/junk/preview');

    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->not->toContain("'unsafe-inline' 'unsafe-eval'")
        ->and($csp)->toMatch("/script-src 'self' 'nonce-[^']+'/",
            'A strict policy with an empty nonce authorises nothing and kills inline scripts.');
});

test('an error page renders with styles the CSP will actually apply', function () {
    // A globally-registered strict CSP applies to error responses too, and the
    // framework's error layout emits un-nonced <style> blocks, which a
    // nonce-bearing style-src refuses. Asserting only the HEADERS on a 404 is not
    // enough — the page must still render styled under that same policy.
    $host = agentsSurfaceHost();

    $response = test()->withServerVariables(['HTTP_HOST' => $host])
        ->get('http://'.$host.'/no-such-page-'.uniqid());

    $response->assertNotFound();

    $html = (string) $response->getContent();
    $csp = (string) $response->headers->get('Content-Security-Policy');

    preg_match("/style-src [^;]*'nonce-([^']+)'/", $csp, $matches);
    $nonce = $matches[1] ?? null;

    expect($nonce)->not->toBeNull();

    preg_match_all('/<style\b([^>]*)>/i', $html, $styleTags, PREG_SET_ORDER);

    expect($styleTags)->not->toBeEmpty('The error page has no <style> block — has the layout changed?');

    // NB toContain() is variadic in Pest — passing a message makes it a second
    // needle. Collect offenders and assert on the list instead.
    $refused = [];

    foreach ($styleTags as [$tag, $attributes]) {
        if (! str_contains($attributes, 'nonce="'.$nonce.'"')) {
            $refused[] = trim($tag);
        }
    }

    expect($refused)->toBe([], 'These <style> blocks would be refused by the response\'s own CSP, '
        .'so the error page renders unstyled: '.implode(', ', $refused));
});

test('the nonce scanner catches CSP nonce-check defeats', function () {
    $csp = "script-src 'self' 'nonce-ABC' 'unsafe-eval'";
    $host = 'agents.example';

    // Backslash authority: the browser loads evil.example, parse_url() sees no host.
    expect(scriptsCspWouldRefuse('<script src="https:/\\evil.example/x.js"></script>', $csp, $host))
        ->toHaveCount(1, 'a backslash authority must not read as a relative URL');

    // 'self' is origin-exact: a different port is a different origin.
    expect(scriptsCspWouldRefuse('<script src="https://agents.example:1337/x.js"></script>', $csp, $host))
        ->toHaveCount(1, 'a different port is a different origin and CSP refuses it');

    // Controls: genuinely same-origin and relative stay clean; cross-origin caught.
    expect(scriptsCspWouldRefuse('<script src="https://agents.example/x.js"></script>', $csp, $host))->toBe([])
        ->and(scriptsCspWouldRefuse('<script src="/x.js"></script>', $csp, $host))->toBe([])
        ->and(scriptsCspWouldRefuse('<script src="https://cdn.example/x.js"></script>', $csp, $host))->toHaveCount(1)
        ->and(scriptsCspWouldRefuse('<script nonce="ABC">go()</script>', $csp, $host))->toBe([]);
});
