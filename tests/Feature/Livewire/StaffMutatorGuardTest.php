<?php

use App\Enums\AgentRole;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/**
 * `AuthorizesSiteAccess` alone is not a staff check — a CLIENT of the site passes it
 * by design. Any agents-surface mutator (`unpublished-changes-banner::publish/discard`,
 * `watermark-toggle::toggle`, and the like) must sit behind an explicit role check as
 * well. Enumerating the whole inventory as a test, rather than checking each component
 * as it is written, is what closes the class of bug.
 */
beforeEach(function () {
    $this->client = Client::factory()->create();
    $this->staff = User::factory()->staff(AgentRole::Agent)->create();
    $this->site = Site::factory()->create([
        'client_id' => $this->client->id,
        'created_by_user_id' => $this->staff->id,
    ]);
    $this->clientUser = User::factory()->create([
        'role' => null,
        'client_id' => $this->client->id,
    ]);
});

test('a client of the site can publish and discard drafts from the unpublished-changes banner', function () {
    Livewire::actingAs($this->clientUser)
        ->test('site.unpublished-changes-banner', ['siteId' => $this->site->id])
        ->call('publish')
        ->assertOk();

    Livewire::actingAs($this->clientUser)
        ->test('site.unpublished-changes-banner', ['siteId' => $this->site->id])
        ->call('discard')
        ->assertOk();
});

/**
 * Components under views/livewire/site that are deliberately reachable by a CLIENT
 * of the site, with the reason. Anything not listed here must carry a role trait or
 * an in-action role check.
 */
const CLIENT_REACHABLE_SITE_COMPONENTS = [
    // Mounted on client/portal/history.blade.php: clients see and restore their own
    // site's versions. Guards site access AND that the version belongs to the site.
    'version-history.blade.php',
    // Portal editor chrome: the demo client publishes and discards their own drafts.
    'unpublished-changes-banner.blade.php',
];

/**
 * Root-level Volt components (views/livewire/*.blade.php, not under site/)
 * that are deliberately reachable by a CLIENT of the authed site.
 *
 * StaffMutatorGuard's inventory only walks views/livewire/site, so these
 * would otherwise be invisible. Anything listed here must stay
 * tenancy-scoped (AuthorizesSiteAccess) and must NOT grow a staff-only
 * role trait without updating HeaderStyleSettingsTest's client-allow path.
 */
const CLIENT_REACHABLE_ROOT_COMPONENTS = [
    // Spec W0: header-style-settings keeps its name/file/hook and "Shares
    // AuthorizesSiteAccess". Posture baseline: no new auth — writes are
    // tenancy-scoped to the authed site, so a client of THAT site may
    // persist chrome knobs; a client of another site is denied.
    'header-style-settings.blade.php',
    // Logo size / overlay logo size / logo margin: same tenancy-scoped
    // AuthorizesSiteAccess posture as header-style-settings (no staff-only
    // role trait). setOverlayLogoSize is registered here with setLogoSize.
    'logo-size-settings.blade.php',
];

