<?php

namespace App\Services\Site\Editor\Operations;

use App\Models\Shop\Product;
use App\Models\Shop\ShopDraft;
use App\Models\Shop\TaxClass;
use App\Services\Shop\ShopDraftWriter;
use App\Support\Shop\ProductFacts;
use Illuminate\Validation\ValidationException;
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

final class ShopDraftProductOperation extends ShopWriteOperation
{
    public function __construct(
        private readonly ShopEntityResolver $resolver,
        private readonly EditorStateFactory $states,
        private readonly ShopDraftWriter $writer,
        private readonly ShopProductProjection $projection,
    ) {}

    public function name(): string
    {
        return 'draft_product';
    }

    public function sideEffects(): string
    {
        return 'Creates a draft catalogue product with priced variants. This does not publish anything — the product stays hidden on the live site until a human publishes it.';
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
            'required' => ['name', 'category_slug', 'variants', 'catalogue_revision'],
            'properties' => [
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'category_slug' => ['type' => 'string'],
                'tax_class_code' => ['type' => 'string'],
                'variants' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 20,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['sku', 'price_pence'],
                        'properties' => [
                            'sku' => ['type' => 'string', 'pattern' => '^[A-Z0-9-]{1,32}$'],
                            'label' => ['type' => 'string'],
                            'price_pence' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000000],
                            'weight_grams' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 100000],
                        ],
                    ],
                ],
                'catalogue_revision' => ['type' => 'integer'],
                'tags' => [
                    'type' => 'array',
                    'maxItems' => 5,
                    'items' => ['type' => 'string'],
                ],
                'facts' => ProductFacts::inputSchema(),
                'customer_inputs' => \App\Services\Shop\CustomerInputDefinition::inputSchema(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<int>
     */
    public function subjectProductIds(array $input): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function handleShopWrite(EditorContext $ctx, array $input, ShopWriteLockset $locks): OperationResult
    {
        $state = $this->states->for($ctx->site, null);
        $this->resolver->requireShop($ctx->site);
        ShopCataloguePayload::assertNoForbiddenKeys($input, $state);

        $name = $input['name'] ?? null;
        if (! is_string($name) || trim($name) === '') {
            throw new OperationFailed(OperationResult::fail(
                'validation',
                'name is required.',
                $state,
                ['fields' => ['name' => ['required string']]],
            ));
        }

        $description = $input['description'] ?? null;
        if ($description !== null && ! is_string($description)) {
            throw new OperationFailed(OperationResult::fail(
                'validation',
                'description must be a string.',
                $state,
                ['fields' => ['description' => ['string']]],
            ));
        }

        $categorySlug = $input['category_slug'] ?? null;
        if (! is_string($categorySlug) || $categorySlug === '') {
            throw new OperationFailed(OperationResult::fail(
                'validation',
                'category_slug is required.',
                $state,
                ['fields' => ['category_slug' => ['required string']]],
            ));
        }
        $category = $this->resolver->category($ctx->site, $categorySlug);

        $taxClassId = null;
        $taxClassCode = $input['tax_class_code'] ?? null;
        if ($taxClassCode !== null) {
            if (! is_string($taxClassCode) || $taxClassCode === '') {
                throw new OperationFailed(OperationResult::fail(
                    'validation',
                    'tax_class_code is invalid.',
                    $state,
                    ['fields' => ['tax_class_code' => ['must match a tax_classes.code']]],
                ));
            }

            $taxClassId = TaxClass::query()->where('code', $taxClassCode)->value('id');
            if ($taxClassId === null) {
                throw new OperationFailed(OperationResult::fail(
                    'validation',
                    'tax_class_code is invalid.',
                    $state,
                    ['fields' => ['tax_class_code' => ['must match a tax_classes.code']]],
                ));
            }
        }

        $variants = ShopCataloguePayload::variants($input['variants'] ?? null, $state, required: true);

        $facts = null;
        if (array_key_exists('facts', $input)) {
            try {
                $facts = ProductFacts::validateFacts(
                    $input['facts'],
                    ProductFacts::groups($ctx->site->product_fact_groups),
                    rejectUnknown: true,
                );
            } catch (ValidationException $exception) {
                throw new OperationFailed(OperationResult::fail(
                    'validation',
                    collect($exception->errors())->flatten()->first() ?? 'facts is invalid.',
                    $state,
                    ['fields' => $exception->errors()],
                ));
            }
        }

        $draftInput = [
            'name' => $name,
            'description' => $description,
            'facts' => $facts,
            'category_id' => $category->id,
            'tax_class_id' => $taxClassId,
            'is_ai_seeded' => $ctx->channel->isAgent(),
            'is_ai_reviewed' => false,
            'ai_seed_source' => $ctx->channel->isAgent() ? 'agent_tool' : null,
            'variants' => array_map(
                function (array $variant): array {
                    $row = [
                        'sku' => $variant['sku'],
                        'label' => $variant['label'],
                        'price_cents' => $variant['price_pence'],
                    ];
                    if (array_key_exists('weight_grams', $variant)) {
                        $row['weight_grams'] = $variant['weight_grams'];
                    }

                    return $row;
                },
                $variants,
            ),
        ];
        if (array_key_exists('tags', $input)) {
            if (! is_array($input['tags'])) {
                throw new OperationFailed(OperationResult::fail(
                    'validation',
                    'tags must be an array of slugs.',
                    $state,
                    ['fields' => ['tags' => ['array of slugs']]],
                ));
            }
            $draftInput['tags'] = $input['tags'];
        }
        if (array_key_exists('customer_inputs', $input)) {
            try {
                $draftInput['customer_inputs'] = \App\Services\Shop\CustomerInputDefinition::normalize($input['customer_inputs']);
            } catch (\Illuminate\Validation\ValidationException $e) {
                throw new OperationFailed(OperationResult::fail(
                    'validation',
                    collect($e->errors())->flatten()->first() ?: 'customer_inputs is invalid.',
                    $state,
                    ['fields' => ['customer_inputs' => ['invalid']]],
                ));
            }
        }

        try {
            $written = $this->writer->createDraft($ctx->site, $draftInput, $ctx->actor->id);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw new OperationFailed(OperationResult::fail(
                'validation',
                (string) (collect($e->errors())->flatten()->first() ?? $e->getMessage()),
                $state,
                ['fields' => $e->errors()],
            ));
        } catch (\InvalidArgumentException $e) {
            throw new OperationFailed(OperationResult::fail('validation', $e->getMessage(), $state));
        }
        ($written['deferred'])();

        $product = $written['product']->fresh(['variants', 'images', 'categories']);
        EditorOperationRecorder::rememberProduct($product->slug);
        $catalogue = (int) ShopDraft::query()->where('site_id', $ctx->site->id)->value('catalogue_revision');
        $changes = [
            ShopCatalogueChanges::change($product->slug, 'name', null, $product->name, 'insert'),
        ];
        if (is_string($description) && $description !== '') {
            $changes[] = ShopCatalogueChanges::change($product->slug, 'description', null, $description, 'insert');
        }
        foreach ($variants as $variant) {
            $changes[] = ShopCatalogueChanges::change(
                $product->slug,
                'variants.'.$variant['sku'].'.price_pence',
                null,
                $variant['price_pence'],
                'insert',
            );
        }

        $result = OperationResult::ok([
            'slug' => $product->slug,
            'revision' => (int) $product->revision,
            'catalogue_revision' => $catalogue,
        ], $state);
        $result->receipt = ResultReceipt::fromArray([
            'new_revision' => $catalogue,
            'effective' => $this->projection->detail($product),
            'changed' => $changes,
            'warnings' => [],
        ]);

        return $result;
    }
}
