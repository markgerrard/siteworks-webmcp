<?php

/*
 * Per-archetype home-page recipe. Each entry defines:
 *   - sections: ordered list of section types that appear on the home page
 *   - weights: per-section-type parameters that modify prompt generation or
 *     rendering (e.g. emergency_variant, max_cards, cta_type)
 *
 * Keys MUST match the string values of App\Enums\Archetype.
 * ArchetypeRecipe::for falls back to 'local_service' on unknown keys.
 *
 * Section placement conventions:
 *   - service_area_card: after 'services', before 'trust' (local-trust signal)
 *   - process: after 'trust', before 'lead_form' (conversion flow)
 *   - lead_form: penultimate before 'cta' for trade/service archetypes
 */

return [
    'emergency_trade' => [
        // Emergency trades: speed + trust front-loaded; no service_area_card
        // (suburb_list already handles geo coverage); process block suits the
        // "how it works" conversion pattern.
        'sections' => ['hero', 'phone_cta_strip', 'services', 'trust', 'process', 'suburb_list', 'cta'],
        'weights' => [
            'hero' => ['emergency_variant' => true],
            'services' => ['highlight_emergency' => true],
            'cta' => ['cta_type' => 'phone'],
        ],
    ],
    'traditional_craftsman' => [
        // Craft trades: portfolio is the hero proof; no process block (the
        // craft itself IS the process); no service_area_card (bespoke work
        // is usually local but brochure-leaning).
        'sections' => ['hero', 'portfolio_strip', 'about', 'services', 'trust', 'cta'],
        'weights' => [
            'services' => ['max_cards' => 4],
        ],
    ],
    'premium_specialist' => [
        // High-ticket specialists: credentials and social proof before
        // process; no service_area_card (typically regional/national).
        'sections' => ['hero', 'trust', 'services', 'process', 'case_study_teaser', 'cta'],
        'weights' => [
            'cta' => ['cta_type' => 'consultation'],
            'trust' => ['emphasise' => 'credentials'],
        ],
    ],
    'local_service' => [
        // Local service trades: geo coverage card after services; process
        // block as the conversion pre-sell before the lead form.
        'sections' => ['hero', 'services', 'service_area_card', 'trust', 'process', 'lead_form', 'cta'],
        'weights' => [],
    ],
    'retail_venue' => [
        // Location-led venues: opening hours is a top-of-funnel trust
        // signal; no service_area_card (address/map already handles geo).
        'sections' => ['hero', 'opening_hours_strip', 'services', 'trust', 'cta'],
        'weights' => [],
    ],
    'professional_service' => [
        // Credentials-led: who-we-help signals fit over service_area_card;
        // no process block (consultation booking is the CTA).
        'sections' => ['hero', 'who_we_help_strip', 'services', 'trust', 'about', 'cta'],
        'weights' => [
            'cta' => ['cta_type' => 'book_call'],
            'trust' => ['emphasise' => 'credentials'],
        ],
    ],
    'saas_platform' => [
        // SaaS / software product: feature-proof and process ("how it works")
        // are the primary conversion levers; no geo sections (national/global
        // product), no phone strip (async demo beats a cold call), no
        // portfolio (screenshots live in feature cards instead).
        'sections' => ['hero', 'features', 'services', 'process', 'trust', 'lead_form', 'cta'],
        'weights' => [
            'cta' => ['cta_type' => 'demo'],
            'trust' => ['emphasise' => 'social_proof'],
        ],
    ],
];
