<?php

return [
    /*
     * Per-section-type schema. The renderer uses this to:
     *   - emit data-editable markers in admin-edit mode
     *   - validate field updates
     *
     * Field types: 'plain' (single-line string), 'rich' (constrained TipTap JSON),
     * 'image' (PostMedia id + alt), 'url', 'ranges' (list of {start, length}
     * codepoint offsets into title), 'enum' (closed string list), 'integer' (min/max).
     *
     * For repeating items use 'items.*.title' notation.
     */
    'hero' => [
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain', 'max' => 120, 'required' => true],
            'subtitle' => ['type' => 'plain', 'max' => 240],
            'accent_word' => ['type' => 'plain', 'max' => 60],
            'accent_ranges' => ['type' => 'ranges'],
            'cta_label' => ['type' => 'plain', 'max' => 32],
            'cta_url' => ['type' => 'url'],
            'background_image' => ['type' => 'image'],
        ],
    ],
    'hero_compact' => [
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain', 'max' => 120, 'required' => true],
            'subtitle' => ['type' => 'plain', 'max' => 240],
            'accent_word' => ['type' => 'plain', 'max' => 60],
            'accent_ranges' => ['type' => 'ranges'],
        ],
    ],
    'about-text' => [
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain', 'max' => 120],
            'body' => ['type' => 'rich'],
        ],
    ],
    'intro' => [
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain', 'max' => 120],
            'body' => ['type' => 'rich'],
        ],
    ],
    'services' => [
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain', 'max' => 120],
            'accent_ranges' => ['type' => 'ranges'],
            'intro' => ['type' => 'rich'],
            'items.*.title' => ['type' => 'plain', 'max' => 80],
            'items.*.body' => ['type' => 'rich'],
            'items.*.icon' => ['type' => 'image'],
        ],
    ],
    'trust' => [
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain', 'max' => 120],
            'items.*.title' => ['type' => 'plain', 'max' => 60],
            'items.*.body' => ['type' => 'plain', 'max' => 240],
        ],
    ],
    'process' => [
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain'],
            'items.*.step' => ['type' => 'plain', 'max' => 16],
            'items.*.title' => ['type' => 'plain', 'max' => 80],
            'items.*.body' => ['type' => 'rich'],
        ],
    ],
    'faqs' => [
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain'],
            'items.*.question' => ['type' => 'plain'],
            'items.*.answer' => ['type' => 'rich'],
        ],
    ],
    'benefits' => [
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain'],
            'items.*.title' => ['type' => 'plain'],
            'items.*.body' => ['type' => 'plain'],
        ],
    ],
    'values' => [
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain'],
            'intro' => ['type' => 'plain'],
            'items.*.title' => ['type' => 'plain'],
            'items.*.body' => ['type' => 'plain'],
        ],
    ],
    'features' => [
        'label' => 'What\'s included',
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain', 'max' => 120],
            'accent_ranges' => ['type' => 'ranges'],
            'intro' => ['type' => 'plain', 'max' => 240],
            'items.*.title' => ['type' => 'plain', 'max' => 80],
            'items.*.body' => ['type' => 'plain', 'max' => 240],
            'items.*.icon' => ['type' => 'image'],
        ],
    ],
    'story' => [
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain'],
            'body' => ['type' => 'rich'],
        ],
    ],
    'details' => [
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain'],
            'items.*.label' => ['type' => 'plain'],
            'items.*.value' => ['type' => 'plain'],
        ],
    ],
    'cta' => [
        'fields' => [
            'title' => ['type' => 'plain'],
            'body' => ['type' => 'plain'],
            'button_label' => ['type' => 'plain', 'max' => 32],
            'button_url' => ['type' => 'url'],
        ],
    ],
    'contact_form' => [
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain'],
            'intro' => ['type' => 'rich'],
            'submit_label' => ['type' => 'plain', 'max' => 32],
        ],
    ],
    'lead_form' => [
        'label' => 'Home lead form',
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain', 'max' => 60],
            'intro' => ['type' => 'plain', 'max' => 200],
            'submit_label' => ['type' => 'plain', 'max' => 20],
            // `benefits` is a flat string array (3 items).
            // `extra_fields` is a nested array of field descriptor objects.
            // Neither fits the existing plain/rich/image types, so they are
            // handled directly by the lead-form-editor Livewire component
            // rather than the generic field-editing system.
            'benefits' => ['type' => 'array'],
            'extra_fields' => ['type' => 'nested_array'],
        ],
    ],
    'trust_strip' => [
        'label' => 'Trust strip',
        'fields' => [
            'sources' => ['type' => 'enum', 'values' => ['site', 'product', 'both']],
            'layout' => ['type' => 'enum', 'values' => ['strip', 'carousel']],
            'heading' => ['type' => 'plain', 'max' => 60],
            'reviews_label' => ['type' => 'plain', 'max' => 30],
            'min_reviews' => ['type' => 'integer', 'min' => 1, 'max' => 1000],
            'external.label' => ['type' => 'plain', 'max' => 30],
            'external.url' => ['type' => 'url'],
            'external.rating' => ['type' => 'decimal', 'min' => 0, 'max' => 5, 'precision' => 1],
            'external.count' => ['type' => 'integer', 'min' => 0],
        ],
    ],
    'suburb_list' => [
        'label' => 'Suburb list',
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain', 'max' => 120],
            'intro' => ['type' => 'plain', 'max' => 240],
        ],
    ],
    'phone_cta_strip' => [
        'label' => 'Phone CTA strip',
        'fields' => [
            'title' => ['type' => 'plain', 'max' => 60],
            'subtitle' => ['type' => 'plain', 'max' => 120],
        ],
    ],
    'opening_hours_strip' => [
        'label' => 'Opening hours strip',
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain', 'max' => 80],
        ],
    ],
    'who_we_help_strip' => [
        'label' => 'Who we help strip',
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain', 'max' => 80],
            'items.*.title' => ['type' => 'plain', 'max' => 40],
        ],
    ],
    'portfolio_strip' => [
        'label' => 'Portfolio strip',
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain', 'max' => 80],
            'intro' => ['type' => 'plain', 'max' => 200],
        ],
    ],
    'case_study_teaser' => [
        'label' => 'Case study teaser',
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain', 'max' => 140],
            'body' => ['type' => 'plain', 'max' => 600],
            'client' => ['type' => 'plain', 'max' => 80],
            'stat' => ['type' => 'plain', 'max' => 20],
            'stat_label' => ['type' => 'plain', 'max' => 60],
            'image_url' => ['type' => 'url'],
        ],
    ],
    'seo' => [
        'fields' => [
            'title' => ['type' => 'plain'],
            'body' => ['type' => 'rich'],
        ],
    ],
    'geo' => [
        'fields' => [
            'title' => ['type' => 'plain'],
            'body' => ['type' => 'rich'],
        ],
    ],
    'article-body' => [
        'label' => 'Article body',
        'fields' => [
            'body' => ['type' => 'rich', 'required' => true],
        ],
    ],
    'service_area_card' => [
        'label' => 'Service area card',
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain', 'max' => 40],
            'intro' => ['type' => 'plain', 'max' => 120],
            'areas' => ['type' => 'array'],
            'cta_label' => ['type' => 'plain', 'max' => 30],
            'cta_url' => ['type' => 'url'],
        ],
    ],
    'cta_band' => [
        'label' => 'CTA band',
        'fields' => [
            'title' => ['type' => 'plain', 'max' => 80],
            'subtitle' => ['type' => 'plain', 'max' => 160],
            'cta_label' => ['type' => 'plain', 'max' => 32],
            'cta_url' => ['type' => 'url'],
        ],
    ],
    'featured_products' => [
        'label' => 'Featured products',
        'page_types' => ['home'],
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain', 'max' => 80],
            'subtitle' => ['type' => 'plain', 'max' => 160],
            'source' => ['type' => 'product_block_source'],
            'count' => ['type' => 'integer', 'min' => 3, 'max' => 8],
            'limit' => ['type' => 'integer', 'min' => 4, 'max' => 12],
            'layout' => ['type' => 'enum', 'values' => ['grid', 'carousel']],
            'cta_label' => ['type' => 'plain', 'max' => 32],
            'cta_url' => ['type' => 'link', 'max' => 160],
        ],
    ],
    'promo_tiles' => [
        'label' => 'Promo tiles',
        'page_types' => ['home', 'about'],
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain', 'max' => 120],
            'tiles.*.heading' => ['type' => 'plain', 'max' => 60],
            'tiles.*.text' => ['type' => 'plain', 'max' => 160],
            'tiles.*.cta_label' => ['type' => 'plain', 'max' => 40],
            'tiles.*.cta_url' => ['type' => 'link'],
            'tiles.*.tone' => ['type' => 'plain'],
        ],
    ],
    'category_rail' => [
        'label' => 'Category rail',
        'page_types' => ['home'],
        'fields' => [
            'title' => ['type' => 'plain', 'max' => 80],
            'subtitle' => ['type' => 'plain', 'max' => 160],
            'slugs' => ['type' => 'array'],
            'limit' => ['type' => 'integer', 'min' => 3, 'max' => 12],
        ],
    ],
    'projects_hero' => [
        'label' => 'Projects hero',
        'page_types' => ['projects'],
        'render' => 'site.sections.projects_hero',
        'fields' => [
            'title' => ['type' => 'plain', 'max' => 80, 'required' => true],
            'accent_ranges' => ['type' => 'ranges'],
            'subtitle' => ['type' => 'plain', 'max' => 240],
            'hero_enabled' => ['type' => 'bool'],
        ],
    ],
    'project_gallery' => [
        'label' => 'Project gallery',
        'page_types' => ['projects'],
        'render' => 'site.sections.project_gallery',
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain', 'max' => 80],
        ],
    ],
    'case_study_highlights' => [
        'label' => 'Case study highlights',
        'page_types' => ['projects'],
        'render' => 'site.sections.case_study_highlights',
        'fields' => [
            'title' => ['type' => 'plain', 'max' => 80],
        ],
    ],
    'related_guides' => [
        'label' => 'Related guides',
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain', 'max' => 80],
        ],
    ],
    'related_services' => [
        'label' => 'Related services',
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain', 'max' => 80],
        ],
    ],
    'cost_disclaimer' => [
        'label' => 'Cost disclaimer',
        'fields' => [
            'body' => ['type' => 'plain'],
            'generated_at' => ['type' => 'plain'],
        ],
    ],
    'cost_table' => [
        'label' => 'Cost table',
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain', 'max' => 120],
            'rows.*.job' => ['type' => 'plain'],
        ],
    ],
    'gallery' => [
        'label' => 'Gallery',
        'fields' => [
            'title' => ['type' => 'plain', 'max' => 80],
        ],
    ],
    'project_detail_hero' => [
        'label' => 'Project detail hero',
        'fields' => [
            'title' => ['type' => 'plain', 'max' => 120, 'required' => true],
            'intro' => ['type' => 'plain', 'max' => 400],
        ],
    ],
    'project_meta_band' => [
        'label' => 'Project meta band',
        'fields' => [
            'project_type' => ['type' => 'plain', 'max' => 80],
            'areas_covered' => ['type' => 'plain', 'max' => 160],
            'location' => ['type' => 'plain', 'max' => 80],
        ],
    ],
    'project_about' => [
        'label' => 'About this project',
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain', 'max' => 120],
            'body' => ['type' => 'plain', 'max' => 2000],
            'project_type' => ['type' => 'plain', 'max' => 80],
            'location' => ['type' => 'plain', 'max' => 80],
            'image_id' => ['type' => 'image'],
        ],
    ],
    'project_photo_essay' => [
        'label' => 'Project photo essay',
        'fields' => [
            'title' => ['type' => 'plain', 'max' => 80],
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'intro' => ['type' => 'plain', 'max' => 600],
        ],
    ],
    'project_cta_row' => [
        'label' => 'Project CTA row',
        'fields' => [
            'title' => ['type' => 'plain', 'max' => 120],
            'body' => ['type' => 'plain', 'max' => 240],
            'cta_label' => ['type' => 'plain', 'max' => 32],
            'cta_url' => ['type' => 'url'],
        ],
    ],
    'similar_projects' => [
        'label' => 'Similar projects',
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain', 'max' => 80],
        ],
    ],

    'team' => [
        'label' => 'Team',
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain', 'max' => 120],
            'intro' => ['type' => 'plain', 'max' => 240],
            'members.*.name' => ['type' => 'plain', 'max' => 80],
            'members.*.role' => ['type' => 'plain', 'max' => 80],
            'members.*.bio' => ['type' => 'plain', 'max' => 240],
            'members.*.image_id' => ['type' => 'image'],
            'members.*.alternate_image_id' => ['type' => 'image'],
            'members.*.hover_image_id' => ['type' => 'image'],
            'items.*.name' => ['type' => 'plain', 'max' => 80],
            'items.*.role' => ['type' => 'plain', 'max' => 80],
            'items.*.bio' => ['type' => 'plain', 'max' => 240],
            'items.*.image' => ['type' => 'image'],
        ],
    ],
    'statistics' => [
        'label' => 'Statistics',
        'fields' => [
            'eyebrow' => ['type' => 'plain', 'max' => 60],
            'title' => ['type' => 'plain', 'max' => 120],
            'intro' => ['type' => 'plain', 'max' => 240],
            'items.*.value' => ['type' => 'plain', 'max' => 32],
            'items.*.label' => ['type' => 'plain', 'max' => 80],
            'items.*.description' => ['type' => 'plain', 'max' => 240],
            'items.*.prefix' => ['type' => 'plain', 'max' => 16],
            'items.*.suffix' => ['type' => 'plain', 'max' => 16],
        ],
    ],
];
