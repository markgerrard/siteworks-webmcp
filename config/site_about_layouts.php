<?php

/*
 * Per-preset about-page recipe, applied at render time by
 * PageRenderer::applyPageKindLayout(..., 'about'). 'classic' is the
 * identity preset — listed for the picker, never consulted by the
 * transform (early return). Variant names route to
 * resources/views/site/sections/variants/<family>/.
 * eyebrow_policy: 'all' keeps every eyebrow; 'first-only' suppresses
 * the eyebrow on every eyebrow_sections type after the first.
 */
return [
    'classic' => [
        'label' => 'Classic',
        'description' => 'The standard about page — split story with portrait image, numbered values circles.',
        'schema_version' => 1,
        'variants' => [],
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => ['story', 'values'],
        'assigner_hints' => ['wants_imagery' => false, 'minimum_features' => 0],
    ],
    'editorial' => [
        'label' => 'Editorial',
        'description' => 'Magazine longform — full-width display heading, flowing two-column prose, banner image; numbered ledger values.',
        'schema_version' => 1,
        'variants' => ['story' => 'editorial', 'values' => 'ledger'],
        'eyebrow_policy' => 'first-only',
        'eyebrow_sections' => ['story', 'values'],
        'assigner_hints' => ['wants_imagery' => false, 'minimum_features' => 3],
    ],
    'showcase' => [
        'label' => 'Showcase',
        'description' => 'Image-led — split story on the brand panel with the photo beside it; values as an elevated checklist band with imagery.',
        'schema_version' => 1,
        'variants' => ['story' => 'banner-overlap', 'values' => 'statements'],
        'eyebrow_policy' => 'first-only',
        'eyebrow_sections' => ['story', 'values'],
        'assigner_hints' => ['wants_imagery' => true, 'minimum_features' => 3],
    ],
    'precision' => [
        'label' => 'Precision',
        'description' => 'Spec-sheet — accent-rule story with a framed side image, values as a marker list beside imagery; dividers throughout.',
        'schema_version' => 1,
        'variants' => ['story' => 'document', 'values' => 'markers'],
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => ['story', 'values'],
        'assigner_hints' => ['wants_imagery' => false, 'minimum_features' => 3],
    ],
];
