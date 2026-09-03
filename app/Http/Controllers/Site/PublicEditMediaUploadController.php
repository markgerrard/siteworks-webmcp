<?php

namespace App\Http\Controllers\Site;

use App\Models\Site;
use Illuminate\Http\Request;

/**
 * Same-origin media upload endpoint for public-host editing.
 */
class PublicEditMediaUploadController
{
    public function __construct(protected SiteMediaUploadController $inner) {}

    public function __invoke(Request $request)
    {
        /** @var Site $site */
        $site = $request->attributes->get('edit_site');

        return $this->inner->__invoke($request, $site->id);
    }
}
