<?php

/*
 * Per-preset header chrome recipe, resolved by ChromeKnobs::recipe() via
 * PageLayoutRegistry kind `chrome`. 'classic' is the identity preset —
 * the current header exactly. Bespoke site-scoped recipes (layout_presets
 * rows with page_kind=chrome) win over this file and are promoted here
 * with `site:layout-promote --kind=chrome`.
 */
return [
    'classic' => [
        'label' => 'Classic',
        'description' => 'Left-aligned logo, inline nav, optional top bar — the current header.',
        'schema_version' => 1,
        'layout' => 'standard',
        'top_bar' => 'auto',
        'nav_row' => 'inline',
        'nav_case' => 'default',
        'logo_height' => 'md',
        'store_controls' => 'icons',
        'sticky_shrink' => 'on',
    ],
    'centred-badge' => [
        'label' => 'Centred badge',
        'description' => 'Badge logo centred, nav beneath; store search left, account/bag right; only the nav floats.',
        'schema_version' => 1,
        'layout' => 'centred',
        'top_bar' => 'off',
        'nav_row' => 'beneath',
        'nav_case' => 'caps',
        'logo_height' => 'xl',
        'store_controls' => 'icons+labels',
        'sticky_shrink' => 'on',
        'brand_pattern' => 'swirl',
    ],
];
