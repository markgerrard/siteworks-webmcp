<?php

return [
    /*
     * The HUMAN Front-1 operations layer — the editor UI's own routes and the delegated legacy writes.
     * Independent of agent access on purpose: a single flag meant turning agent tools
     * off also killed the human editor, and turning the human layer on forced agent access on with it, so
     * a staged rollout (humans first, agents later) was impossible. These two now move separately.
     */
    'operations' => [
        'enabled' => (bool) env('EDITOR_OPERATIONS', false),
    ],

    /*
     * The AGENT fronts — Front 2 (WebMCP in the browser shell) and Front 3 (the MCP HTTP server).
     * Gated additionally by `roles` below; the human layer is never role-gated.
     */
    'agent_tools' => [
        'enabled' => (bool) env('EDITOR_AGENT_TOOLS', false),
        'roles' => array_values(array_filter(explode(',', (string) env('EDITOR_AGENT_TOOLS_ROLES', 'staff')))),
        /*
         * Client-portal WebMCP channel (surface=portal-shop). Independent of
         * EDITOR_AGENT_TOOLS so the client front can stay off at deploy while
         * staff shop-admin tools stay on. Default FALSE — must be flipped
         * after a security review.
         */
        'client_portal_enabled' => (bool) env('EDITOR_AGENT_TOOLS_CLIENT_PORTAL', false),
    ],
    'agent_approval' => [
        'enabled' => (bool) env('EDITOR_AGENT_APPROVAL', false),
        'ttl_minutes' => (int) env('EDITOR_AGENT_APPROVAL_TTL_MINUTES', 5),
        'grant_ttl_minutes' => (int) env('EDITOR_AGENT_APPROVAL_GRANT_TTL_MINUTES', 60),
        'pending_limit' => (int) env('EDITOR_AGENT_APPROVAL_PENDING_LIMIT', 25),
        'denied_cooldown_minutes' => (int) env('EDITOR_AGENT_APPROVAL_DENIED_COOLDOWN_MINUTES', 30),
    ],
    /*
     * Exposure sets (spec § 8, ruling R1): what a tenant's agents may REACH is a narrower question
     * than what the branch BUILDS. The sandbox set is v5's list verbatim — the shipped 18-operation
     * surface plus B1, B2, B4, C1, C2, A1 (with D1 behind it through delegation) and A2. Everything
     * else (the paid video and capture operations, B6, C3, future named atomic ops) exists on the
     * branch and is reachable on internal/dev tenants only. Enforced at EXECUTION for agent channels
     * (EditorOperations::run) — registered ≠ reachable was the hole R1 was ruled to close.
     *
     * FAIL CLOSED: an unlisted site — including every site created tomorrow — gets the NARROWEST
     * set. Defaulting to internal would make every new tenant fail OPEN to the paid operations; the
     * classification that widens is the one that must be affirmative. ToolExposure refuses to boot on
     * an unknown set name or an unparseable site list, so a mangled env cannot silently widen.
     */
    'exposure' => [
        'sets' => [
            'sandbox' => [
                'add_section', 'edit_field',
                'get_brand_context', 'get_brand_system', 'get_effective_hero_state', 'get_job_status', 'get_logo_assets', 'get_page_structure', 'get_site_context',
                'inspect_draft', 'list_image_versions', 'move_section', 'publish_summary',
                'remove_section', 'restore_image_version', 'restore_media_version',
                'seed_product_reviews', 'select_logo', 'set_fulfilment', 'set_hero_copy_style', 'set_logo_media', 'set_nav_container', 'set_shop_index_blocks', 'set_title_emphasis', 'set_variant',
                'undo_revision', 'update_brand_theme', 'update_form', 'upload_image',
                'list_products', 'get_product', 'draft_product', 'update_draft_product', 'set_product_image', 'manage_category', 'draft_category_content',
                'list_media', 'assign_media',
                // export_products: staff-agent WebMCP only. Deliberately NOT added
                // to CommerceOperations::SANDBOX — that const is the client-portal allowlist;
                // client export exposure is a later decision. This entry only
                // makes the op reachable for staff on the sandbox tenant set (ToolExposure), same as every
                // other commerce read op above — the op's own allowedRoles(): ['staff'] plus the missing
                // SANDBOX-const entry are what keep a client denied regardless of this list.
                'export_products',
                'describe_import_products',
                'import_products',
                'skill_import_catalogue_from_source',
                'skill_add_product_with_imagery',
                'skill_export_catalogue',
            ],
            'commerce' => [
                'list_products', 'get_product', 'draft_product', 'update_draft_product',
                'set_product_image', 'manage_category', 'draft_category_content', 'upload_image',
            ],
            /*
             * Page-level advertisement set for every site-scoped portal / agents
             * page that is not a shop write surface. Fed through ShopAgentToolsSeed
             * + AgentToolsGate — not a tenant classification (nameFor stays
             * sandbox/internal). Subset of SANDBOX: no specialist editor mutations,
             * no draft_product / manage_category commerce writes.
             */
            'portal_base' => [
                // Reads/handoff only, plus upload_image (media-library-scoped).
                // import_products is DELIBERATELY absent: a client-executable
                // write must not be advertised on pages that render
                // public-submitted text (enquiries, reviews, orders).
                // It stays on the shop page sets.
                'get_site_context', 'get_brand_system', 'get_logo_assets',
                'export_products', 'list_products', 'get_product',
                'upload_image',
                // Export protocol only: shop-write skills stay on the shop page
                // sets with the tools they name (import/draft/manage_category).
                'skill_export_catalogue',
            ],
            'internal' => [
                'add_section', 'edit_field',
                'get_brand_context', 'get_draft_diff', 'get_effective_hero_state', 'get_job_status',
                'get_page_structure', 'get_video_state', 'inspect_draft', 'list_image_versions',
                'move_section', 'publish_summary', 'remove_section',
                'restore_image_version', 'restore_media_version',                 'seed_product_reviews', 'select_logo', 'set_fulfilment', 'set_hero_copy_style', 'set_logo_media',
                'set_nav_container', 'set_nav_label', 'set_section_style', 'set_shop_index_blocks', 'set_theme_tokens', 'save_theme_token_preset', 'apply_theme_token_preset', 'list_theme_token_presets', 'set_title_emphasis', 'set_variant',
                'draft_product', 'get_product', 'list_products', 'manage_category', 'set_product_image', 'update_draft_product', 'draft_category_content',
                'undo_revision', 'update_asset_metadata', 'update_brand_theme', 'update_form',
                'update_page_settings', 'upload_image', 'validate_draft',
                'list_media', 'assign_media',
                'describe_import_products', 'import_products',
                'skill_import_catalogue_from_source', 'skill_add_product_with_imagery', 'skill_export_catalogue',
                // portal_base coverage on internal-listed tenants (review): without these the base seed collapses to almost nothing on
                // exactly the demo sites that are internal-listed.
                'get_site_context', 'get_brand_system', 'get_logo_assets', 'export_products',
            ],
        ],
        'default' => 'sandbox',
        'internal_sites' => env('EDITOR_TOOL_INTERNAL_SITES', ''),
    ],

    'operation_log_retention_days' => (int) env('EDITOR_OPERATION_LOG_RETENTION_DAYS', 90),
];
