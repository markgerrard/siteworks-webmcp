<?php

namespace App\Http\Controllers\Site;

use App\Exceptions\Site\PageStateException;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Site\SiteVersion;
use App\Services\Site\SitePublishService;
use Illuminate\Http\RedirectResponse;

class SiteVersionRollbackController extends Controller
{
    public function __construct(protected SitePublishService $publishService) {}

    public function __invoke(Site $site, SiteVersion $version): RedirectResponse
    {
        $this->authorize('update', $site);

        try {
            $this->publishService->rollbackToVersion($site, $version);
        } catch (PageStateException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Site rolled back to v{$version->version}.");
    }
}
