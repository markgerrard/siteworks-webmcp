<?php

namespace App\Http\Controllers\Site;

use App\Models\GeneratedPage;
use App\Models\Site;
use Illuminate\Http\Request;

/**
 * Same-origin wrapper around PageFieldUpdateController for public-host editing.
 *
 * Authentication is handled by EditSessionAuth middleware; the resolved site
 * is stored in the request attributes.
 */
class PublicEditFieldController
{
    public function __construct(protected PageFieldUpdateController $inner) {}

    public function __invoke(Request $request, int $page)
    {
        /** @var Site $site */
        $site = $request->attributes->get('edit_site');

        // Re-route to the inner controller, substituting IDs from the authenticated context.
        return $this->inner->__invoke($request, $site->id, $page);
    }
}
