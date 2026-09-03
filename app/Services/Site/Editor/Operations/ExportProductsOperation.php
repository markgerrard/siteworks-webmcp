<?php

namespace App\Services\Site\Editor\Operations;

use App\Services\Shop\ProductsExporter;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationFailed;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\Shop\ShopEntityResolver;
use App\Services\Site\Editor\Shop\ShopReadOperation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Read-only sandbox op: mints a short-lived SIGNED download URL for the
 * current catalogue rather than streaming it through the tool response.
 * Exports the CURRENT catalogue exactly as-is — no draft changes, no
 * publish, no snapshot rebuild. The download route
 * (sites.shop.products.export-download) re-authorises the caller against
 * SitePolicy when the URL is hit; the signature is not the only wall.
 */
final class ExportProductsOperation extends ShopReadOperation
{
    private const TTL_MINUTES = 5;

    /**
     * Cache key for the frozen export bytes. Shared with the download
     * controller so both sides agree on where the mint-time render lives.
     */
    public static function cacheKey(string $token): string
    {
        return "shop-export:{$token}";
    }

    public function __construct(
        ShopEntityResolver $resolver,
        EditorStateFactory $states,
        private readonly ProductsExporter $exporter,
    ) {
        parent::__construct($resolver, $states);
    }

    public function name(): string
    {
        return 'export_products';
    }

    /**
     * Staff-only for now (agents WebMCP only) — narrower than the
     * ['staff', 'client'] ShopReadOperation grants list_products/get_product
     * etc. Client portal exposure is a later decision; the actual wall for
     * that is CommerceOperations::SANDBOX (this op is deliberately absent
     * from it), and this override keeps the role check consistent with it.
     *
     * @return list<string>
     */
    public function allowedRoles(): ?array
    {
        // Read-only, tenant-scoped (SitePolicy view + signed-URL re-check), so a
        // client may export their own catalogue — same shape as list/get_product.
        return ['staff', 'client'];
    }

    public function sideEffects(): string
    {
        return 'Exports the CURRENT merchant catalogue as a downloadable csv/md/json file via a short-lived signed URL ('
            .self::TTL_MINUTES.'-minute expiry). Read-only: makes no draft changes, does not publish, and does not '
            .'rebuild the snapshot. The tool response never carries the catalogue itself, only a download_url.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'format' => ['type' => 'string', 'enum' => ProductsExporter::FORMATS],
                'status' => ['type' => 'string', 'enum' => ProductsExporter::STATUSES],
                'category_slug' => ['type' => ['string', 'null']],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function handleShopRead(EditorContext $ctx, array $input): OperationResult
    {
        $state = $this->commerceState($ctx->site);

        $format = $input['format'] ?? 'csv';
        if (! is_string($format) || ! in_array($format, ProductsExporter::FORMATS, true)) {
            throw new OperationFailed(OperationResult::fail(
                'validation',
                'format must be csv, md, or json.',
                $state,
                ['fields' => ['format' => ['must be one of: '.implode(', ', ProductsExporter::FORMATS)]]],
            ));
        }

        $status = $input['status'] ?? 'any';
        if (! is_string($status) || ! in_array($status, ProductsExporter::STATUSES, true)) {
            throw new OperationFailed(OperationResult::fail(
                'validation',
                'status must be any, published, draft, or archived.',
                $state,
                ['fields' => ['status' => ['must be one of: '.implode(', ', ProductsExporter::STATUSES)]]],
            ));
        }

        $categorySlug = null;
        if (array_key_exists('category_slug', $input) && $input['category_slug'] !== null) {
            if (! is_string($input['category_slug']) || $input['category_slug'] === '') {
                throw new OperationFailed(OperationResult::fail(
                    'validation',
                    'category_slug must be a non-empty string.',
                    $state,
                    ['fields' => ['category_slug' => ['must be a non-empty string']]],
                ));
            }

            // Resolves or throws not_found — same contract as list_products'
            // category_slug filter, so an unknown slug reads the same way
            // across both read operations.
            $this->resolver->category($ctx->site, $input['category_slug']);
            $categorySlug = $input['category_slug'];
        }

        // Render once here to report exact bytes + a sha256 integrity hash in the
        // envelope, and FREEZE those exact bytes for the download to serve.
        // The download must NOT re-render: if the catalogue
        // changed inside the TTL window a re-render would diverge from the minted
        // sha256, and a checksum mismatch must mean corruption, never an expected
        // race. product_count is products, not variant rows.
        $products = $this->exporter->collect($ctx->site, $status, $categorySlug);
        $content = $this->exporter->render($products, $format);
        $count = $products->count();
        $expiresAt = now()->addMinutes(self::TTL_MINUTES);

        // Freeze the rendered bytes under a random opaque token with the SAME TTL
        // as the signed URL. The token is random (not guessable/enumerable) and
        // travels inside the signature-protected URL, so it adds no path-traversal
        // or enumeration surface — it only names one already-authorised artefact.
        $token = Str::random(40);
        Cache::put(self::cacheKey($token), $content, $expiresAt);

        $routeParams = ['site' => $ctx->site->id, 'format' => $format, 'status' => $status, 'token' => $token];
        if ($categorySlug !== null) {
            $routeParams['category_slug'] = $categorySlug;
        }

        // A client's signed URL must land on the client-portal (customer) origin —
        // the agents route sits behind staff CF Access and would bounce them to the
        // staff login. Both routes are domain-scoped, so the name selects the host.
        $downloadRoute = $ctx->actor->isClientUser()
            ? 'client.portal.shop.products.export-download'
            : 'sites.shop.products.export-download';

        $downloadUrl = URL::temporarySignedRoute(
            $downloadRoute,
            $expiresAt,
            $routeParams,
        );

        return OperationResult::ok([
            'download_url' => $downloadUrl,
            'filename' => $this->exporter->filename($ctx->site, $format),
            'mime' => $this->exporter->mime($format),
            'bytes' => strlen($content),
            'sha256' => hash('sha256', $content),
            'product_count' => $count,
            'expires_at' => $expiresAt->toIso8601String(),
            'requires_current_session' => true,
        ], $state);
    }
}
