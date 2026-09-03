<?php

/*
 * Per-preset project_detail recipe, applied at render time by
 * PageRenderer::applyPageKindLayout(..., 'project_detail'). 'classic' is
 * the identity preset — listed for the picker; resolveKey() returns null
 * so the transform is a no-op. Variant names route to
 * resources/views/site/sections/variants/<family>/.
 *
 * There is no sites.project_detail_layout column (COLUMN_MAP absent).
 * Recipe keys stay classic unless a page-level layout_preset_key is set.
 * Lead forms are intentionally absent: site_enquiries.page_type is
 * varchar(40) and nested paths would truncate attribution.
 */
return [
    'classic' => [
        'label' => 'Classic',
        'description' => 'Project detail — caps breadcrumb, title/intro split, meta rows, photo essay, conversational CTA, similar projects.',
        'schema_version' => 1,
        'variants' => [
            'project_detail_hero' => 'classic',
            'project_meta_band' => 'classic',
            'project_about' => 'classic',
            'project_photo_essay' => 'classic',
            'project_cta_row' => 'classic',
            'similar_projects' => 'classic',
        ],
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => [
            'project_detail_hero',
            'project_meta_band',
            'project_photo_essay',
            'project_cta_row',
            'similar_projects',
        ],
        'assigner_hints' => ['wants_imagery' => true, 'minimum_features' => 0],
    ],
    'editorial' => [
        'label' => 'Editorial',
        'description' => 'Project detail following the Editorial projects personality.',
        'schema_version' => 1,
        'variants' => [
            'project_detail_hero' => 'classic',
            'project_meta_band' => 'classic',
            'project_about' => 'editorial',
            'project_photo_essay' => 'editorial',
            'project_cta_row' => 'classic',
            'similar_projects' => 'classic',
        ],
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => [],
    ],
    'showcase' => [
        'label' => 'Showcase',
        'description' => 'Project detail following the Showcase projects personality.',
        'schema_version' => 1,
        'variants' => [
            'project_detail_hero' => 'classic',
            'project_meta_band' => 'classic',
            'project_about' => 'showcase',
            'project_photo_essay' => 'showcase',
            'project_cta_row' => 'classic',
            'similar_projects' => 'classic',
        ],
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => [],
    ],
    'precision' => [
        'label' => 'Precision',
        'description' => 'Project detail following the Precision projects personality.',
        'schema_version' => 1,
        'variants' => [
            'project_detail_hero' => 'classic',
            'project_meta_band' => 'classic',
            'project_about' => 'split',
            'project_photo_essay' => 'classic',
            'project_cta_row' => 'classic',
            'similar_projects' => 'classic',
        ],
        'options' => ['detail_heading' => 'ruled'],
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => [],
    ],
    'banded' => [
        'label' => 'Banded',
        'description' => 'Project detail following the Banded projects personality.',
        'schema_version' => 1,
        'variants' => [
            'project_detail_hero' => 'classic',
            'project_meta_band' => 'classic',
            'project_about' => 'banded',
            'project_photo_essay' => 'banded',
            'project_cta_row' => 'classic',
            'similar_projects' => 'classic',
        ],
        'eyebrow_policy' => 'all',
        'eyebrow_sections' => [],
    ],
];
