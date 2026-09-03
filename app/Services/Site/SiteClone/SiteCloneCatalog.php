<?php

namespace App\Services\Site\SiteClone;

class SiteCloneCatalog
{
    /**
     * Tables to copy, in dependency order.
     *
     * generated_page_revisions has no site_id — it is scoped via page_id
     * after generated_pages is copied.
     *
     * @var list<string>
     */
    public const CHILD_TABLES = [
        'business_profiles',
        'site_media',
        'generated_pages',
        'generated_page_revisions',
        'projects_page_drafts',
        'hero_versions',
        'hero_video_versions',
        'project_categories',
        'project_items',
        'before_after_pairs',
        'imported_media',
        'logo_concepts',
        'site_personalisation_faces',
        'site_drafts',
        'site_versions',
        'site_versions_current',
        'previews',
        'site_reviews',
        // page_kind travels with the row; unique is (site_id, page_kind, key).
        'layout_presets',
    ];

    /**
     * CHILD_TABLES entries that are not site_id-scoped.
     *
     * @var list<string>
     */
    public const NON_SITE_ID_CHILD_TABLES = [
        'generated_page_revisions',
    ];

    /**
     * site_id tables that must not be cloned. Values are one-line reasons.
     *
     * @var array<string, string>
     */
    public const EXCLUDED_SITE_ID_TABLES = [
        'external_api_calls' => 'telemetry',
        'site_enquiries' => 'lead inbox — operational data that belongs to the source site, not the clone',
        'site_subscriptions' => 'managed-content billing state; destination subscribes explicitly',
        'editor_operation_log' => 'editor audit trail — operational, never site content',
        'editor_agent_approvals' => 'one-use agent approval tokens — ephemeral, bound to actor + site + op; never cloned',
        'site_draft_asset_selections' => 'agent draft hero/logo selections reference source-site version ids; the destination starts with no pending selections',
        'shop_categories' => 'shop catalogue is env-local; child tables (variants, images) are product_id-keyed and would not follow a flat site_id clone',
        'shop_products' => 'products carry Stripe product/price IDs — cloning would point the clone at the ORIGINAL Stripe objects',
        'shop_drafts' => 'shop catalogue revision counter is env-local; the destination starts with no agent catalogue writes',
        'shop_featured_products' => 'references env-local shop_products rows',
        'shop_shipping_rates' => 'references env-local shop config; set up per destination',
        'shop_hero_versions' => 'references env-local shop assets',
        'shop_snapshots' => 'derived publish snapshot with denormalised env-local product IDs; republish regenerates',
        'shop_snapshot_current' => 'pointer into env-local shop_snapshots',
        'shop_carts' => 'live customer cart state — operational data',
        'shop_orders' => 'customer orders — PII and payment records must never clone across sites',
        'shop_orders_numbering' => 'per-site order sequence; restarts at the destination',
        'shop_customers' => 'customer PII — must never clone across sites',
        'shop_customer_addresses' => 'customer address book (T3) — PII, follows shop_customers',
    ];

    /**
     * Path columns that store a Spaces key or a full URL containing the
     * source prefix and/or /{src_id}/.
     *
     * @var array<string, list<string>>
     */
    public const PATH_REWRITES = [
        'logo_concepts' => ['path'],
        'hero_versions' => ['url', 'watermark_url'],
        'site_media' => ['s3_key', 'url'],
        'sites' => ['brand_favicon_url', 'brand_og_url', 'brand_og_square_url', 'brand_og_custom_path', 'home_hero_video_path', 'home_hero_video_poster_path'],
        'hero_video_versions' => ['s3_key'],
        'site_personalisation_faces' => ['path'],
        'imported_media' => ['url'],
    ];
}
