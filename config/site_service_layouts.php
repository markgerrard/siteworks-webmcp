<?php

/*
 * Per-preset service-page recipe, applied at render time by
 * PageRenderer::applyServicesLayout. 'classic' is the identity preset —
 * listed for the picker, never consulted by the transform (early return).
 * Variant names route to resources/views/site/sections/variants/<family>/.
 * eyebrow_policy: 'all' keeps every eyebrow; 'first-only' suppresses the
 * eyebrow on every stamped section after the first that carries one.
 */
return [
    'classic' => [
        'label' => 'Classic',
        'description' => 'The standard service page — split intro with portrait image, icon card grid.',
        'schema_version' => 1,
        'variants' => [],
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => ['intro', 'features'],
        'assigner_hints' => ['wants_imagery' => false, 'minimum_features' => 0],
    ],
    'editorial' => [
        'label' => 'Editorial',
        'description' => 'Magazine longform — full-width heading, two-column prose, banner image, numbered rows.',
        'schema_version' => 1,
        'variants' => ['intro' => 'editorial', 'features' => 'numbered', 'lead_form' => 'inline-editorial'],
        'options' => [
            'form_input_style' => 'underline',
            'form_surface' => 'panel-inverted',
            'form_trust_style' => 'inline-piped',
            'form_submit_style' => 'auto-arrow',
        ],
        'eyebrow_policy' => 'first-only',
        'eyebrow_sections' => ['intro', 'features'],
        'assigner_hints' => ['wants_imagery' => false, 'minimum_features' => 3],
    ],
    'showcase' => [
        'label' => 'Showcase',
        'description' => 'Image-led — full-bleed split intro on a brand panel, checklist band beside photography.',
        'schema_version' => 1,
        'variants' => ['intro' => 'split', 'features' => 'checklist', 'lead_form' => 'centered'],
        'options' => [
            'form_input_style' => 'boxed',
            'form_surface' => 'flat-cream',
            'form_trust_style' => 'chips-under-button',
        ],
        'eyebrow_policy' => 'first-only',
        'eyebrow_sections' => ['intro', 'features'],
        'assigner_hints' => ['wants_imagery' => true, 'minimum_features' => 3],
    ],
    'precision' => [
        'label' => 'Precision',
        'description' => 'Spec-sheet — utilitarian intro with an optional framed image, dense two-column scope listing.',
        'schema_version' => 1,
        'variants' => ['intro' => 'spec', 'features' => 'markers', 'lead_form' => 'phone-ledger'],
        'options' => [
            'form_input_style' => 'boxed',
            'form_surface' => 'card-on-dark',
            'form_trust_style' => 'tick-list',
        ],
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => ['intro', 'features'],
        'assigner_hints' => ['wants_imagery' => false, 'minimum_features' => 3],
    ],
];
