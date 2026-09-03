<?php

use Illuminate\Support\Facades\File;

/**
 * `$siteId` identifies the tenant. It must never be settable from the client.
 *
 * Without `#[Locked]`, a client's `/livewire/update` carrying
 * `updates: {siteId: <someone else's>}` is accepted and the component rehydrates
 * pointed at another tenant's site. On its own this is not yet a leak on every
 * customer-portal component, because the action paths those components read
 * re-derive through `findAuthorizedSite()` and fail closed — but that re-derivation
 * is an assumption each action path has to remember, not a guarantee.
 *
 * That is the point: the tenancy boundary rested on every action path remembering to
 * re-derive, which is an easy assumption for a new component to miss. Locking the
 * property removes the class of bug instead of auditing each path forever.
 */
test('every Livewire component with a siteId property locks it', function () {
    $unlocked = [];
    $checked = 0;

    foreach (File::allFiles(resource_path('views/livewire')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $contents = $file->getContents();

        // Strip comments first: a commented-out `// #[Locked]` above the property
        // must not count as locked. And match ANY type declaration — untyped,
        // readonly and union-typed properties are invisible to `\??int`.
        $contents = preg_replace('!//[^\n]*!', '', $contents) ?? $contents;
        $contents = preg_replace('!/\*.*?\*/!s', '', $contents) ?? $contents;
        $contents = preg_replace('/\{\{--.*?--\}\}/s', '', $contents) ?? $contents;

        // Any public property naming a tenant record, not just $siteId.
        $pattern = '/(#\[Locked\][^;]{0,120}?)?public\s+(?:readonly\s+)?[\w?|\\\\]*\s*\$(siteId|clientId|activeSiteId|importId)\b/';

        if (! preg_match($pattern, $contents, $matches)) {
            continue;
        }

        $checked++;

        if (($matches[1] ?? '') === '') {
            $unlocked[] = $file->getRelativePathname().' ($'.$matches[2].')';
        }
    }

    expect($unlocked)->toBe([], 'These components expose $siteId as a client-writable property, so a '
        .'/livewire/update payload can repoint them at another tenant: '.implode(', ', $unlocked));

    expect($checked)->toBeGreaterThanOrEqual(35,
        "Only {$checked} components with a \$siteId were found — the scan stopped matching.");
});

test('class-based Livewire components lock their site property too', function () {
    $unlocked = [];

    foreach (File::allFiles(app_path('Livewire')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = $file->getContents();

        if (preg_match('/(#\[Locked\]\s*\n\s*)?public\s+(\??int\s+\$siteId|Site\s+\$site)/', $contents, $matches)
            && ($matches[1] ?? '') === '') {
            $unlocked[] = $file->getRelativePathname();
        }
    }

    expect($unlocked)->toBe([], 'Unlocked tenant identity on: '.implode(', ', $unlocked));
});
