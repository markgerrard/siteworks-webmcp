<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Payload Guards
    |---------------------------------------------------------------------------
    |
    | Livewire v4 caps how much a single /livewire/update batch may carry. The
    | packaged default of 20 for max_components is too low for the agent site
    | editor, which throws TooManyComponentsException mid-edit:
    |
    |   - the site dashboard mounts every tab's components on load (tabs use
    |     Alpine x-show, not @if);
    |   - page-manager adds per-page children, and the projects/case-study
    |     editors mount one card per item, so the total scales with content.
    |
    | The limit guards against oversized payloads rather than component count per
    | se, so it is raised — not disabled — with headroom for content-heavy sites.
    |
    | Only the payload block is overridden here; every other Livewire setting
    | still comes from the package default via mergeConfigFrom. That merge is
    | shallow at the top level, so all four guards must be restated or the
    | omitted ones would fall back to null (disabled).
    |
    */

    'payload' => [
        'max_size' => 1024 * 1024,   // 1MB - maximum request payload size in bytes
        'max_nesting_depth' => 10,   // Maximum depth of dot-notation property paths
        'max_calls' => 50,           // Maximum method calls per request
        'max_components' => 100,     // Maximum components per batch request
    ],

    /*
    |---------------------------------------------------------------------------
    | Lazy-load Placeholder
    |---------------------------------------------------------------------------
    |
    | Skeleton shown while lazily-mounted components load (the site editor
    | tabs use lazy.bundle). Without this, Livewire renders a bare <div>
    | and the landing tab flashes blank. Per-component placeholder()
    | methods still take precedence.
    |
    */

    'component_placeholder' => 'livewire.placeholders.panel-skeleton',

    /*
    |---------------------------------------------------------------------------
    | Temporary File Uploads
    |---------------------------------------------------------------------------
    |
    | Pinned to the app host's local disk. The package default ("default")
    | follows FILESYSTEM_DISK, and when that is s3 Livewire has the browser
    | PUT the temporary file straight to the Spaces origin — which the
    | agents and client-portal CSP (connect-src 'self') block, so every
    | upload silently does nothing.
    | Local keeps uploads on the same-origin /livewire/upload-file route;
    | components then copy to the media disk themselves.
    |
    | The whole block is restated because mergeConfigFrom is shallow.
    |
    */

    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK', 'local'),
        'rules' => null,
        'directory' => null,
        'middleware' => 'throttle:60,1',
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],

];
