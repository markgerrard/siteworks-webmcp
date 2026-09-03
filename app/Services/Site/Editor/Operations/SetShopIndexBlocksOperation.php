<?php

namespace App\Services\Site\Editor\Operations;

use App\Services\Shop\ShopIndexBlockSettings;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Support\Shop\ShopIndexBlocks;
use InvalidArgumentException;

final class SetShopIndexBlocksOperation extends BaseOperation
{
    public function __construct(
        private readonly EditorStateFactory $states,
        private readonly ShopIndexBlockSettings $settings,
    ) {}

    public function name(): string
    {
        return 'set_shop_index_blocks';
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function wrapInAdminChange(): bool
    {
        return false;
    }

    public function address(): string
    {
        return 'site';
    }

    public function sideEffects(): string
    {
        return 'Writes the live sites.shop_index_blocks list of product rows and trust strips under a blocks_revision compare-and-swap. Does not publish a draft.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['blocks', 'blocks_revision', 'composition_revision'],
            'properties' => [
                'blocks' => [
                    'type' => 'array',
                    'maxItems' => ShopIndexBlocks::MAX,
                    'items' => [
                        'type' => 'object',
                        'required' => ['heading'],
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => ['featured_products', 'trust_strip']],
                            'source' => ['type' => 'string'],
                            'limit' => ['type' => 'integer'],
                            'sources' => ['type' => 'string', 'enum' => ['site', 'product', 'both']],
                            'layout' => ['type' => 'string', 'enum' => ['grid', 'strip', 'carousel']],
                            'heading' => ['type' => 'string', 'maxLength' => 80],
                            'reviews_label' => ['type' => 'string', 'maxLength' => 30],
                            'min_reviews' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000],
                            'external' => [
                                'type' => ['object', 'null'],
                                'properties' => [
                                    'label' => ['type' => 'string', 'maxLength' => 30],
                                    'url' => ['type' => 'string', 'format' => 'uri'],
                                    'rating' => ['type' => 'number', 'minimum' => 0, 'maximum' => 5, 'multipleOf' => 0.1],
                                    'count' => ['type' => 'integer', 'minimum' => 0],
                                ],
                            ],
                        ],
                    ],
                ],
                'blocks_revision' => [
                    'type' => 'string',
                    'description' => "Current revision token. Provoke a stale_revision error and retry with its error payload's blocks_revision value.",
                ],
                'composition_revision' => ['type' => 'integer'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $state = $this->states->for($ctx->site, null);
        $blocks = $input['blocks'] ?? null;
        if (! is_array($blocks)) {
            return OperationResult::fail('validation', 'blocks must be a list.', $state, [
                'fields' => ['blocks' => ['must be a list']],
            ]);
        }

        $expectedRevision = $input['blocks_revision'] ?? null;
        if (! is_string($expectedRevision) || $expectedRevision === '') {
            return OperationResult::fail('validation', 'blocks_revision is required.', $state, [
                'fields' => ['blocks_revision' => ['required string']],
            ]);
        }

        $previous = $ctx->site->shop_index_blocks;
        try {
            $parsed = $this->settings->save($ctx->site, $blocks, $expectedRevision);
        } catch (InvalidArgumentException $exception) {
            $fresh = $ctx->site->fresh();
            $current = ShopIndexBlockSettings::revision($fresh);
            if (! hash_equals($current, $expectedRevision)) {
                return OperationResult::fail(
                    'stale_revision',
                    $exception->getMessage(),
                    $state,
                    [
                        'blocks_revision' => $current,
                        'blocks' => ShopIndexBlocks::normalize($fresh->shop_index_blocks),
                    ],
                );
            }

            return OperationResult::fail('validation', $exception->getMessage(), $state, [
                'fields' => ['blocks' => [$exception->getMessage()]],
            ]);
        }

        $ctx->changes->record(
            'site',
            'sites.shop_index_blocks',
            $previous,
            $parsed,
            'update',
        );

        $fresh = $ctx->site->fresh();

        return OperationResult::ok([
            'blocks' => $parsed,
            'blocks_revision' => ShopIndexBlockSettings::revision($fresh),
        ], $state);
    }
}
