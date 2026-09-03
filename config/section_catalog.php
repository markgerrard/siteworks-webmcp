<?php

/*
 * Section types use their site_sections page_types declaration when present.
 * Types without one fall back to ['*'], making them available on every page type.
 */

return [
    'hero' => [
        'page_types' => ['*'],
        'singleton' => true,
        'max' => 1,
        'defaults' => ['title' => 'Welcome', 'subtitle' => '', 'cta_label' => 'Get in touch'],
        'initial_fields' => ['eyebrow', 'title', 'subtitle', 'cta_label', 'cta_url', 'background_image'],
    ],
    'hero_compact' => [
        'page_types' => ['*'],
        'singleton' => true,
        'max' => 1,
        'defaults' => ['title' => 'Welcome'],
        'initial_fields' => ['eyebrow', 'title', 'subtitle', 'accent_word'],
    ],
    'about-text' => [
        'page_types' => ['*'],
        'initial_fields' => ['eyebrow', 'title', 'body'],
    ],
    'intro' => [
        'page_types' => ['*'],
        'initial_fields' => ['eyebrow', 'title', 'body'],
    ],
    'services' => [
        'page_types' => ['*'],
        'defaults' => ['items' => []],
        'initial_fields' => ['eyebrow', 'title', 'intro'],
    ],
    'trust' => [
        'page_types' => ['*'],
        'defaults' => ['items' => []],
        'initial_fields' => ['eyebrow', 'title'],
    ],
    'process' => [
        'page_types' => ['*'],
        'defaults' => ['items' => []],
        'initial_fields' => ['eyebrow', 'title'],
    ],
    'faqs' => [
        'page_types' => ['*'],
        'defaults' => ['items' => []],
        'initial_fields' => ['eyebrow', 'title'],
    ],
    'benefits' => [
        'page_types' => ['*'],
        'defaults' => ['items' => []],
        'initial_fields' => ['eyebrow', 'title'],
    ],
    'values' => [
        'page_types' => ['*'],
        'defaults' => ['items' => []],
        'initial_fields' => ['eyebrow', 'title', 'intro'],
    ],
    'features' => [
        'page_types' => ['*'],
        'defaults' => ['items' => []],
        'initial_fields' => ['eyebrow', 'title', 'intro'],
    ],
    'story' => [
        'page_types' => ['*'],
        'initial_fields' => ['eyebrow', 'title', 'body'],
    ],
    'details' => [
        'page_types' => ['*'],
        'defaults' => ['items' => []],
        'initial_fields' => ['eyebrow', 'title'],
    ],
    'cta' => [
        'page_types' => ['*'],
        'initial_fields' => ['title', 'body', 'button_label', 'button_url'],
    ],
    'contact_form' => [
        'page_types' => ['*'],
        'singleton' => true,
        'max' => 1,
        'initial_fields' => ['eyebrow', 'title', 'intro', 'submit_label'],
    ],
    'lead_form' => [
        'injected_only' => true,
    ],
    'trust_strip' => [
        'page_types' => ['*'],
        'defaults' => [
            'sources' => 'both',
            'layout' => 'strip',
            'heading' => 'What customers say',
            'reviews_label' => 'reviews',
            'min_reviews' => 3,
        ],
        'initial_fields' => [
            'sources', 'layout', 'heading', 'reviews_label', 'min_reviews',
            'external.label', 'external.url', 'external.rating', 'external.count',
        ],
    ],
    'suburb_list' => [
        'page_types' => ['*'],
        'initial_fields' => ['eyebrow', 'title', 'intro'],
    ],
    'phone_cta_strip' => [
        'page_types' => ['*'],
        'initial_fields' => ['title', 'subtitle'],
    ],
    'opening_hours_strip' => [
        'page_types' => ['*'],
        'initial_fields' => ['eyebrow', 'title'],
    ],
    'who_we_help_strip' => [
        'page_types' => ['*'],
        'defaults' => ['items' => []],
        'initial_fields' => ['eyebrow', 'title'],
    ],
    'portfolio_strip' => [
        'page_types' => ['*'],
        'defaults' => ['item_ids' => []],
        'initial_fields' => ['eyebrow', 'title', 'intro'],
        'references' => ['item_ids' => 'project_items'],
    ],
    'case_study_teaser' => [
        'page_types' => ['*'],
        'initial_fields' => ['eyebrow', 'title', 'body', 'client', 'stat', 'stat_label', 'image_url'],
    ],
    'seo' => [
        'page_types' => ['*'],
        'singleton' => true,
        'max' => 1,
        'initial_fields' => ['title', 'body'],
    ],
    'geo' => [
        'page_types' => ['*'],
        'singleton' => true,
        'max' => 1,
        'initial_fields' => ['title', 'body'],
    ],
    'article-body' => [
        'page_types' => ['*'],
        'defaults' => ['body' => ['type' => 'doc', 'content' => []]],
        'initial_fields' => ['body'],
    ],
    'service_area_card' => [
        'page_types' => ['*'],
        'initial_fields' => ['eyebrow', 'title', 'intro', 'cta_label', 'cta_url'],
    ],
    'cta_band' => [
        'page_types' => ['*'],
        'singleton' => true,
        'max' => 1,
        'initial_fields' => ['title', 'subtitle', 'cta_label', 'cta_url'],
    ],
    'projects_hero' => [
        'page_types' => ['projects'],
        'singleton' => true,
        'max' => 1,
        'defaults' => ['title' => 'Projects'],
        'initial_fields' => ['title', 'subtitle'],
    ],
    'project_gallery' => [
        'page_types' => ['projects'],
        'singleton' => true,
        'max' => 1,
        'defaults' => ['eyebrow' => 'Recent work', 'title' => 'Our projects', 'item_ids' => []],
        'initial_fields' => ['eyebrow', 'title'],
        'references' => ['item_ids' => 'project_items'],
    ],
    'case_study_highlights' => [
        'page_types' => ['projects'],
        'defaults' => ['item_ids' => []],
        'initial_fields' => ['title'],
        'references' => ['item_ids' => 'project_items'],
    ],
    'related_guides' => [
        'page_types' => ['*'],
        'initial_fields' => ['eyebrow', 'title'],
    ],
    'related_services' => [
        'page_types' => ['*'],
        'initial_fields' => ['eyebrow', 'title'],
    ],
    'cost_disclaimer' => [
        'page_types' => ['*'],
        'initial_fields' => ['body', 'generated_at'],
    ],
    'cost_table' => [
        'page_types' => ['*'],
        'defaults' => ['rows' => []],
        'initial_fields' => ['eyebrow', 'title'],
    ],
    'gallery' => [
        'page_types' => ['*'],
        'initial_fields' => ['title'],
    ],
    'project_detail_hero' => [
        'page_types' => ['*'],
        'singleton' => true,
        'max' => 1,
        'defaults' => ['title' => 'Project'],
        'initial_fields' => ['title', 'intro'],
    ],
    'project_meta_band' => [
        'page_types' => ['*'],
        'initial_fields' => ['project_type', 'areas_covered', 'location'],
    ],
    'project_about' => [
        'page_types' => ['*'],
        'initial_fields' => ['eyebrow', 'title', 'body', 'project_type', 'location', 'image_id'],
        'references' => ['image_id' => 'site_media'],
    ],
    'project_photo_essay' => [
        'page_types' => ['*'],
        'defaults' => ['item_ids' => []],
        'initial_fields' => ['title', 'eyebrow', 'intro'],
        'references' => ['item_ids' => 'project_items'],
    ],
    'project_cta_row' => [
        'page_types' => ['*'],
        'initial_fields' => ['title', 'body', 'cta_label', 'cta_url'],
    ],
    'similar_projects' => [
        'page_types' => ['*'],
        'defaults' => ['item_ids' => []],
        'initial_fields' => ['eyebrow', 'title'],
        'references' => ['item_ids' => 'project_items'],
    ],
    'team' => [
        'page_types' => ['*'],
        'defaults' => ['members' => [], 'items' => []],
        'initial_fields' => ['eyebrow', 'title', 'intro'],
        'references' => [
            'members.*.image_id' => 'site_media',
            'members.*.alternate_image_id' => 'site_media',
            'members.*.hover_image_id' => 'site_media',
        ],
    ],
    'statistics' => [
        'page_types' => ['*'],
        'defaults' => ['items' => []],
        'initial_fields' => ['eyebrow', 'title', 'intro'],
    ],
    'featured_products' => [
        'page_types' => ['home'],
        'singleton' => true,
        'max' => 1,
        'defaults' => [
            'title' => 'Featured products',
            'subtitle' => '',
            'source' => 'featured',
            'count' => 4,
            'limit' => 4,
            'layout' => 'grid',
            'cta_label' => 'Browse the shop',
            'cta_url' => '/shop',
        ],
        'initial_fields' => ['eyebrow', 'title', 'subtitle', 'source', 'count', 'limit', 'layout', 'cta_label', 'cta_url'],
    ],
    'promo_tiles' => [
        'page_types' => ['home', 'about'],
        'max' => 2,
        'defaults' => ['eyebrow' => '', 'title' => '', 'tiles' => []],
        'initial_fields' => ['eyebrow', 'title'],
    ],
    'category_rail' => [
        'page_types' => ['home'],
        'singleton' => true,
        'max' => 1,
        'defaults' => [
            'title' => 'Shop by occasion',
            'subtitle' => '',
            'slugs' => [],
            'limit' => 8,
        ],
        'initial_fields' => ['title', 'subtitle', 'slugs', 'limit'],
    ],
];
