<?php

namespace App\Http\Controllers\Site;

use App\Models\Site;
use Illuminate\Http\Request;

/**
 * Same-origin publish endpoint for public-host editing.
 */
class PublicEditPublishController
{
    public function __construct(protected SitePublishController $inner) {}

    public function __invoke(Request $request)
    {
        /** @var Site $site */
        $site = $request->attributes->get('edit_site');

        return $this->inner->publish($request, $site->id);
    }
}
