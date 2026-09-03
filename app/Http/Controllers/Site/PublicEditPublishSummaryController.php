<?php

namespace App\Http\Controllers\Site;

use App\Models\Site;
use Illuminate\Http\Request;

/**
 * Same-origin publish-summary endpoint for public-host editing.
 */
class PublicEditPublishSummaryController
{
    public function __construct(protected SitePublishController $inner) {}

    public function __invoke(Request $request)
    {
        /** @var Site $site */
        $site = $request->attributes->get('edit_site');

        return $this->inner->summary($request, $site->id);
    }
}
