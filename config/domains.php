<?php

return [
    'primary_domain' => env('APP_PRIMARY_DOMAIN', 'app.domain.com'),
    'agent_domain' => env('APP_AGENT_DOMAIN', 'agents.domain.com'),
    'customer_domain' => env('APP_CUSTOMER_DOMAIN', 'app.domain.com'),
    'editor_preview_domain' => env('APP_EDITOR_PREVIEW_DOMAIN', 'editor-preview.domain.com'),

    // Comma-separated emails granted the super-admin tier (empty = nobody).
    'super_admin_allowlist' => env('SUPER_ADMIN_ALLOWLIST', ''),
];
