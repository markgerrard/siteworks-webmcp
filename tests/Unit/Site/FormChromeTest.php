<?php

use App\Models\Site;
use App\Services\Site\FormChrome;
use App\Services\Site\ThemeResolver;

test('boxed returns the exact legacy literals per family', function () {
    $site = new Site(['form_style' => null]);
    expect(FormChrome::inputClass($site, 'contact'))->toBe('w-full rounded-md border border-gray-300 px-4 py-2.5 text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:border-transparent transition-shadow')
        ->and(FormChrome::inputClass($site, 'lead'))->toBe('w-full px-4 py-2.5 rounded-md border border-gray-300 text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:border-transparent transition-shadow')
        ->and(FormChrome::labelClass($site))->toBe('block text-sm font-semibold text-gray-700 mb-1.5');
});

test('a null site is boxed — isolated section views have no $site', function () {
    expect(FormChrome::inputClass(null, 'contact'))->toBe(FormChrome::BOXED_INPUT)
        ->and(FormChrome::inputClass(null, 'lead'))->toBe(FormChrome::LEAD_BOXED_INPUT)
        ->and(FormChrome::labelClass(null))->toBe(FormChrome::BOXED_LABEL)
        ->and(FormChrome::selectClass(null, 'contact'))->toBe(FormChrome::BOXED_SELECT)
        ->and(FormChrome::selectClass(null, 'lead'))->toBe(FormChrome::LEAD_BOXED_SELECT)
        ->and(FormChrome::radioOptionClass(null, 'lead'))->toBe(FormChrome::LEAD_BOXED_RADIO_OPTION);
});

test('underline has no box chrome and a token hairline', function () {
    $site = new Site(['form_style' => 'underline']);
    $c = FormChrome::inputClass($site, 'contact');
    expect($c)->not->toContain('border-gray-300')->not->toContain('rounded-md')
        ->toContain('border-b')->toContain('border-gray-500')->not->toContain('color-mix')
        ->toContain('focus:[border-bottom-color:var(--brand-accent)]');
    expect(FormChrome::labelClass($site))->toContain('uppercase')->toContain('tracking-[0.18em]');
});

test('boxed and null select chrome matches today\'s per-surface literals with no appearance-none', function () {
    $boxed = new Site(['form_style' => null]);

    expect(FormChrome::selectClass($boxed, 'contact'))
        ->toBe(FormChrome::BOXED_INPUT)
        ->toBe(FormChrome::BOXED_SELECT)
        ->not->toContain('appearance-none')
        ->and(FormChrome::selectClass($boxed, 'lead'))
        ->toBe(FormChrome::LEAD_BOXED_SELECT)
        ->toContain('bg-white')
        ->not->toContain('appearance-none')
        ->not->toContain('placeholder-gray-400');
});

test('underline select is the underline input set plus appearance-none', function () {
    $underline = new Site(['form_style' => 'underline']);

    expect(FormChrome::selectClass($underline, 'contact'))
        ->toBe(FormChrome::inputClass($underline, 'contact').' appearance-none')
        ->and(FormChrome::selectClass($underline, 'lead'))
        ->toBe(FormChrome::inputClass($underline, 'lead').' appearance-none');
});

test('radio option underline drops the boxed pill', function () {
    $boxed = new Site(['form_style' => null]);
    $underline = new Site(['form_style' => 'underline']);

    expect(FormChrome::radioOptionClass($boxed, 'contact'))
        ->toBe('flex items-center gap-2 text-sm text-gray-700')
        ->and(FormChrome::radioOptionClass($boxed, 'lead'))
        ->toBe('flex items-center gap-2 px-3 py-2 rounded-md border border-gray-300 cursor-pointer hover:bg-gray-50 transition-colors')
        ->and(FormChrome::radioOptionClass($underline))
        ->toBe('inline-flex items-center gap-2 py-1')
        ->and(FormChrome::radioOptionClass($underline, 'lead'))
        ->not->toContain('border-gray-300')
        ->not->toContain('rounded-md');
});

