<?php

return [

    /*
     * When true, a null sites.texture_key is resolved from business context
     * or a seeded pick so neighbouring sites already differ. When false,
     * null keys resolve to `plus` — the pre-system motif — so shipping this
     * feature never restyles existing sites until opted in via
     * TEXTURE_AUTO_DEFAULTS.
     */
    'auto' => filter_var(env('TEXTURE_AUTO_DEFAULTS', true), FILTER_VALIDATE_BOOLEAN),

];
