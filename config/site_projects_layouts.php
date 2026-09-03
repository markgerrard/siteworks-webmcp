<?php

/*
 * Per-preset projects-page recipe, applied at render time by
 * PageRenderer::applyPageKindLayout(..., 'projects'). 'classic' is the
 * identity preset — listed for the picker, never consulted by the
 * transform (early return). Variant names route to
 * resources/views/site/sections/variants/<family>/.
 * eyebrow_policy: 'all' keeps every eyebrow; 'first-only' suppresses
 * the eyebrow on every eyebrow_sections type after the first.
 *
 * Recipe KEY resolution: PageLayoutRegistry::resolveProjectsRecipeKey()
 * reads sites.services_layout. The projects page follows the SERVICES
 * personality (no dedicated recipe column, no new knob).
 * sites.projects_layout stays reserved for the CaseStudies (tile grid
 * vs long-form) swap and is NOT read by this recipe machinery.
 */
return [
    'classic' => [
        'label' => 'Classic',
        'description' => 'The standard projects page — tile gallery with a bare heading.',
        'schema_version' => 1,
        'variants' => [],
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => ['project_gallery'],
        'assigner_hints' => ['wants_imagery' => false, 'minimum_features' => 0],
    ],
    'editorial' => [
        'label' => 'Editorial',
        'description' => 'Magazine longform personality — tile gallery with a plain eyebrow heading.',
        'schema_version' => 1,
        'variants' => ['project_gallery' => 'classic'],
        'options' => ['link_detail_pages' => true],
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => ['project_gallery'],
        'assigner_hints' => ['wants_imagery' => false, 'minimum_features' => 3],
    ],
    'showcase' => [
        'label' => 'Showcase',
        'description' => 'Image-led personality — tile gallery with a plain eyebrow heading.',
        'schema_version' => 1,
        'variants' => ['project_gallery' => 'classic'],
        'options' => ['link_detail_pages' => true],
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => ['project_gallery'],
        'assigner_hints' => ['wants_imagery' => true, 'minimum_features' => 3],
    ],
    'precision' => [
        'label' => 'Precision',
        'description' => 'Spec-sheet personality — tile gallery with a ruled eyebrow heading.',
        'schema_version' => 1,
        'variants' => ['project_gallery' => 'classic'],
        'options' => ['gallery_heading' => 'ruled', 'link_detail_pages' => true],
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => ['project_gallery'],
        'assigner_hints' => ['wants_imagery' => false, 'minimum_features' => 3],
    ],
    'banded' => [
        'label' => 'Banded',
        'description' => 'Banded personality — tile gallery with a plain eyebrow heading.',
        'schema_version' => 1,
        'variants' => ['project_gallery' => 'classic'],
        'options' => ['link_detail_pages' => true],
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => ['project_gallery'],
        'assigner_hints' => ['wants_imagery' => true, 'minimum_features' => 3],
    ],
];
