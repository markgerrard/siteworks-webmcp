<?php

namespace App\Services\Site\Editor\Operations;

use App\Enums\Shop\ProductReviewSource;
use App\Enums\Shop\ProductReviewStatus;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\Product;
use App\Models\Shop\ProductReview;
use App\Observers\Shop\ProductReviewObserver;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationFailed;
use App\Services\Site\Editor\OperationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SeedProductReviewsOperation extends BaseOperation
{
    public const MAX_REVIEWS = 40;

    public function __construct(private readonly EditorStateFactory $states) {}

    public function name(): string
    {
        return 'seed_product_reviews';
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

    /**
     * @return list<string>|null
     */
    public function allowedRoles(): ?array
    {
        return ['staff'];
    }

    public function sideEffects(): string
    {
        return 'Bootstraps a new store with clearly-marked seeded reviews (source: seed, shown as such). Staff only; not exposed to agents in this demo. Does not enable the public review form.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['reviews', 'composition_revision'],
            'properties' => [
                'reviews' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => self::MAX_REVIEWS,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['product_slug', 'rating', 'title', 'body', 'author_name'],
                        'properties' => [
                            'product_slug' => ['type' => 'string'],
                            'rating' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                            'title' => ['type' => 'string'],
                            'body' => ['type' => 'string'],
                            'author_name' => ['type' => 'string'],
                        ],
                    ],
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
        $reviews = $input['reviews'] ?? null;
        if (! is_array($reviews) || $reviews === []) {
            return OperationResult::fail('validation', 'reviews must be a non-empty list.', $state, [
                'fields' => ['reviews' => ['required']],
            ]);
        }
        if (count($reviews) > self::MAX_REVIEWS) {
            return OperationResult::fail('validation', 'Too many reviews in one call.', $state, [
                'fields' => ['reviews' => ['max '.self::MAX_REVIEWS]],
            ]);
        }

        ProductReviewObserver::mute();
        try {
            $created = DB::transaction(function () use ($ctx, $reviews, $state): array {
                $created = [];
                foreach (array_values($reviews) as $index => $row) {
                    if (! is_array($row)) {
                        throw new OperationFailed(OperationResult::fail('validation', 'Each review must be an object.', $state, [
                            'fields' => ['reviews.'.$index => ['object required']],
                        ]));
                    }
                    $slug = $row['product_slug'] ?? null;
                    if (! is_string($slug) || $slug === '') {
                        throw new OperationFailed(OperationResult::fail('validation', 'product_slug is required.', $state, [
                            'fields' => ['reviews.'.$index.'.product_slug' => ['required']],
                        ]));
                    }
                    $product = Product::query()
                        ->where('site_id', $ctx->site->id)
                        ->where('slug', $slug)
                        ->first();
                    if ($product === null) {
                        throw new OperationFailed(OperationResult::fail('not_found', 'Product not found.', $state));
                    }

                    try {
                        $created[] = ProductReview::validatedCreate([
                            'site_id' => $ctx->site->id,
                            'product_id' => $product->id,
                            'rating' => $row['rating'] ?? null,
                            'title' => $row['title'] ?? null,
                            'body' => $row['body'] ?? null,
                            'author_name' => $row['author_name'] ?? null,
                            'status' => ProductReviewStatus::Published->value,
                            'source' => ProductReviewSource::Seed->value,
                        ]);
                    } catch (ValidationException $e) {
                        $fields = [];
                        foreach ($e->errors() as $field => $messages) {
                            $fields['reviews.'.$index.'.'.$field] = $messages;
                        }

                        throw new OperationFailed(OperationResult::fail('validation', $e->getMessage(), $state, ['fields' => $fields]));
                    }
                }

                return $created;
            });

            foreach (ProductReviewObserver::takeDirtySiteIds() as $siteId) {
                RebuildShopSnapshot::dispatch($siteId)->afterCommit();
            }
        } catch (OperationFailed $e) {
            ProductReviewObserver::takeDirtySiteIds();

            return $e->result;
        } finally {
            ProductReviewObserver::unmute();
        }

        $ctx->changes->record(
            'site',
            'shop_product_reviews',
            null,
            count($created),
            'create',
        );

        return OperationResult::ok([
            'created' => count($created),
            'ids' => array_map(fn (ProductReview $review): int => $review->id, $created),
        ], $state);
    }
}
