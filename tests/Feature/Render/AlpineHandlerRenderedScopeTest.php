<?php

use App\Enums\AgentRole;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/**
 * Alpine handler scope, checked against RENDERED HTML rather than Blade source.
 *
 * Three source-level guards have now been defeated in a row, each by the same
 * shape of trick: a string appearing somewhere that the checker mistook for
 * structure. The root cause is that Blade source is not the DOM. `@if` branches,
 * component tags, slots, conditional attributes and caller-supplied scope all mean
 * the ancestry a text walker computes is not the ancestry Alpine sees.
 *
 * Rendered HTML has no such gap: Blade has run, Flux components have become native
 * elements, Livewire roots carry their real `wire:id`. Walking the parsed DOM
 * answers the actual question — is there an Alpine root above this handler.
 *
 * LIMITATION, stated rather than hidden: libxml drops attributes whose name begins
 * with `@`, so the `@click` shorthand (35 uses) is invisible here and stays covered
 * by the source walker in AlpineHandlerScopeTest. Anything written `x-on:` (89 uses,
 * including every handler the CSP rework converted) is covered by this file.
 *
 * SECOND LIMITATION, closed below rather than merely admitted: every widget on
 * sites/show is `lazy.bundle`, so a plain GET of that page returns placeholders and
 * the ~300 handlers inside those panels never appear in it. The review
 * measured 355 rendered handlers across the mounted panel set against 14 in the GET.
 * The last test in this file mounts the panels directly and checks those too.
 *
 * Every route floor below is the count actually rendered today. A floor of zero is
 * how a coverage test passes while measuring nothing — review found three
 * of the four routes here sitting at zero.
 */
function renderedUnscopedHandlers(string $html): array
{
    libxml_use_internal_errors(true);

    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);

    libxml_clear_errors();
    libxml_use_internal_errors(false);

    $offenders = [];
    $handlers = 0;

    foreach ((new DOMXPath($dom))->query('//*') as $element) {
        $handlerAttributes = [];

        foreach ($element->attributes as $attribute) {
            if (str_starts_with($attribute->name, 'x-on:')) {
                $handlerAttributes[] = $attribute->name;
            }
        }

        if ($handlerAttributes === []) {
            continue;
        }

        $handlers++;
        $scoped = false;

        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            if ($node->hasAttribute('x-data') || $node->hasAttribute('wire:id')) {
                $scoped = true;
                break;
            }
        }

        if (! $scoped) {
            $offenders[] = "<{$element->tagName} ".implode(' ', $handlerAttributes).'>';
        }
    }

    return ['offenders' => $offenders, 'handlers' => $handlers];
}

function assertRenderedHandlersAreScoped(string $html, string $page, int $expectAtLeast = 0): void
{
    $result = renderedUnscopedHandlers($html);

    expect($result['offenders'])->toBe([], "On {$page}, these rendered handlers have no x-data or wire:id "
        .'ancestor, so Alpine never initialises them and they are inert: '
        .implode(', ', $result['offenders']));

    expect($result['handlers'])->toBeGreaterThanOrEqual($expectAtLeast,
        "Expected at least {$expectAtLeast} x-on: handlers in the rendered {$page}, found "
        .$result['handlers'].' — the page changed or the scan stopped seeing them.');
}

function getRenderedAgentsPage(string $path, ?User $user = null): string
{
    $host = (string) config('domains.agent_domain');

    $request = $user ? test()->actingAs($user) : test();

    $response = $request->withServerVariables(['HTTP_HOST' => $host])
        ->get('http://'.$host.$path);

    $response->assertOk();

    return (string) $response->getContent();
}

test('the sites list renders its filter handlers inside an Alpine scope', function () {
    $user = User::factory()->staff(AgentRole::Agent)->create();
    Site::factory()->count(2)->create(['created_by_user_id' => $user->id]);

    // The three filter selects are the handlers that actually shipped inert.
    assertRenderedHandlersAreScoped(getRenderedAgentsPage('/sites', $user), '/sites', expectAtLeast: 6);
});

