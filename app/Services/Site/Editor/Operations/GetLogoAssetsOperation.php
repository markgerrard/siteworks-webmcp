<?php

namespace App\Services\Site\Editor\Operations;

use App\Enums\LogoAssetVariant;
use App\Models\LogoConcept;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\LogoAssetCatalog;
use Illuminate\Support\Facades\URL;

final class GetLogoAssetsOperation extends BaseOperation
{
    public function __construct(
        private readonly EditorStateFactory $states,
        private readonly LogoAssetCatalog $catalog,
    ) {}

    public function name(): string
    {
        return 'get_logo_assets';
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function wrapInAdminChange(): bool
    {
        return false;
    }

    public function address(): string
    {
        return 'site';
    }

    /**
     * @return list<string>
     */
    public function allowedRoles(): ?array
    {
        return ['staff', 'client'];
    }

    public function sideEffects(): string
    {
        return 'Reads the site\'s selected logo and any stored variants, minting short-lived signed download URLs. Makes no draft changes.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $expiresAt = now()->addMinutes(LogoAssetCatalog::TTL_MINUTES);
        $downloadRoute = $ctx->actor->isClientUser()
            ? 'client.portal.logo.download'
            : 'sites.logo.download';

        $active = $this->catalog->active($ctx->site);
        $activePayload = $active !== null
            ? $this->envelope($active, LogoAssetVariant::Selected, $downloadRoute, $ctx->site->id, $expiresAt)
            : null;

        $variants = [];
        foreach ($this->catalog->variants($ctx->site) as $entry) {
            $payload = $this->envelope($entry['concept'], $entry['variant'], $downloadRoute, $ctx->site->id, $expiresAt);
            if ($payload === null) {
                continue;
            }
            $payload['role'] = $entry['role'];
            $variants[] = $payload;
        }

        return OperationResult::ok([
            'has_logo' => $activePayload !== null || $variants !== [],
            'active' => $activePayload,
            'variants' => $variants,
        ], $this->states->for($ctx->site, null));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function envelope(
        LogoConcept $concept,
        LogoAssetVariant $variant,
        string $downloadRoute,
        int $siteId,
        \DateTimeInterface $expiresAt,
    ): ?array {
        $bytes = $this->catalog->bytes($concept);
        if ($bytes === null) {
            return null;
        }

        $dimensions = $this->catalog->dimensions($bytes);
        $payload = [
            'download_url' => URL::temporarySignedRoute(
                $downloadRoute,
                $expiresAt,
                ['site' => $siteId, 'variant' => $variant->value],
            ),
            'filename' => $this->catalog->filename($concept),
            'mime' => $this->catalog->mime($concept),
            'bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'expires_at' => $expiresAt->toIso8601String(),
            'requires_current_session' => true,
        ];

        if ($dimensions['width'] !== null) {
            $payload['width'] = $dimensions['width'];
        }
        if ($dimensions['height'] !== null) {
            $payload['height'] = $dimensions['height'];
        }

        return $payload;
    }
}
