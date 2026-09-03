<?php

namespace App\Services\Site\Editor\Operations;

use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\Shop\ShopEntityResolver;
use App\Services\Site\Editor\Shop\ShopReadOperation;
use App\Services\Site\Editor\Shop\SkillCurrentState;

final class SkillAddProductWithImageryOperation extends ShopReadOperation
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
        return 'skill_add_product_with_imagery';
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
        return 'Protocol for adding a single product with images.';
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
            '1. Call `get_site_context` and `list_products` (avoid near-duplicates — surface and ask).',
            '2. Call `draft_product` (lands as draft). Clarify-don\'t-guess on price and options.',
            '3. Images: `upload_image` is the ONLY upload path; it returns a `media_id`.',
            '4. Call `set_product_image` with that `media_id` — never a path or URL.',
            '5. Corrections via `update_draft_product` carrying `product_revision` (from `get_product`).',
            '6. Call `get_product` to verify the result; report warnings; merchant publishes in the UI. Do not attempt to publish — no publish tool exists.',
        ]);
    }
}
