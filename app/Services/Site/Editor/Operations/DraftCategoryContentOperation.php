<?php

namespace App\Services\Site\Editor\Operations;

use App\Models\Shop\ShopDraft;
use App\Services\Shop\CategoryContentService;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\Shop\ShopEntityResolver;
use App\Services\Site\Editor\Shop\ShopWriteLockset;
use App\Services\Site\Editor\Shop\ShopWriteOperation;
use Illuminate\Validation\ValidationException;

final class DraftCategoryContentOperation extends ShopWriteOperation
{
    public function __construct(
        private readonly ShopEntityResolver $resolver,
        private readonly EditorStateFactory $states,
        private readonly CategoryContentService $content,
    ) {}

    public function name(): string
    {
        return 'draft_category_content';
    }

    public function sideEffects(): string
    {
        return 'Updates draft category copy, FAQs, and metadata for this site. The shop snapshot rebuild publishes the rendered storefront state.';
    }

    /** @return list<string>|null */
    public function allowedRoles(): ?array
    {
        return ['staff', 'client'];
    }

    /** @return array<string, mixed> */
    public function inputSchema(): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['slug', 'catalogue_revision'],
            'properties' => [
                'slug' => ['type' => 'string'],
                'description_long' => ['type' => ['string', 'null']],
                'faqs' => ['type' => 'array', 'maxItems' => 12, 'items' => ['type' => 'object', 'required' => ['q', 'a'], 'properties' => ['q' => ['type' => 'string', 'maxLength' => 160], 'a' => ['type' => 'string', 'maxLength' => 1200]]]],
                'meta_title' => ['type' => ['string', 'null'], 'maxLength' => 70],
                'meta_description' => ['type' => ['string', 'null'], 'maxLength' => 170],
                'catalogue_revision' => ['type' => 'integer'],
            ],
        ];
    }

    /** @return list<int> */
    public function subjectProductIds(array $input): array
    {
        return [];
    }

    /** @param array<string, mixed> $input */
    protected function handleShopWrite(EditorContext $ctx, array $input, ShopWriteLockset $locks): OperationResult
    {
        $state = $this->states->for($ctx->site, null);
        $this->resolver->requireShop($ctx->site);
        $category = $this->resolver->category($ctx->site, $input['slug'] ?? '');
        try {
            $updated = $this->content->update($category, array_intersect_key($input, array_flip(['description_long', 'faqs', 'meta_title', 'meta_description'])));
        } catch (ValidationException $exception) {
            return OperationResult::fail('validation', 'Category content failed validation.', $state, [
                'fields' => $exception->errors(),
            ]);
        }
        $locks->draft->catalogue_revision = (int) $locks->draft->catalogue_revision + 1;
        $locks->draft->updated_by_user_id = $ctx->actor->id;
        $locks->draft->save();

        return OperationResult::ok([
            'slug' => $updated->slug,
            'catalogue_revision' => (int) ShopDraft::query()->where('site_id', $ctx->site->id)->value('catalogue_revision'),
        ], $state);
    }
}
