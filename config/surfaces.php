<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Current Surface
    |--------------------------------------------------------------------------
    |
    | Drives surface-gated route loading in bootstrap/app.php. One of:
    |
    |   'all'         — load every route file (transition / single-container deploys)
    |   'agents'      — staff workspace only (agent subdomain)
    |   'customer'    — customer self-serve UI only (primary domain)
    |   'site-public' — published preview rendering, custom-domain serving
    |   'editor-preview' — sandboxed editor preview iframe origin
    |
    | The default is 'all' so existing single-container deploys keep working
    | unchanged. Production targets per-surface containers.
    |
    */

    'current' => env('SURFACE', 'all'),

    'valid' => ['all', 'agents', 'customer', 'site-public', 'editor-preview'],
];