test('underline hairline contrast against white is at least 3:1 for every theme fixture', function (string $key) {
    $path = base_path('tests/fixtures/home-themes/demo-site-themes.json');
    $decoded = json_decode((string) file_get_contents($path), true);
    expect($decoded[$key] ?? null)->toBeArray();

    // The form card is hardcoded white, so the unfocused hairline is a FIXED
    // grey (Tailwind gray-500 / #6b7280) — not a mix of --color-text. Mixing
    // a light-on-dark token toward white collapses to ≈1:1 (54-nh).
    $hairline = '#6b7280';
    $ratio = app(ThemeResolver::class)->contrastRatio($hairline, '#ffffff');
    expect($ratio)->toBeGreaterThanOrEqual(3.0);

    $classes = FormChrome::inputClass(new Site(['form_style' => 'underline']));
    expect($classes)
        ->toContain('border-gray-500')
        ->not->toContain('color-mix');
})->with([
    '51-eden' => ['51-eden'],
    '52-hunt' => ['52-hunt'],
    '54-nh' => ['54-nh'],
    'light-archetype' => ['light-archetype'],
]);

test('explicit style overrides the site knob; null site still boxed', function () {
    $site = new \App\Models\Site(['form_style' => 'underline']);
    expect(\App\Services\Site\FormChrome::inputClass($site, 'lead', 'boxed'))->toBe(\App\Services\Site\FormChrome::LEAD_BOXED_INPUT)
        ->and(\App\Services\Site\FormChrome::inputClass(null, 'lead', 'soft-filled'))->toBe(\App\Services\Site\FormChrome::LEAD_SOFT_INPUT)
        ->and(\App\Services\Site\FormChrome::inputClass(null, 'contact', 'soft-filled'))->toBe(\App\Services\Site\FormChrome::SOFT_INPUT);
});

test('dark surfaces swap to light-on-dark controls', function () {
    expect(\App\Services\Site\FormChrome::inputClass(null, 'lead', 'underline', 'panel-inverted'))->toBe(\App\Services\Site\FormChrome::UNDERLINE_INPUT_DARK)
        ->and(\App\Services\Site\FormChrome::labelClass(null, 'underline', 'panel-inverted'))->toBe(\App\Services\Site\FormChrome::UNDERLINE_LABEL_DARK);
});

test('dark surfaces win over soft-filled, and selects, radios and errors have dark variants', function () {
    expect(\App\Services\Site\FormChrome::inputClass(null, 'lead', 'soft-filled', 'panel-inverted'))->toBe(\App\Services\Site\FormChrome::SOFT_INPUT_DARK)
        ->and(\App\Services\Site\FormChrome::inputClass(null, 'lead', 'boxed', 'panel-inverted'))->toBe(\App\Services\Site\FormChrome::BOXED_INPUT_DARK)
        ->and(\App\Services\Site\FormChrome::selectClass(null, 'lead', 'boxed', 'panel-inverted'))->toBe(\App\Services\Site\FormChrome::BOXED_INPUT_DARK.' appearance-none [&>option]:text-gray-900')
        ->and(\App\Services\Site\FormChrome::labelClass(null, 'boxed', 'panel-inverted'))->toBe(\App\Services\Site\FormChrome::BOXED_LABEL_DARK)
        ->and(\App\Services\Site\FormChrome::radioOptionClass(null, 'lead', null, 'panel-inverted'))->toBe(\App\Services\Site\FormChrome::RADIO_OPTION_DARK)
        ->and(\App\Services\Site\FormChrome::radioOptionClass(null, 'lead', null, 'panel-inverted', 'tiles'))->toBe(\App\Services\Site\FormChrome::LEAD_RADIO_TILE_DARK)
        ->and(\App\Services\Site\FormChrome::errorClass())->toBe('text-sm text-red-600')
        ->and(\App\Services\Site\FormChrome::errorClass('panel-inverted'))->toBe(\App\Services\Site\FormChrome::ERROR_DARK);
});

test('radio and submit styles', function () {
    expect(\App\Services\Site\FormChrome::radioOptionClass(null, 'lead', null, null, 'tiles'))->toBe(\App\Services\Site\FormChrome::LEAD_RADIO_TILE)
        ->and(\App\Services\Site\FormChrome::radioOptionClass(null, 'lead', null, null, 'segmented'))->toBe(\App\Services\Site\FormChrome::LEAD_RADIO_SEGMENT)
        ->and(\App\Services\Site\FormChrome::radioOptionClass(null, 'lead', 'soft-filled'))->toBe(\App\Services\Site\FormChrome::SOFT_RADIO_OPTION)
        ->and(\App\Services\Site\FormChrome::submitClass())->toBe(\App\Services\Site\FormChrome::SUBMIT_FULL)
        ->and(\App\Services\Site\FormChrome::submitClass('auto-arrow'))->toBe(\App\Services\Site\FormChrome::SUBMIT_AUTO_ARROW)
        ->and(\App\Services\Site\FormChrome::SUBMIT_AUTO_ARROW)->toContain('w-full md:w-auto');
});