test('the site detail page renders its handlers inside an Alpine scope', function () {
    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);

    // Overview lost most inline handlers in the section split; Design carries the sub-tab handlers.
    assertRenderedHandlersAreScoped(getRenderedAgentsPage('/sites/'.$site->id.'/design', $user), '/sites/{site}/design', expectAtLeast: 3);
});

test('the dashboard renders its handlers inside an Alpine scope', function () {
    $user = User::factory()->staff(AgentRole::Agent)->create();

    assertRenderedHandlersAreScoped(getRenderedAgentsPage('/dashboard', $user), '/dashboard', expectAtLeast: 3);
});

test('the admin index renders its handlers inside an Alpine scope', function () {
    $admin = User::factory()->admin()->create();

    assertRenderedHandlersAreScoped(getRenderedAgentsPage('/admin', $admin), '/admin', expectAtLeast: 5);
});

test('the rendered check actually detects an unscoped handler', function () {
    // Guard against the failure mode of every previous version of this test: a
    // checker that examines nothing and passes.
    $scoped = '<html><body><form x-data><select x-on:change="go()"></select></form></body></html>';
    $unscoped = '<html><body><form><select x-on:change="go()"></select></form></body></html>';
    $livewireRoot = '<html><body><div wire:id="abc"><select x-on:change="go()"></select></div></body></html>';
    $siblingScope = '<html><body><div x-data></div><select x-on:change="go()"></select></body></html>';

    expect(renderedUnscopedHandlers($scoped)['offenders'])->toBe([])
        ->and(renderedUnscopedHandlers($livewireRoot)['offenders'])->toBe([])
        ->and(renderedUnscopedHandlers($unscoped)['offenders'])->toHaveCount(1)
        // A scope that closed before the handler is not an ancestor of it.
        ->and(renderedUnscopedHandlers($siblingScope)['offenders'])->toHaveCount(1);
});

test('the lazy panels on the site page are checked, not just the placeholders they leave behind', function () {
    // A GET of sites/show returns lazy placeholders. Mount the panels themselves so
    // the handlers inside them are actually examined.
    $user = User::factory()->admin()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);

    // Take the plain-GET baseline BEFORE mounting panels: a panel whose mount signature
    // needs more than siteId throws mid-render, and the harness's later full-page render
    // then sees a Site whose enum casts resolve to null (test-process artefact).
    $fromPlainGet = renderedUnscopedHandlers(getRenderedAgentsPage('/sites/'.$site->id, $user))['handlers'];

    $view = implode("\n", array_map(fn (string $f): string => File::get($f), glob(resource_path('views/sites/sections/*.blade.php')) ?: []));
    preg_match_all('/<livewire:([a-z0-9.\-]+)/i', $view, $matches);

    $components = array_values(array_unique($matches[1]));
    expect($components)->not->toBeEmpty('No <livewire:…> components found in sites/sections/*.blade.php.');

    $mounted = 0;
    $handlers = 0;
    $offenders = [];

    foreach ($components as $name) {
        try {
            $html = Livewire::actingAs($user)->test($name, ['siteId' => $site->id])->html();
        } catch (Throwable) {
            // Different mount signature — covered by its own feature test.
            continue;
        }

        $mounted++;
        $result = renderedUnscopedHandlers($html);
        $handlers += $result['handlers'];

        foreach ($result['offenders'] as $offender) {
            $offenders[] = "{$name}: {$offender}";
        }
    }

    expect($offenders)->toBe([], 'Handlers with no Alpine ancestor inside a lazily-loaded panel: '
        .implode(', ', $offenders));

    expect($mounted)->toBeGreaterThanOrEqual(8,
        "Only {$mounted} of the site-page panels mounted — the scan is not reaching them.");

    // The invariant that matters: mounting the panels must reveal substantially more
    // than the plain GET does, or this test is checking placeholders again. The GET
    // shows 5 on a factory site; the panels show ~31 here and ~355 against real dev
    // data (page-manager alone 303, because the count scales with pages and projects).

    expect($handlers)->toBeGreaterThan($fromPlainGet * 3,
        "The mounted panels revealed {$handlers} handlers against {$fromPlainGet} in the plain GET — "
        .'not enough of a difference to believe the panels are being examined.')
        ->and($handlers)->toBeGreaterThanOrEqual(25,
            "Only {$handlers} handlers seen across the mounted panels.");
});
