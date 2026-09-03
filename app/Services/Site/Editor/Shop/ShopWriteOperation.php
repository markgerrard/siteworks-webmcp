<?php

namespace App\Services\Site\Editor\Shop;

use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopDraft;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorState;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationFailed;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\RevisionScopes;
use Illuminate\Support\Facades\DB;

abstract class ShopWriteOperation extends BaseOperation
{
    /**
     * @var list<int>|null
     */
    private static ?array $declaredSubjectProductIds = null;

    private static ?self $incomingOperation = null;

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $incomingInput = null;

    private static ?Site $incomingSite = null;

    public function address(): string
    {
        return 'shop';
    }

    public function wrapInAdminChange(): bool
    {
        return false;
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function revisionMismatchCode(): string
    {
        return 'stale_revision';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<int>
     */
    abstract public function subjectProductIds(array $input): array;

    /**
     * @param  array<string, mixed>  $input
     */
    abstract protected function handleShopWrite(EditorContext $ctx, array $input, ShopWriteLockset $locks): OperationResult;

    /**
     * @return list<int>|null
     */
    public static function declaredSubjectProductIds(): ?array
    {
        return self::$declaredSubjectProductIds;
    }

    /**
     * Bind the in-flight write so the registered shop scope can resolve
     * subject ids from input at funnel time (before handle()).
     *
     * @param  array<string, mixed>  $input
     */
    public static function bindIncoming(self $operation, array $input, ?Site $site = null): void
    {
        self::$incomingOperation = $operation;
        self::$incomingInput = $input;
        self::$incomingSite = $site;
    }

    public static function bindSite(Site $site): void
    {
        self::$incomingSite = $site;
    }

    public static function incomingSite(): ?Site
    {
        return self::$incomingSite;
    }

    /**
     * @return array{op: self, input: array<string, mixed>}|null
     */
    public static function incoming(): ?array
    {
        if (self::$incomingOperation === null || self::$incomingInput === null) {
            return null;
        }

        return ['op' => self::$incomingOperation, 'input' => self::$incomingInput];
    }

    public static function clearIncoming(): void
    {
        self::$incomingOperation = null;
        self::$incomingInput = null;
        self::$incomingSite = null;
        self::$declaredSubjectProductIds = null;
    }

    /**
     * Lock subject products ascending by id, then ensure-and-lock shop_drafts.
     *
     * @param  list<int>  $productIds
     */
    public static function lockSubject(Site $site, array $productIds, ?int $actorUserId): ShopWriteLockset
    {
        $ids = array_values(array_unique(array_filter(
            $productIds,
            static fn (int $id): bool => $id > 0,
        )));
        sort($ids);

        $products = [];
        if ($ids !== []) {
            $found = Product::query()
                ->where('site_id', $site->id)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($found->count() !== count($ids)) {
                throw new OperationFailed(OperationResult::fail(
                    'not_found',
                    'Product not found.',
                    new EditorState(
                        siteId: $site->id,
                        pageId: null,
                        draftRevisionId: null,
                        compositionRevision: 0,
                        pendingPublish: false,
                    ),
                ));
            }

            $products = $found->all();
        }

        ShopDraft::query()->insertOrIgnore([[
            'site_id' => $site->id,
            'catalogue_revision' => 0,
            'updated_by_user_id' => $actorUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);

        $draft = ShopDraft::query()
            ->where('site_id', $site->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($ids !== []) {
            $variantIds = ProductVariant::query()
                ->whereIn('product_id', $ids)
                ->orderBy('id')
                ->pluck('id')
                ->all();
            if ($variantIds !== []) {
                VariantStock::query()
                    ->whereIn('variant_id', $variantIds)
                    ->orderBy('variant_id')
                    ->lockForUpdate()
                    ->get();
            }
        }

        return new ShopWriteLockset($products, $draft);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    final public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $run = function () use ($ctx, $input): OperationResult {
            self::bindSite($ctx->site);
            $ids = array_values(array_unique(array_map(
                intval(...),
                $this->subjectProductIds($input),
            )));
            if ($ids === [] && array_key_exists('product_revision', $input) && (
                (array_key_exists('product_id', $input) && $input['product_id'] !== null && $input['product_id'] !== '')
                || (is_string($input['slug'] ?? null) && $input['slug'] !== '')
            )) {
                $ids = [app(ShopEntityResolver::class)->product($ctx->site, $input)->id];
            }
            sort($ids);
            self::$declaredSubjectProductIds = $ids;

            try {
                $state = app(EditorStateFactory::class)->for($ctx->site, null);
                if (! $this->managesOwnRevision()) {
                    $expected = self::intOrNull($input['catalogue_revision'] ?? null);
                    if ($expected === null) {
                        throw new OperationFailed(OperationResult::fail(
                            'validation',
                            'catalogue_revision is required.',
                            $state,
                            ['fields' => ['catalogue_revision' => ['required integer']]],
                        ));
                    }

                    $stale = RevisionScopes::check('shop', $ctx, $expected, $state);
                    if ($stale !== null) {
                        throw new OperationFailed($stale);
                    }
                }

                $locks = self::lockSubject($ctx->site, $ids, $ctx->actor->id);

                foreach ($locks->products as $product) {
                    DraftOnlyGuard::assert($product, $state);
                }

                if ($ids !== []) {
                    $productRevision = self::intOrNull($input['product_revision'] ?? null);
                    if ($productRevision === null) {
                        throw new OperationFailed(OperationResult::fail(
                            'validation',
                            'product_revision is required.',
                            $state,
                            ['fields' => ['product_revision' => ['required integer']]],
                        ));
                    }

                    foreach ($locks->products as $product) {
                        if ((int) $product->revision !== $productRevision) {
                            throw new OperationFailed(OperationResult::fail(
                                'stale_revision',
                                'Product has moved.',
                                $state,
                                ['current_product_revision' => (int) $product->revision],
                            ));
                        }
                    }
                }

                $result = $this->handleShopWrite($ctx, $input, $locks);
                if (! $result->ok) {
                    throw new OperationFailed($result);
                }

                return $result;
            } finally {
                self::clearIncoming();
            }
        };

        return DB::transaction($run);
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^(0|-?[1-9][0-9]*)$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
