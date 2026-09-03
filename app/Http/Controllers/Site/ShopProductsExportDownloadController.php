<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\User;
use App\Services\Shop\ProductsExporter;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\AgentToolsGate;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\Operations\ExportProductsOperation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Target of the signed URL the `export_products` WebMCP operation mints
 * (App\Services\Site\Editor\Operations\ExportProductsOperation). The
 * `signed` route middleware proves the URL was minted by an authorised
 * call to that operation and has not expired or been tampered with — but
 * that is not the only wall: this action still runs the normal SitePolicy
 * `view` check against the acting (cookie-authenticated) user, exactly
 * like every other agents-surface route. A stolen or forwarded link with
 * a valid signature still 403s for anyone who isn't allowed to view this
 * site. A client download additionally re-checks the client-portal
 * channel gate so a URL minted while the flag was on cannot outlive
 * flag revocation.
 */
class ShopProductsExportDownloadController extends Controller
{
    public function __invoke(
        Request $request,
        Site $site,
        ProductsExporter $exporter,
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
                    $registry->get('export_products'),
                ),
                403,
            );
        }

        abort_unless($site->shopEnabled(), 404);

        // The bytes come ONLY from the frozen mint-time render, never from a
        // re-render here. The op hashed those exact bytes into
        // the envelope's sha256; re-rendering could diverge if the catalogue moved
        // inside the TTL, and a checksum mismatch must mean corruption, not a race.
        // The token is random + signature-bound, so it names one already-authorised
        // artefact and adds no path-traversal or enumeration surface.
        $token = $request->query('token');
        $content = is_string($token) && $token !== ''
            ? Cache::get(ExportProductsOperation::cacheKey($token))
            : null;

        // Use get (not pull) so a retried download within the TTL stays idempotent.
        // A null artefact means the frozen bytes expired/evicted — refuse with 409
        // export_stale so the client re-mints, rather than silently re-rendering.
        if (! is_string($content)) {
            return response('export_stale', 409, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        // Format only shapes the filename + mime for the response; the BYTES are
        // the frozen ones above and are not re-derived from it.
        $format = $this->paramIn($request, 'format', ProductsExporter::FORMATS, 'csv');
        $filename = $exporter->filename($site, $format);

        return response($content, 200, [
            'Content-Type' => $exporter->mime($format).'; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function paramIn(Request $request, string $key, array $allowed, string $default): string
    {
        $value = $request->query($key, $default);

        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }
}
