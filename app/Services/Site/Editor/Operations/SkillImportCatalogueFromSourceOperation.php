<?php

namespace App\Services\Site\Editor\Operations;

use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\Shop\ShopEntityResolver;
use App\Services\Site\Editor\Shop\ShopReadOperation;
use App\Services\Site\Editor\Shop\SkillCurrentState;

final class SkillImportCatalogueFromSourceOperation extends ShopReadOperation
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
        return 'skill_import_catalogue_from_source';
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
        return 'Protocol for turning a merchant\'s flyer, price list or document into draft products.';
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
            '1. Call `get_site_context` (identity, capabilities, currency) and `get_brand_system` (voice, conventions) before touching the catalogue.',
            '2. Call `describe_import_products` for the exact field spec — do not guess fields.',
            '3. Extract products from the source. Preserve merchant naming VERBATIM (do not "correct" spelling). Normalise prices to the store currency; flag any price whose currency or format was ambiguous.',
            '4. Call `list_products`; match extracted items against the existing catalogue by name similarity. For probable matches do NOT create a new product — surface the match and ask the merchant. `import_products` also reports an exact name match as `matched` and leaves that product alone.',
            '5. Categories must EXIST before import (`import_products` does not auto-create): create missing ones with `manage_category` first.',
            '6. Items with missing or "ask"-style prices: still draft them, and list every such item in your report to the merchant as needing a price. NEVER invent a price.',
            '7. Variable sizing/options ("ask for sizes"): ask the merchant before drafting variants; if proceeding, draft a single "from" price and flag it in your report.',
            '8. `import_products` with `dry_run` first; review the preview; then commit with `catalogue_revision` (alias: `expected_revision`) + a fresh `idempotency_key`.',
            '9. Report what was drafted, every warning, and what needs the merchant. Do not attempt to publish; publishing is the merchant\'s action in the UI (no publish tool exists and import rejects `published` status).',
        ]);
    }
}
