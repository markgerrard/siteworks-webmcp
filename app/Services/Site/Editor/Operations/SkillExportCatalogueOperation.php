<?php

namespace App\Services\Site\Editor\Operations;

use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\Shop\ShopEntityResolver;
use App\Services\Site\Editor\Shop\ShopReadOperation;
use App\Services\Site\Editor\Shop\SkillCurrentState;

final class SkillExportCatalogueOperation extends ShopReadOperation
{
    public function __construct(
        ShopEntityResolver $resolver,
        EditorStateFactory $states,
        private readonly SkillCurrentState $currentState,
    ) {
        parent::__construct($resolver, $states);
    }

    public function name(): string
    {
        return 'skill_export_catalogue';
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
        return 'Protocol for exporting this store\'s catalogue and brand for use elsewhere.';
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
    protected function handleShopRead(EditorContext $ctx, array $input): OperationResult
    {
        return OperationResult::ok([
            'current_state' => $this->currentState->prefix($ctx->site),
            'protocol' => $this->protocol(),
        ], $this->commerceState($ctx->site));
    }

    private function protocol(): string
    {
        return implode("\n", [
            '1. Call `get_site_context` for identity/capabilities; `get_brand_system` when the destination needs brand (palette, fonts, voice); `get_logo_assets` for logo files (signed variant downloads; may report `has_logo:false`).',
            '2. Call `export_products` choosing csv/md/json per destination. The envelope\'s bytes are FROZEN with a `sha256` — download via the signed URL and verify the hash before presenting; note `requires_current_session`.',
            '3. Present what was exported and the verification result. Do not attempt to publish; publishing is the merchant\'s action in the UI (no publish tool exists).',
        ]);
    }
}
