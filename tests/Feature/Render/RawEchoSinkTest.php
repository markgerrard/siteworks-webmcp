<?php

use Illuminate\Support\Facades\File;

/**
 * Raw `{!! !!}` echoes on the staff surfaces.
 *
 * The agents CSP concedes `'unsafe-eval'`, because Flux's Alpine build compiles every
 * `x-data` / `x-on` / `x-init` through `new Function()`. That means an attacker who
 * can inject HTML into a staff page can add Alpine directives, and the trusted Alpine
 * runtime executes them. The nonce is irrelevant — no new <script> element is created.
 *
 * So the nonce protects against script-TAG injection, not HTML injection, and the
 * thing standing between those two is this: there is currently no raw-HTML sink on a
 * staff surface carrying attacker-controlled data. The only raw echo on these surfaces
 * is built from literals and an integer id.
 *
 * That is a property of today's code, not of the architecture, and nothing was
 * stopping the next one appearing. This test is the cheap half of the mitigation: the
 * expensive half is Alpine's CSP build, which needs ~326 inline expressions rewritten
 * as registered components and is tracked separately.
 */
const ALLOWED_RAW_ECHOES = [
    // Alpine's initial tab, built entirely from string literals plus $site->id (an
    // integer primary key). Raw because it is a JS expression, not content. If this
    // ever interpolates a user-supplied string, it becomes an XSS sink under
    // 'unsafe-eval'.
    // Fortify's generated 2FA QR code, an SVG built by the QR library from the user's
    // own TOTP secret. Raw because it IS markup. Safe on two counts: the library
    // renders the URI as bitmap paths rather than text, and the property holding it is
    // #[Locked], so a client cannot substitute its own markup through a property
    // update. Both were checked — the lock is what makes the raw echo defensible.
    'pages/settings/⚡two-factor-setup-modal.blade.php' => 1,
];

/**
 * Views served on a surface that holds a staff session.
 */
function staffSurfaceViewDirectories(): array
{
    return ['sites', 'clients', 'admin', 'livewire', 'settings', 'components/layouts', 'pages'];
}

test('no unreviewed raw-HTML sink exists on a staff surface', function () {
    $found = [];

    foreach (staffSurfaceViewDirectories() as $directory) {
        $path = resource_path('views/'.$directory);

        if (! is_dir($path)) {
            continue;
        }

        foreach (File::allFiles($path) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            // Strip comments so a documented example does not register as a sink.
            $contents = preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents()) ?? $file->getContents();

            $count = preg_match_all('/\{!!.*?!!\}/s', $contents);

            if ($count === 0) {
                continue;
            }

            $relative = str_replace('\\', '/', $directory.'/'.$file->getRelativePathname());
            $allowed = ALLOWED_RAW_ECHOES[$relative] ?? 0;

            if ($count > $allowed) {
                $found[] = "{$relative} ({$count} raw echo".($count === 1 ? '' : 'es')
                    .', '.$allowed.' reviewed)';
            }
        }
    }

    expect($found)->toBe([], 'New raw echo on a staff surface. Under the agents CSP\'s '
        .'unsafe-eval, injected HTML carrying Alpine directives executes — so a raw echo of '
        .'anything user-supplied is an XSS sink, nonce or not. Escape it, or add it to '
        .'ALLOWED_RAW_ECHOES with the reason it is safe: '.implode(', ', $found));
});

test('the raw-echo allowlist still describes reality', function () {
    // An allowlist whose entries have moved silently widens the exemption.
    foreach (ALLOWED_RAW_ECHOES as $relative => $count) {
        $path = resource_path('views/'.$relative);

        expect(file_exists($path))->toBeTrue("Allowlisted view {$relative} no longer exists.");

        $contents = preg_replace('/\{\{--.*?--\}\}/s', '', File::get($path)) ?? '';

        expect(preg_match_all('/\{!!.*?!!\}/s', $contents))->toBe($count,
            "{$relative} no longer has exactly {$count} raw echo(es) — re-review it.");
    }
});
