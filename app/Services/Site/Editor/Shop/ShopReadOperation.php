<?php

namespace App\Services\Site\Editor\Shop;

use App\Models\Shop\ShopDraft;
use App\Models\Site;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorState;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationFailed;
use App\Services\Site\Editor\OperationResult;
use Illuminate\Support\Facades\Gate;

abstract class ShopReadOperation extends BaseOperation
{
    public function __construct(
        protected readonly ShopEntityResolver $resolver,
        protected readonly EditorStateFactory $states,
    ) {}

    public function address(): string
    {
        return 'shop';
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function wrapInAdminChange(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function allowedRoles(): ?array
    {
        return ['staff', 'client'];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    final public function handle(EditorContext $ctx, array $input): OperationResult
    {
        try {
            $this->resolver->requireShop($ctx->site);

            return $this->handleShopReadCoherently($ctx, $input);
        } catch (OperationFailed $exception) {
            return $exception->result;
        }
    }

    /**
     * Product rows and shop_drafts.catalogue_revision must describe one
     * snapshot. Read the revision, then the rows, then the revision again;
     * retry the row read once if the counter moved (spec §3.2).
     *
     * @param  array<string, mixed>  $input
     */
    private function handleShopReadCoherently(EditorContext $ctx, array $input): OperationResult
    {
        $before = $this->catalogueRevision($ctx->site);
        $result = $this->handleShopRead($ctx, $input);
        $after = $this->catalogueRevision($ctx->site);

        if ($before !== $after) {
            $result = $this->handleShopRead($ctx, $input);
            $after = $this->catalogueRevision($ctx->site);
        }

        if ($result->ok) {
            $result = OperationResult::ok(
                [...$result->data, 'catalogue_revision' => $after],
                $result->state,
            );
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    abstract protected function handleShopRead(EditorContext $ctx, array $input): OperationResult;

    /**
     * Drafts are included only after SitePolicy has authorised this site.
     * Never derived from RenderContext::fromRequest().
     */
    protected function includeDrafts(EditorContext $ctx): bool
    {
        return Gate::forUser($ctx->actor)->allows('view', $ctx->site);
    }

    protected function commerceState(Site $site): EditorState
    {
        return $this->states->for($site, null);
    }

    protected function catalogueRevision(Site $site): int
    {
        return (int) (ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision') ?? 0);
    }
}
