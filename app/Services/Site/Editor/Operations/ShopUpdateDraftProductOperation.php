<?php

namespace App\Services\Site\Editor\Operations;

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

final class ShopUpdateDraftProductOperation extends ShopWriteOperation
{
    public function __construct(
        private readonly ShopEntityResolver $resolver,
        private readonly EditorStateFactory $states,
        private readonly ShopDraftWriter $writer,
        private readonly ShopProductProjection $projection,
    ) {}

    public function name(): string
    {
        return 'update_draft_product';
    }

    public function sideEffects(): string
    {
        return 'Updates a draft catalogue product. Published and archived products are refused. This does not publish anything.';
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
            'required' => ['product_revision', 'catalogue_revision'],
            'properties' => [
                'slug' => ['type' => 'string'],
                'product_id' => ['type' => 'integer'],
                'product_revision' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'tax_class_code' => ['type' => 'string'],
                'primary_category_id' => ['type' => 'integer'],
                'extra_category_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                ],
                'variants' => [
                    'type' => 'array',
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

        $payload = [];
        $changes = [];

        if (array_key_exists('name', $input)) {
            if (! is_string($input['name']) || trim($input['name']) === '') {
                throw new OperationFailed(OperationResult::fail(
                    'validation',
                    'name must be a string.',
                    $state,
                    ['fields' => ['name' => ['string']]],
                ));
            }
            if ($input['name'] !== $product->name) {
                $changes[] = ShopCatalogueChanges::change($product->slug, 'name', $product->name, $input['name'], 'set');
            }
            $payload['name'] = $input['name'];
        }

        if (array_key_exists('description', $input)) {
            if ($input['description'] !== null && ! is_string($input['description'])) {
                throw new OperationFailed(OperationResult::fail(
                    'validation',
                    'description must be a string.',
                    $state,
                    ['fields' => ['description' => ['string']]],
                ));
            }
            if ($input['description'] !== $product->description) {
                $changes[] = ShopCatalogueChanges::change($product->slug, 'description', $product->description, $input['description'], 'set');
            }
            $payload['description'] = $input['description'];
        }

        if (array_key_exists('tax_class_code', $input)) {
            $code = $input['tax_class_code'];
            if ($code !== null && (! is_string($code) || $code === '')) {
                throw new OperationFailed(OperationResult::fail(
                    'validation',
                    'tax_class_code is invalid.',
                    $state,
                    ['fields' => ['tax_class_code' => ['must match a tax_classes.code']]],
                ));
            }
            $taxClassId = null;
            if (is_string($code) && $code !== '') {
                $taxClassId = TaxClass::query()->where('code', $code)->value('id');
                if ($taxClassId === null) {
                    throw new OperationFailed(OperationResult::fail(
                        'validation',
                        'tax_class_code is invalid.',
                        $state,
                        ['fields' => ['tax_class_code' => ['must match a tax_classes.code']]],
                    ));
                }
            }
            $before = $product->taxClass?->code;
            if ($before !== $code) {
                $changes[] = ShopCatalogueChanges::change($product->slug, 'tax_class_code', $before, $code, 'set');
            }
            $payload['tax_class_id'] = $taxClassId;
        }

        if (array_key_exists('primary_category_id', $input)) {
            $primary = $input['primary_category_id'];
            if ($primary !== null && ! is_int($primary) && ! (is_string($primary) && preg_match('/^[1-9][0-9]*$/', $primary) === 1)) {
                throw new OperationFailed(OperationResult::fail(
                    'validation',
                    'primary_category_id must be an integer.',
                    $state,
                    ['fields' => ['primary_category_id' => ['integer']]],
                ));
            }
            $payload['primary_category_id'] = $primary === null ? null : (int) $primary;
        }

        if (array_key_exists('extra_category_ids', $input)) {
            if (! is_array($input['extra_category_ids'])) {
                throw new OperationFailed(OperationResult::fail(
                    'validation',
                    'extra_category_ids must be an array.',
                    $state,
                    ['fields' => ['extra_category_ids' => ['array of integers']]],
                ));
            }
            $payload['extra_category_ids'] = array_map(intval(...), $input['extra_category_ids']);
        }

        if (array_key_exists('tags', $input)) {
            if (! is_array($input['tags'])) {
                throw new OperationFailed(OperationResult::fail(
                    'validation',
                    'tags must be an array of slugs.',
                    $state,
                    ['fields' => ['tags' => ['array of slugs']]],
                ));
            }
            $payload['tags'] = $input['tags'];
        }

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
            $payload['facts'] = $facts;
            $changes[] = ShopCatalogueChanges::change($product->slug, 'facts', $product->facts, $facts, 'set');
        }

        if (array_key_exists('variants', $input)) {
            $variants = ShopCataloguePayload::variants($input['variants'], $state, required: true);
            $existing = $product->variants()->get()->keyBy('sku');
            $payload['variants'] = [];
            foreach ($variants as $variant) {
                $current = $existing->get($variant['sku']);
                $path = 'variants.'.$variant['sku'].'.price_pence';
                if ($current === null) {
                    $changes[] = ShopCatalogueChanges::change($product->slug, $path, null, $variant['price_pence'], 'insert');
                } elseif ((int) $current->price_cents !== $variant['price_pence']) {
                    $changes[] = ShopCatalogueChanges::change($product->slug, $path, (int) $current->price_cents, $variant['price_pence'], 'set');
                }
                $row = [
                    'sku' => $variant['sku'],
                    'label' => $variant['label'],
                    'price_cents' => $variant['price_pence'],
                ];
                if (array_key_exists('weight_grams', $variant)) {
                    $row['weight_grams'] = $variant['weight_grams'];
                }
                $payload['variants'][] = $row;
            }
        }

        if (array_key_exists('customer_inputs', $input)) {
            try {
                $payload['customer_inputs'] = \App\Services\Shop\CustomerInputDefinition::normalize($input['customer_inputs']);
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
            $written = $this->writer->updateDraft($ctx->site, $product, $payload, $ctx->actor->id);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw new OperationFailed(OperationResult::fail(
                'validation',
                (string) (collect($e->errors())->flatten()->first() ?? $e->getMessage()),
                $state,
                ['fields' => $e->errors()],
            ));
        } catch (\InvalidArgumentException $e) {
            // Cross-site category ids (tenant guard in ShopDraftWriter::syncCategories) must surface as a
            // structured error, not `internal`.
            throw new OperationFailed(OperationResult::fail('validation', $e->getMessage(), $state));
        }
        ($written['deferred'])();

        $fresh = $written['product']->fresh(['variants', 'images', 'categories', 'taxClass']);
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
            'changed' => $changes,
            'warnings' => [],
        ]);

        return $result;
    }
}
