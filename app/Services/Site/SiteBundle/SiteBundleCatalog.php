<?php

namespace App\Services\Site\SiteBundle;

/**
 * Table list + FK-safety rules for a portable single-site export/import
 * bundle (see site:export-bundle / site:import-bundle).
 *
 * This is a sibling of App\Services\Site\SiteClone\SiteCloneCatalog, not a
 * reuse of it: SiteCloneCatalog remaps every id because it clones a site
 * into a *live, populated* database (id collisions are the norm). This
 * bundle instead targets an *empty* database, so primary keys travel
 * unchanged and no remapping is needed — only excluded-table FKs (users,
 * clients, telemetry, shop PII/Stripe) need nulling, and a
 * handful of forward references (a page pointing at a revision that hasn't
 * been inserted yet) need a null-then-backfill two-pass insert.
 *
 * Excludes everything SiteCloneCatalog::EXCLUDED_SITE_ID_TABLES excludes
 * (telemetry, PII, Stripe-linked shop tables, operational logs) — see that
 * class for the one-line reasons, which apply identically here.
 */
class SiteBundleCatalog
{
    /**
     * Tables to export/import, in FK-safe insert order.
     *
     * @var list<string>
     */
    public const TABLES = [
        'sites',
        'business_profiles',
        'site_media',
        'generated_pages',
        'generated_page_revisions',
        'logo_concepts',
        'layout_presets',
        'site_drafts',
        'site_versions',
        'site_versions_current',
        'previews',
        'shop_categories',
        'shop_snapshots',
        'shop_snapshot_current',
    ];

    /**
     * Tables scoped by a column other than the default `site_id`.
     *
     * `sites` is scoped by its own `id`. `generated_page_revisions` has no
     * site_id column at all — it's scoped via page_id after generated_pages
     * is resolved (see SiteBundleExportService::exportRows()).
     *
     * @var array<string, string>
     */
    public const SCOPE_COLUMN = [
        'sites' => 'id',
        'generated_page_revisions' => 'page_id',
    ];

    /**
     * Tables not scoped by site_id (handled via SCOPE_COLUMN + a page_id
     * lookup instead of a plain WHERE site_id = ?).
     *
     * @var list<string>
     */
    public const SCOPED_VIA_PARENT_ROWS = [
        'generated_page_revisions',
    ];

    /**
     * Primary key column per table, for deterministic export ordering.
     * Defaults to 'id' when a table isn't listed here.
     *
     * @var array<string, string>
     */
    public const PRIMARY_KEY = [
        'site_versions_current' => 'site_id',
        'shop_snapshot_current' => 'site_id',
    ];

    /**
     * Only the single latest row (by published_at, falling back to id) is
     * exported for these tables.
     *
     * @var list<string>
     */
    public const LATEST_ONLY = [
        'previews',
    ];

    /**
     * Columns nulled on import and never restored — they reference tables
     * this bundle deliberately excludes (users, clients, and telemetry).
     * See SiteCloneCatalog::EXCLUDED_SITE_ID_TABLES
     * for the reasoning; this is the column-level equivalent for tables we
     * DO carry, whose id-columns point at ones we don't.
     *
     * @var array<string, list<string>>
     */
    public const ALWAYS_NULL_ON_IMPORT = [
        'sites' => [
            'client_id', 'created_by_user_id', 'assigned_to_user_id',
            'custom_domain', 'custom_domain_status', 'custom_domain_cf_id', 'custom_domain_cf_zone',
        ],
        'generated_page_revisions' => ['created_by_user_id'],
        'site_versions' => ['published_by_user_id'],
        'site_drafts' => ['updated_by_user_id'],
    ];

    /**
     * Columns nulled on the first insert pass and restored by a targeted
     * UPDATE once every table in the bundle has been inserted — these are
     * forward references into tables that insert later than their
     * referrer (or self-references where a child can precede its parent
     * in the bundle's row order).
     *
     * @var array<string, list<string>>
     */
    public const FORWARD_REF_BACKFILL = [
        'sites' => ['overlay_logo_concept_id'],
        'generated_pages' => ['parent_id', 'draft_revision_id', 'published_revision_id'],
        'generated_page_revisions' => ['parent_revision_id'],
        'shop_categories' => ['parent_id'],
    ];

    /**
     * [table, column, disk-resolver] triples of columns that may hold a
     * single media file reference (a full URL or a bare disk-relative
     * key). disk-resolver is either a literal disk name or 'media' to mean
     * App\Support\MediaStorage::diskName().
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    public const FILE_COLUMNS = [
        ['logo_concepts', 'path', 's3'],
        ['site_media', 's3_key', 'media'],
        ['site_media', 'url', 'media'],
        ['sites', 'brand_favicon_url', 'media'],
        ['sites', 'brand_og_url', 'media'],
        ['sites', 'brand_og_square_url', 'media'],
        ['sites', 'brand_og_custom_path', 'media'],
        ['sites', 'home_hero_video_path', 'media'],
        ['sites', 'home_hero_video_poster_path', 'media'],
        ['shop_categories', 'hero_image_url', 'media'],
        ['shop_snapshots', 'hero_image_url', 'media'],
    ];

    /**
     * [table, jsonColumn, disk-resolver] triples of JSON/jsonb columns
     * whose decoded values are deep-scanned for embedded media
     * URLs/keys (hero images and similar assets referenced from page or
     * shop-snapshot content rather than a dedicated column).
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    public const CONTENT_SCAN_COLUMNS = [
        ['generated_page_revisions', 'content_data', 'media'],
        ['shop_snapshots', 'json', 'media'],
    ];
}
