<?php

return [
    'enabled' => (bool) env('DEMO_MODE', false),
    'site_host' => env('DEMO_SITE_HOST', 'localhost'),
    // Shared secret for GET /demo/reset?token=… on the portal host (between-takes reset). Empty = the route is a 404.
    'reset_token' => (string) env('DEMO_RESET_TOKEN', ''),
    'user_email' => env('DEMO_USER_EMAIL', 'demo@camino.example'),
    'user_password' => env('DEMO_USER_PASSWORD', 'webmcp-demo'),
    /*
     * Operations that must not register in demo mode even if the classes
     * exist. T0 deleted the four AI/video operations; this list is the
     * config-driven hide so a re-import cannot advertise them.
     */
    'hidden_operations' => [
        'generate_image',
        'generate_logo_concepts',
        'regenerate_hero',
        'manage_video',
    ],
    /*
     * Extra editor operations a demo portal client may run on top of
     * CommerceOperations::SANDBOX. Production (DEMO_MODE=false) is unchanged.
     */
    'editor_client_operations' => [
        'get_brand_context',
        'get_page_structure',
        'inspect_draft',
        'edit_field',
        'add_section',
        'set_variant',
    ],
];
