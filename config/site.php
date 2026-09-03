<?php

return [
    'layout_body_style' => 'min-height: 100vh; display: flex; flex-direction: column;',

    /*
     * Number of recent page revisions to keep regardless of age.
     * Pruning keeps whichever set is more inclusive: this many recent rows
     * OR everything within the days threshold below.
     */
    'revision_keep_count' => env('SITE_REVISION_KEEP_COUNT', 50),

    /*
     * Days within which all revisions are kept regardless of count.
     */
    'revision_keep_days' => env('SITE_REVISION_KEEP_DAYS', 90),

    /*
     * Renderer selection flag. Until true, the public renderer continues to
     * read from Preview.snapshot via PreviewRenderer. When true, requests
     * resolve through site_versions_current → PageRenderer.
     */
    'use_versioned_renderer' => env('SITE_USE_VERSIONED_RENDERER', false),

    /*
     * Path prefix for public nav href generation. Default ''. ResolvePreviewHost
     * middleware expects single-segment paths ('/' or '/{page_type}'), so leave
     * this empty for host-routed sites.
     */
    'public_route_prefix' => env('SITE_PUBLIC_ROUTE_PREFIX', ''),

    /*
     * Feature flag: per-service-page galleries (service_gallery section).
     * Default off — with the flag off the section renders nothing and the
     * renderer skips its item preload, so existing sites are byte-identical.
     */
    'service_page_galleries_enabled' => filter_var(env('FEATURE_SERVICE_PAGE_GALLERIES', false), FILTER_VALIDATE_BOOLEAN),

    /*
     * Soft cap on items rendered per service_gallery section (~100 quoted
     * to clients; lazy-load makes the tail cheap but unbounded is not).
     */
    'service_page_gallery_cap' => env('SERVICE_PAGE_GALLERY_CAP', 100),

    /*
     * Feature flag: native on-site reviews (submission + display), gated
     * per site by sites.native_reviews_enabled on top of this master
     * switch. Default off.
     */
    'native_reviews_enabled' => filter_var(env('FEATURE_NATIVE_REVIEWS', false), FILTER_VALIDATE_BOOLEAN),

    // Sender address for quote-form enquiry notifications.
    'enquiry_from_address' => env('ENQUIRY_FROM_ADDRESS', 'website@siteworks.cloud'),

    /*
     * Public page-render HTML cache.
     *
     * When enabled, the fully rendered HTML for each public-mode page is
     * cached per (site_id, version_id, page_id, one_page?). Served straight
     * from cache on subsequent hits until SitePublishService publishes a
     * new version (which flushes the per-site cache tag).
     *
     * Only 'public' mode is cached — admin-edit and admin-preview always
     * bypass so editors see live content immediately.
     *
     * Disabled by default. Safe to flip per-environment via env.
     */
    'public_cache_enabled' => env('SITE_PUBLIC_CACHE_ENABLED', false),
    'public_cache_ttl' => (int) env('SITE_PUBLIC_CACHE_TTL', 3600),

    /* Managed-content carryover credits: off by default (quota is flat each month). */
    // Managed content ships dormant: the UI is hidden and enforced server-side.
    'managed_content_ui_enabled' => env('MANAGED_CONTENT_UI_ENABLED', false),

    'managed_content_carryover_enabled' => env('MANAGED_CONTENT_CARRYOVER_ENABLED', false),

    /*
     * Projects-page trust framing. When true, AI-generated project items render with
     * "Example Projects" vocabulary and an "Example" badge per tile; when false they
     * render with marketing vocabulary regardless of source. Per-site override:
     * sites.honest_project_framing.
     */
    'honest_project_framing' => env('HONEST_PROJECT_FRAMING', false),

    /*
     * Auto-include gate for Google Reviews sections.
     *
     * When true, ArchetypeComposer will inject reviews_summary on home and
     * reviews on about pages when the site has a trusted place ID and its
     * cached reviews meet the quality threshold (rating ≥ 4.0, count ≥ 3).
     * Flip to false per-environment to disable all automatic injection.
     */
    'reviews_auto_include_enabled' => env('REVIEWS_AUTO_INCLUDE_ENABLED', true),
];
