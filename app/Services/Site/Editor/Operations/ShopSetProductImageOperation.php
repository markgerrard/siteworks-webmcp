<?php

namespace App\Services\Site\Editor\Operations;

use App\Models\Shop\ShopDraft;
use App\Services\Shop\ShopDraftWriter;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperationRecorder;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationFailed;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\ResultReceipt;
use App\Services\Site\Editor\Shop\ShopCatalogueChanges;
use App\Services\Site\Editor\Shop\ShopCataloguePayload;
use App\Services\Site\Editor\Shop\ShopEntityResolver;
use App\Services\Site\Editor\Shop\ShopProductProjection;
use App\Services\Site\Editor\Shop\ShopWriteLockset;
use App\Services\Site\Editor\Shop\ShopWriteOperation;

final class ShopSetProductImageOperation extends ShopWriteOperation
{
    public function __construct(
        private readonly ShopEntityResolver $resolver,
        private readonly EditorStateFactory $states,
        private readonly ShopDraftWriter $writer,
        private readonly ShopProductProjection $projection,
    ) {}

    public function name(): string
    {
        return 'set_product_image';
    }

    public function sideEffects(): string
    {
        return 'Attaches an existing site media object to a draft product. This does not publish anything and never accepts image bytes.';
    }

    /**
     * @return list<string>|null
     */
    public function allowedRoles(): ?array
    {
        return ['staff', 'client'];
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['media_id', 'product_revision', 'catalogue_revision'],
            'properties' => [
                'slug' => ['type' => 'string'],
                'product_id' => ['type' => 'integer'],
                'product_revision' => ['type' => 'integer'],
                'media_id' => ['type' => 'integer'],
                'sort_order' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 19],
                'alt' => ['type' => 'string'],
                'catalogue_revision' => ['type' => 'integer'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<int>
     */
    public function subjectProductIds(array $input): array
    {
        $site = self::incomingSite();
        if ($site !== null) {
            return [$this->resolver->product($site, $input)->id];
        }

        $id = $input['product_id'] ?? null;
        if (is_int($id) || (is_string($id) && preg_match('/^[1-9][0-9]*$/', $id) === 1)) {
            return [(int) $id];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function handleShopWrite(EditorContext $ctx, array $input, ShopWriteLockset $locks): OperationResult
    {
        $state = $this->states->for($ctx->site, null);
        $this->resolver->requireShop($ctx->site);
        ShopCataloguePayload::assertNoForbiddenKeys($input, $state, ['status', 'data_base64', 'primary']);

        $product = $locks->products[0] ?? throw new OperationFailed(OperationResult::fail(
            'not_found',
            'Product not found.',
            $state,
        ));

        $mediaId = $input['media_id'] ?? null;
        if (! is_int($mediaId) && ! (is_string($mediaId) && preg_match('/^[1-9][0-9]*$/', $mediaId) === 1)) {
            throw new OperationFailed(OperationResult::fail(
                'validation',
                'media_id is required.',
                $state,
                ['fields' => ['media_id' => ['required integer']]],
            ));
        }
        $media = $this->resolver->media($ctx->site, (int) $mediaId);

        if ($media->s3_key === null || $media->s3_key === '') {
            throw new OperationFailed(OperationResult::fail(
                'validation',
                'media_id must refer to uploaded media with an s3_key.',
                $state,
                ['fields' => ['media_id' => ['non-null s3_key']]],
            ));
        }

        if ($product->images()->count() >= 20) {
            throw new OperationFailed(OperationResult::fail(
                'validation',
                'A product may have at most 20 images.',
                $state,
                ['fields' => ['media_id' => ['at most 20 images']]],
            ));
        }

        $sortOrder = 0;
        if (array_key_exists('sort_order', $input) && $input['sort_order'] !== null) {
            $sortOrder = $input['sort_order'];
            if (! is_int($sortOrder) || $sortOrder < 0 || $sortOrder > 19) {
                throw new OperationFailed(OperationResult::fail(
                    'validation',
                    'sort_order must be an integer between 0 and 19.',
                    $state,
                    ['fields' => ['sort_order' => ['integer 0-19']]],
                ));
            }
        }

        $alt = $input['alt'] ?? $media->alt_text;
        if ($alt !== null && ! is_string($alt)) {
            throw new OperationFailed(OperationResult::fail(
                'validation',
                'alt must be a string.',
                $state,
                ['fields' => ['alt' => ['string']]],
            ));
        }

        $written = $this->writer->attachImage($ctx->site, $product, [
            'path' => $media->s3_key,
            'sort_order' => $sortOrder,
            'alt' => $alt,
        ], $ctx->actor->id);
        ($written['deferred'])();

        $fresh = $written['product']->fresh(['variants', 'images', 'categories']);
        EditorOperationRecorder::rememberProduct($fresh->slug);
        $catalogue = (int) ShopDraft::query()->where('site_id', $ctx->site->id)->value('catalogue_revision');

        $result = OperationResult::ok([
            'slug' => $fresh->slug,
            'revision' => (int) $fresh->revision,
            'catalogue_revision' => $catalogue,
        ], $state);
        $result->receipt = ResultReceipt::fromArray([
            'new_revision' => $catalogue,
            'effective' => $this->projection->detail($fresh),
            'changed' => [
                ShopCatalogueChanges::change(
                    $fresh->slug,
                    'images.'.$sortOrder,
                    null,
                    ['media_id' => (int) $media->id],
                    'insert',
                ),
            ],
            'warnings' => [],
        ]);

        return $result;
    }
}