test('auto submit is auto-width tracked uppercase without ml-auto', function () {
    expect(FormChrome::submitClass('auto'))->toBe(FormChrome::SUBMIT_AUTO)
        ->and(FormChrome::SUBMIT_AUTO)->toContain('uppercase tracking-[0.12em]')
        ->not->toContain('ml-auto');
});

/** Composite white at $alpha over an opaque 6-digit hex; returns the resulting hex. */
function formPackWhiteOver(string $hex, float $alpha): string
{
    $hex = ltrim($hex, '#');
    $out = '#';
    foreach ([0, 2, 4] as $i) {
        $c = hexdec(substr($hex, $i, 2));
        $out .= str_pad(dechex((int) round($alpha * 255 + (1 - $alpha) * $c)), 2, '0', STR_PAD_LEFT);
    }

    return $out;
}

test('dark-surface form chrome meets 4.5:1 for text and 3:1 for hairlines on every live theme', function (string $source, string $key) {
    // Spec §Accessibility: asserted on resolved token values with the contrast helper the ink ledger uses.
    // Light surfaces are skipped — polarity (isDarkSurface / text_on_* white) paints them with light chrome.
    $resolver = app(\App\Services\Site\ThemeResolver::class);
    if ($source === 'fixture') {
        $decoded = json_decode((string) file_get_contents(base_path('tests/fixtures/home-themes/demo-site-themes.json')), true);
        expect($decoded[$key] ?? null)->toBeArray();
        $tokens = $resolver->renderTokens($decoded[$key]);
    } else {
        $tokens = $resolver->renderTokens($resolver->baseTheme($key));
    }
    $darkSurfaces = 0;
    foreach (['band', 'primary'] as $surfaceKey) {
        $surface = $resolver->normaliseHex((string) $tokens[$surfaceKey]);
        expect($surface)->not->toBeNull();
        if (! $resolver->isDarkSurface($surface)) {
            continue;
        }
        $darkSurfaces++;
        // text-white (inputs, labels, placeholders, thank-you copy, inline-piped trust, tile descriptions) — local-friendly #15803d fails any alpha < 1.0
        // text-white/80 is the gray-600 swap on contact dark-surface (thank-you copy, intro); already ≥ 4.5:1 on every live palette.
        // text-white/60 is the gray-400 swap on contact-details eyebrow-scale labels only — not AA body, not asserted here.
        expect($resolver->contrastRatio(formPackWhiteOver($surface, 1.0), $surface))->toBeGreaterThanOrEqual(4.5, "{$key} {$surfaceKey} text @ 1.00");
        // border-white/70: boxed dark border and underline dark hairline (/60 is 2.82:1 on #15803d)
        expect($resolver->contrastRatio(formPackWhiteOver($surface, 0.70), $surface))->toBeGreaterThanOrEqual(3.0, "{$key} {$surfaceKey} hairline");
        // error + required-mark text-red-50 / #fef2f2 (flat hex — text-red-300 is 2.64:1 on #15803d)
        expect($resolver->contrastRatio('#fef2f2', $surface))->toBeGreaterThanOrEqual(4.5, "{$key} {$surfaceKey} error");
        expect($resolver->contrastRatio('#fef2f2', $surface))->toBeGreaterThanOrEqual(4.5, "{$key} {$surfaceKey} required-mark");
    }
    expect($darkSurfaces)->toBeGreaterThan(0);
})->with([
    '51-eden' => ['fixture', '51-eden'],
    '52-hunt' => ['fixture', '52-hunt'],
    '54-nh' => ['fixture', '54-nh'],
    'light-archetype' => ['fixture', 'light-archetype'],
    'trades-bold' => ['theme', 'trades-bold'],
    'professional-clean' => ['theme', 'professional-clean'],
    'local-friendly' => ['theme', 'local-friendly'],
]);
