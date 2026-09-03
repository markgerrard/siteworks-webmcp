<?php

namespace App\Http\Controllers\Site;

use App\Enums\LogoAssetVariant;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\AgentToolsGate;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\LogoAssetCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Target of the signed URL `get_logo_assets` mints. `signed` proves the URL
 * was minted by that operation and has not expired or been tampered with;
 * SitePolicy `view` still runs against the authenticated user. The `{variant}`
 * parameter is a fixed LogoAssetVariant value — never a storage path.
 * A client download additionally re-checks the client-portal channel gate
 * so a URL minted while the flag was on cannot outlive flag revocation.
 */
class LogoAssetDownloadController extends Controller
{
    public function __invoke(
        Request $request,
        Site $site,
        string $variant,
        LogoAssetCatalog $catalog,
        AgentToolsGate $gate,
        OperationRegistry $registry,
    ): Response {
        $this->authorize('view', $site);

        $user = $request->user();
        if ($user instanceof User && $user->isClientUser()) {
            abort_unless(
                $gate->enabledForUserAndOperation(
                    $user,
                    ActorChannel::Webmcp,
                    $registry->get('get_logo_assets'),
                ),
                403,
            );
        }

        $key = LogoAssetVariant::tryFrom($variant);
        abort_unless($key instanceof LogoAssetVariant, 404);

        $concept = $catalog->resolve($site, $key);
        abort_unless($concept !== null, 404);

        $bytes = $catalog->bytes($concept);
        abort_unless(is_string($bytes), 404);

        $filename = $catalog->filename($concept);

        return response($bytes, 200, [
            'Content-Type' => $catalog->mime($concept),
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
