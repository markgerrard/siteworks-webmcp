<?php

/*
 * Per-preset home-page recipe, applied at render time by
 * PageRenderer::applyHomeLayout via PageLayoutRegistry. Each entry defines:
 *   - variants: section type => variant name stamped onto the section
 *     (only when the section doesn't already carry an explicit variant)
 *   - insert_sections: section types spliced into the home page when absent
 *
 * 'classic' is the identity preset — listed for the picker, never consulted
 * by the transform (early return). Label/description copy is the former
 * home-layout enum's label()/description() strings, verbatim.
 */

return [
    'classic' => [
        'label' => 'Classic',
        'description' => 'The standard layout — centred hero, icon service cards, reviews carousel.',
        'schema_version' => 1,
        'variants' => [],
        'insert_sections' => [],
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => [],
    ],
    'editorial' => [
        'label' => 'Editorial',
        'description' => 'Featured ledger services on a contrast band, unnumbered brand-manifesto trust, ghost-numeral process stepper.',
        'schema_version' => 1,
        'variants' => [
            'hero' => 'panel-left',
            'services' => 'featured-ledger',
            'trust' => 'brand-manifesto',
            'process' => 'stepper',
            'lead_form' => 'inline-editorial',
        ],
        'surfaces' => [
            'services' => 'contrast',
            'trust' => 'brand',
        ],
        'options' => [
            'featured_count' => 4,
            'form_input_style' => 'underline',
            'form_surface' => 'panel-inverted',
            'form_trust_style' => 'inline-piped',
            'form_submit_style' => 'auto-arrow',
        ],
        'insert_sections' => [],
        'eyebrow_policy' => 'first-only',
        'eyebrow_sections' => ['services', 'trust', 'process'],
    ],
    'showcase' => [
        'label' => 'Showcase',
        'description' => 'Photo-led layout — boxed hero panel, photo service cards, a featured-projects band, and a bold accent CTA.',
        'schema_version' => 1,
        'variants' => [
            'hero' => 'boxed-left',
            'services' => 'photo-cards',
            'reviews_summary' => 'grid',
            'portfolio_strip' => 'dark-band',
            'lead_form' => 'centered',
            // cta deliberately NOT stamped: the dark-surface + accent-button
            // default reads premium; the solid accent-band wall crushed
            // photography-led pages. Tenants that want
            // the loud version set variant: 'accent-band' on the section.
        ],
        'options' => [
            'form_input_style' => 'boxed',
            'form_surface' => 'flat-cream',
            'form_trust_style' => 'chips-under-button',
        ],
        'insert_sections' => ['portfolio_strip'],
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => [],
    ],
    'precision' => [
        'label' => 'Precision',
        'description' => 'Marker listing — panel-left hero, two-column marker services, trust and process, with a contrast process band.',
        'schema_version' => 1,
        'variants' => [
            'hero' => 'panel-left',
            'services' => 'marker-columns',
            'trust' => 'marker-columns',
            'process' => 'marker-columns',
            'lead_form' => 'phone-ledger',
        ],
        'surfaces' => [
            'process' => 'contrast',
        ],
        'options' => [
            'form_input_style' => 'boxed',
            'form_surface' => 'card-on-dark',
            'form_trust_style' => 'tick-list',
        ],
        'insert_sections' => [],
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => ['services', 'trust', 'process'],
    ],

    // Deliberately stamps NO hero: sites keep their native scene/persisted hero.
    'banded' => [
        'label' => 'Banded',
        'description' => 'Full-bleed split bands — alternating photo and brand-panel services, banded checklist trust with image pane, checklist steps.',
        'schema_version' => 1,
        'variants' => [
            'services' => 'split-bands',
            'trust' => 'checklist-band',
            'process' => 'checklist-steps',
            'lead_form' => 'centered',
        ],
        'options' => [
            'form_input_style' => 'boxed',
            'form_surface' => 'flat-cream',
            'form_trust_style' => 'chips-under-button',
        ],
        'insert_sections' => [],
        'eyebrow_policy' => 'all',
    ],
];
