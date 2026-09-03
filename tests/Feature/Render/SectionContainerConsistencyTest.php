<?php

use Symfony\Component\Finder\Finder;

/**
 * Regression guard for container drift: wide content bands must use the
 * themed shell (site-shell-container → --container-width: 1280px on
 * compact/balanced, 1360px on generous density), NOT hardcoded Tailwind
 * containers. A hardcoded max-w-6xl/7xl shell equals the token only by
 * coincidence — on generous-density sites it sits inset from the hero,
 * nav, and footer (the "hero starts wider than the content" wonk).
 *
 * Narrow deliberate measures are fine: prose bands (seo/geo/article-body),
 * the standalone contact form, and inner max-w-2xl..5xl constraints don't
 * match the forbidden pattern (px-4 sm:px-6 suffix marks a page shell).
 */
it('no section template hardcodes a wide page-shell container', function () {
    $offenders = [];

    $dirs = [resource_path('views/site/sections'), resource_path('views/site/partials')];
    foreach (Finder::create()->files()->in($dirs)->name('*.blade.php') as $file) {
        if (preg_match('/max-w-[67]xl mx-auto px-4 sm:px-6/', $file->getContents())) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe(
        [],
        'These site templates hardcode a wide shell instead of site-shell-container: '.implode(', ', $offenders)
    );
});