test('every agents-surface Livewire component with a public action carries a role check', function () {
    // Detection is by PUBLIC ACTION, not by write verb. Matching only on
    // `->save(`, `->update(`, `->delete(` misses footer-editor (`->updateFooter(`)
    // and page-list-manager (`->setHomepage(`) — both genuinely client-writable
    // actions that a verb-based heuristic is too narrow to catch.
    $lifecycle = ['mount', 'render', 'with', 'boot', 'booted', 'hydrate', 'dehydrate',
        'rules', 'messages', 'validationAttributes', 'placeholder'];

    $unguarded = [];
    $checked = 0;

    foreach (File::allFiles(resource_path('views/livewire/site')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $contents = $file->getContents();

        preg_match_all('/public function (\w+)\s*\(/', $contents, $methodMatches);

        $actions = array_values(array_filter(
            $methodMatches[1],
            fn (string $method) => ! in_array($method, $lifecycle, true)
                && ! str_starts_with($method, 'updated')
                && ! str_starts_with($method, 'updating')
                && ! str_starts_with($method, 'get'),
        ));

        if ($actions === []) {
            continue;
        }

        $checked++;

        if (in_array($file->getFilename(), CLIENT_REACHABLE_SITE_COMPONENTS, true)) {
            continue;
        }

        $guarded = str_contains($contents, 'RequiresStaffRole')
            || str_contains($contents, 'RequiresAdminRole')
            || str_contains($contents, 'RequiresSuperAdminRole')
            || str_contains($contents, 'assertCurrentUserIs')
            || str_contains($contents, 'isStaff()')
            || str_contains($contents, 'isAgent()')
            || str_contains($contents, 'isAdmin()');

        if (! $guarded) {
            $unguarded[] = $file->getRelativePathname().' ('.implode(', ', $actions).')';
        }
    }

    expect($unguarded)->toBe([], 'These site components expose public Livewire actions with no role '
        .'trait and no in-action role check, so AuthorizesSiteAccess alone decides — and a client of '
        .'the site passes it. Guard them, or add them to CLIENT_REACHABLE_SITE_COMPONENTS with a '
        .'reason: '.implode(', ', $unguarded));

    expect($checked)->toBeGreaterThanOrEqual(1,
        "Only {$checked} components with public actions were found — the scan stopped matching.");
});

test('the client-reachable allowlist does not rot', function () {
    // An allowlist that outlives its entries silently widens. Each must still exist
    // and must still be mounted somewhere on the client portal.
    foreach (CLIENT_REACHABLE_SITE_COMPONENTS as $filename) {
        $path = resource_path('views/livewire/site/'.$filename);

        expect(file_exists($path))->toBeTrue("Allowlisted component {$filename} no longer exists.");
    }

    foreach (CLIENT_REACHABLE_ROOT_COMPONENTS as $filename) {
        $path = resource_path('views/livewire/'.$filename);

        expect(file_exists($path))->toBeTrue("Allowlisted root component {$filename} no longer exists.");
    }

    $portal = '';

    foreach (File::allFiles(resource_path('views/client')) as $file) {
        $portal .= $file->getContents();
    }

    // NB toContain() is variadic in Pest — a message becomes a second needle.
    expect(str_contains($portal, 'site.version-history'))->toBeTrue(
        'version-history is allowlisted as client-reachable but the portal no longer mounts it.');
});

test('header-style-settings is recorded as client-writable per spec W0', function () {
    expect(CLIENT_REACHABLE_ROOT_COMPONENTS)->toContain('header-style-settings.blade.php');

    $contents = File::get(resource_path('views/livewire/header-style-settings.blade.php'));

    expect($contents)->toContain('AuthorizesSiteAccess')
        ->and($contents)->not->toContain('RequiresStaffRole')
        ->and($contents)->toContain('function setKnob')
        ->and($contents)->toContain("'overlay_glass' => ['off', 'scrolled', 'floating', 'always']")
        ->and($contents)->toContain("'nav_case' => ['normal', 'upper', 'lower']")
        ->and($contents)->toContain("'header_shrink' => ['on', 'off']")
        ->and($contents)->toContain("'header_fit' => ['comfortable', 'tight']")
        ->and($contents)->toContain("'overlay_inner_scale' => ['overlay', 'main']")
        ->and($contents)->toContain("'right_action' => ['phone', 'cta', 'phone_cta', 'none']")
        ->and($contents)->toContain("'nav_cta_target' => ['url', 'form']")
        ->and($contents)->toContain('function setHeaderPadding')
        ->and($contents)->toContain('function saveCta')
        ->and($contents)->toContain('function clearCta')
        ->and($contents)->toContain('function setMotto')
        ->and($contents)->toContain('function setFooterLogo');
});

test('logo-size-settings is recorded as client-writable with setOverlayLogoSize', function () {
    expect(CLIENT_REACHABLE_ROOT_COMPONENTS)->toContain('logo-size-settings.blade.php');

    $contents = File::get(resource_path('views/livewire/logo-size-settings.blade.php'));

    expect($contents)->toContain('AuthorizesSiteAccess')
        ->and($contents)->not->toContain('RequiresStaffRole')
        ->and($contents)->toContain('function setLogoSize')
        ->and($contents)->toContain('function setOverlayLogoSize')
        ->and($contents)->toContain('function setLogoMargin')
        ->and($contents)->toContain('function setOverlayLogoMargin');
});
