<?php

namespace App\Services\Shop;

use App\Enums\Shop\ProductStatus;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Shop\ShopDraft;
use App\Models\Shop\ShopProductImportReceipt;
use App\Models\Shop\TaxClass;
use App\Models\Site;
use App\Support\Shop\ProductFacts;
use App\Support\Shop\ProductTagAssignment;
use App\Support\Shop\ProductTagVocabulary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProductImporter
{
    public function __construct(private readonly ShopDraftWriter $writer) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function run(Site $site, array $input, ?int $actorUserId, bool $isAgent): array
    {
        $schemaVersion = $input['schema_version'] ?? null;
        if ($schemaVersion !== ProductImportContract::SCHEMA_VERSION) {
            throw new ProductImportFailed('validation', 'schema_version must be '.ProductImportContract::SCHEMA_VERSION.'.', [
                'fields' => ['schema_version' => ['must be '.ProductImportContract::SCHEMA_VERSION]],
            ]);
        }

        $format = $input['format'] ?? null;
        if (! is_string($format) || ! in_array($format, ['json', 'csv', 'md'], true)) {
            throw new ProductImportFailed('validation', 'format must be json, csv, or md.', [
                'fields' => ['format' => ['json, csv, or md']],
            ]);
        }

        $data = $input['data'] ?? null;
        if (! is_string($data)) {
            throw new ProductImportFailed('validation', 'data must be a string.', [
                'fields' => ['data' => ['required string']],
            ]);
        }
        if (strlen($data) > ProductImportContract::MAX_BYTES) {
            throw new ProductImportFailed('validation', 'data exceeds the '.ProductImportContract::MAX_BYTES.' byte limit.', [
                'fields' => ['data' => ['max '.ProductImportContract::MAX_BYTES.' bytes']],
            ]);
        }

        $dryRun = ($input['dry_run'] ?? false) === true;
        $forceCreate = ($input['force_create'] ?? false) === true;
        $expectedRevision = $this->intOrNull($input['catalogue_revision'] ?? $input['expected_revision'] ?? null);
        if ($expectedRevision === null) {
            throw new ProductImportFailed('validation', 'expected_revision is required.', [
                'fields' => ['expected_revision' => ['required integer']],
            ]);
        }

        try {
            $parsed = ProductImportParser::parse($format, $data);
        } catch (\InvalidArgumentException $exception) {
            throw new ProductImportFailed('validation', 'data is unparseable.', [
                'fields' => ['data' => ['unparseable']],
            ]);
        }

        if (count($parsed) > ProductImportContract::MAX_PRODUCTS) {
            throw new ProductImportFailed('validation', 'import exceeds the '.ProductImportContract::MAX_PRODUCTS.' product limit.', [
                'fields' => ['data' => ['max '.ProductImportContract::MAX_PRODUCTS.' products']],
            ]);
        }

        $categoryIndex = $this->categoryIndex($site);
        $planToken = $this->planToken($parsed, $expectedRevision, $categoryIndex, $forceCreate);

        if (! $dryRun && is_string($input['plan_token'] ?? null) && $input['plan_token'] !== '') {
            if (! hash_equals($planToken, $input['plan_token'])) {
                throw new ProductImportFailed('plan_stale', 'The dry-run plan is stale.');
            }
        }

        $evaluated = $this->evaluate($site, $parsed, $categoryIndex, $forceCreate);

        if ($dryRun) {
            return $this->envelope(
                schemaVersion: $schemaVersion,
                results: array_map(fn (array $row): array => $this->resultRow($row, created: $row['errors'] === []), $evaluated),
                revision: $expectedRevision,
                idempotencyKey: is_string($input['idempotency_key'] ?? null) ? $input['idempotency_key'] : null,
                planToken: $planToken,
            );
        }

        $idempotencyKey = $input['idempotency_key'] ?? null;
        if (! is_string($idempotencyKey) || $idempotencyKey === '') {
            throw new ProductImportFailed('validation', 'idempotency_key is required when dry_run is false.', [
                'fields' => ['idempotency_key' => ['required string']],
            ]);
        }

        $existing = ShopProductImportReceipt::query()
            ->where('site_id', $site->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing !== null) {
            return $existing->receipt;
        }

        $current = (int) (ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision') ?? 0);
        if ($current !== $expectedRevision) {
            throw new ProductImportFailed('revision_conflict', 'Shop catalogue has moved.', [
                'current_catalogue_revision' => $current,
            ]);
        }

        $createdAny = false;
        $written = [];

        $deferred = $this->writer->write($site, [], $actorUserId, function () use ($site, $evaluated, $actorUserId, $isAgent, &$createdAny, &$written): void {
            foreach ($evaluated as $index => $row) {
                if ($row['matched'] !== null || $row['errors'] !== []) {
                    $written[$index] = $this->resultRow($row, created: false);
                    continue;
                }

                try {
                    DB::transaction(function () use ($site, $row, $actorUserId, $isAgent, $index, &$written): void {
                        $payload = $this->draftPayload($site, $row, $isAgent);
                        $created = $this->writer->createDraft($site, $payload, $actorUserId, bumpCatalogue: false);
                        // Warnings ride on the product itself so the review need is visible in the
                        // admin long after the tool receipt has scrolled away. Same transaction as
                        // the create: a product never exists without its notes.
                        if ($row['warnings'] !== []) {
                            $created['product']->update(['review_notes' => $row['warnings']]);
                        }
                        $written[$index] = $this->resultRow($row, created: true, product: $created['product']);
                    });
                    $createdAny = true;
                } catch (UniqueConstraintViolationException) {
                    $row['errors'][] = 'slug_taken';
                    $written[$index] = $this->resultRow($row, created: false);
                } catch (\Throwable) {
                    $row['errors'][] = 'write_failed';
                    $written[$index] = $this->resultRow($row, created: false);
                }
            }

            if ($createdAny) {
                $this->writer->bumpCatalogue($site, $actorUserId);
            }
        });

        $revision = (int) (ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision') ?? 0);
        ksort($written);
        $envelope = $this->envelope(
            schemaVersion: $schemaVersion,
            results: array_values($written),
            revision: $revision,
            idempotencyKey: $idempotencyKey,
            planToken: null,
        );

        if ($createdAny) {
            $deferred();
        }

        ShopProductImportReceipt::query()->create([
            'site_id' => $site->id,
            'idempotency_key' => $idempotencyKey,
            'receipt' => $envelope,
        ]);

        return $envelope;
    }

    /**
     * @param  list<array<string, mixed>>  $parsed
     * @param  array<string, array{id: int, slug: string, name: string}>  $categoryIndex
     * @return list<array<string, mixed>>
     */
    private function evaluate(Site $site, array $parsed, array $categoryIndex, bool $forceCreate): array
    {
        [$existingByName, $existingBySlug] = $this->existingIndex($site);
        $seenSkus = [];
        $out = [];

        foreach ($parsed as $product) {
            $errors = $product['errors'] ?? [];
            // Warnings never block a row; they mark what a human should check before publishing.
            $warnings = [];
            $name = is_string($product['name'] ?? null) ? trim($product['name']) : '';
            if ($name === '') {
                $errors[] = 'missing_name';
            }
            $slug = is_string($product['slug'] ?? null) && $product['slug'] !== '' ? $product['slug'] : null;

            // A row that names a product the catalogue already has is that product, not a
            // new one: it is reported as matched and never created, so a re-typed flyer
            // cannot duplicate the range. The caller creates it anyway only by saying so
            // (force_create) or by giving it a slug of its own; either way the match is
            // still noted for the reviewer.
            $matched = null;
            $existing = $name !== '' ? ($existingByName[self::normaliseName($name)] ?? null) : null;
            if ($existing !== null) {
                $warnings[] = 'matches_existing';
                if (! $forceCreate && ($slug === null || $slug === $existing['slug'])) {
                    $matched = $existing;
                }
            } elseif ($slug !== null && isset($existingBySlug[$slug])) {
                $errors[] = 'slug_taken';
            }

            $primary = $this->resolveCategory($product['primary_category'] ?? null, $categoryIndex);
            $extraIds = [];
            if ($primary === null) {
                $errors[] = 'category_not_found';
            }
            foreach ($product['extra_categories'] ?? [] as $extra) {
                $resolved = $this->resolveCategory($extra, $categoryIndex);
                if ($resolved === null) {
                    $errors[] = 'category_not_found';
                    continue;
                }
                if ($primary !== null && $resolved['id'] === $primary['id']) {
                    $warnings[] = 'duplicate_category';
                    continue;
                }
                $extraIds[] = $resolved['id'];
            }

            $variants = $product['variants'] ?? [];
            if (! is_array($variants) || $variants === []) {
                $errors[] = 'missing_variants';
                $variants = [];
            }

            $description = is_string($product['description'] ?? null) ? trim($product['description']) : '';
            if ($description === '') {
                $warnings[] = 'missing_description';
            }

            $productSkus = [];
            $cleanVariants = [];
            foreach ($variants as $variant) {
                $label = $variant['label'] ?? null;
                if (count($variants) > 1 && (! is_string($label) || trim($label) === '')) {
                    $warnings[] = 'missing_variant_label';
                }
                $sku = is_string($variant['sku'] ?? null) ? $variant['sku'] : '';
                if (preg_match('/^[A-Z0-9-]{1,32}$/', $sku) !== 1) {
                    $errors[] = 'bad_sku';
                }
                if ($sku !== '' && (isset($productSkus[$sku]) || isset($seenSkus[$sku]))) {
                    $errors[] = 'duplicate_sku';
                }
                $productSkus[$sku] = true;
                $price = $variant['price_pence'] ?? null;
                if ($price === null) {
                    // No readable price on the source. The row is still drafted, at no
                    // price, and carries the note so the merchant supplies the number.
                    $warnings[] = 'price_missing';
                } elseif (! is_int($price) || $price < MoneyPence::MIN || $price > MoneyPence::MAX) {
                    $errors[] = 'bad_price';
                }
                $weight = $variant['weight_grams'] ?? null;
                if ($weight !== null && (! is_int($weight) || $weight < 0 || $weight > 100000)) {
                    $errors[] = 'bad_weight';
                }
                $stock = $variant['stock'] ?? null;
                if ($stock !== null && (! is_int($stock) || $stock < 0)) {
                    $errors[] = 'bad_stock';
                }
                $cleanVariants[] = $variant;
            }

            foreach (array_keys($productSkus) as $sku) {
                if ($sku !== '') {
                    $seenSkus[$sku] = true;
                }
            }

            $taxClassId = null;
            $taxCode = $product['tax_class_code'] ?? null;
            if (is_string($taxCode) && $taxCode !== '') {
                $taxClassId = TaxClass::query()->where('code', $taxCode)->value('id');
                if ($taxClassId === null) {
                    $errors[] = 'unknown_tax_class';
                }
            }

            $tags = $product['tags'] ?? [];
            if ($tags !== [] && is_array($tags)) {
                try {
                    ProductTagAssignment::parse($tags, ProductTagVocabulary::normalize($site->product_tags));
                } catch (\Throwable) {
                    $errors[] = 'unknown_tag';
                }
            }

            $facts = $product['facts'] ?? null;
            if (is_array($facts)) {
                try {
                    $facts = ProductFacts::validateFacts($facts, ProductFacts::groups($site->product_fact_groups), rejectUnknown: true);
                } catch (ValidationException) {
                    $errors[] = 'bad_facts';
                }
            }

            $customerInputs = $product['customer_inputs'] ?? [];
            if ($customerInputs !== []) {
                try {
                    $customerInputs = CustomerInputDefinition::normalize($customerInputs);
                } catch (ValidationException) {
                    $errors[] = 'bad_customer_inputs';
                }
            }

            $out[] = [
                'source_row' => $product['source_row'],
                'name' => $name,
                'slug' => $slug,
                'matched' => $matched,
                'description' => is_string($product['description'] ?? null) ? $product['description'] : null,
                'primary_category_id' => $primary['id'] ?? null,
                'primary_category_slug' => $primary['slug'] ?? null,
                'extra_category_ids' => array_values(array_unique($extraIds)),
                'tags' => is_array($tags) ? $tags : [],
                'tax_class_id' => $taxClassId,
                'variants' => $cleanVariants,
                'customer_inputs' => is_array($customerInputs) ? $customerInputs : [],
                'facts' => $facts,
                'errors' => array_values(array_unique($errors)),
                'warnings' => array_values(array_unique($warnings)),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function draftPayload(Site $site, array $row, bool $isAgent): array
    {
        $variants = [];
        foreach ($row['variants'] as $variant) {
            $item = [
                'sku' => $variant['sku'],
                'label' => $variant['label'] ?? null,
                'price_cents' => $variant['price_pence'] ?? 0,
            ];
            if (array_key_exists('weight_grams', $variant)) {
                $item['weight_grams'] = $variant['weight_grams'];
            }
            if (array_key_exists('stock', $variant)) {
                $item['stock'] = $variant['stock'];
            }
            $variants[] = $item;
        }

        $payload = [
            'name' => $row['name'],
            'description' => $row['description'],
            'facts' => $row['facts'],
            'category_id' => $row['primary_category_id'],
            'extra_category_ids' => $row['extra_category_ids'],
            'tax_class_id' => $row['tax_class_id'],
            'is_ai_seeded' => $isAgent,
            'is_ai_reviewed' => false,
            'ai_seed_source' => $isAgent ? 'agent_tool' : null,
            'variants' => $variants,
        ];
        if (is_string($row['slug'] ?? null) && $row['slug'] !== '') {
            $payload['slug'] = $row['slug'];
        }
        if (($row['tags'] ?? []) !== []) {
            $payload['tags'] = $row['tags'];
        }
        // A row that names its own customer inputs keeps them. A row that names none
        // takes only the site's free-text defaults (a note, a message): a default
        // choice input has placeholder options, and on a product page that reads as a
        // required decision the shopper cannot make.
        $payload['customer_inputs'] = ($row['customer_inputs'] ?? []) !== []
            ? $row['customer_inputs']
            : $this->freeTextDefaultInputs($site);

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function freeTextDefaultInputs(Site $site): array
    {
        $defaults = is_array($site->default_customer_inputs) ? $site->default_customer_inputs : [];

        return array_values(array_filter(
            $defaults,
            fn ($input): bool => is_array($input) && in_array($input['kind'] ?? null, ['text', 'textarea'], true),
        ));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function resultRow(array $row, bool $created, ?Product $product = null): array
    {
        // Name, category and lowest price let a reader recognise the row without a
        // second lookup; the page-side summary is drawn from this receipt alone.
        $summary = [
            'name' => $row['name'],
            'category' => $row['primary_category_slug'] ?? null,
            'price_pence' => $this->lowestPricePence($row['variants'] ?? []),
        ];

        if ($row['matched'] !== null) {
            return [
                'source_row' => $row['source_row'],
                'status' => 'matched',
                'slug' => $row['matched']['slug'],
                'product_id' => $row['matched']['id'],
                ...$summary,
                'warnings' => $row['warnings'] ?? [],
            ];
        }

        if ($created) {
            $out = [
                'source_row' => $row['source_row'],
                'status' => 'created',
                'slug' => $product?->slug ?? $row['slug'],
                ...$summary,
                'warnings' => $row['warnings'] ?? [],
            ];
            if ($product !== null) {
                $out['product_id'] = $product->id;
            }

            return $out;
        }

        return [
            'source_row' => $row['source_row'],
            'status' => 'rejected',
            ...$summary,
            'errors' => $row['errors'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     */
    private function lowestPricePence(array $variants): ?int
    {
        $prices = [];
        foreach ($variants as $variant) {
            if (is_int($variant['price_pence'] ?? null)) {
                $prices[] = $variant['price_pence'];
            }
        }

        return $prices === [] ? null : min($prices);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function envelope(int $schemaVersion, array $results, int $revision, ?string $idempotencyKey, ?string $planToken): array
    {
        $created = 0;
        $matched = 0;
        $failed = 0;
        foreach ($results as $row) {
            match ($row['status'] ?? '') {
                'created' => $created++,
                'matched' => $matched++,
                default => $failed++,
            };
        }

        $data = [
            'schema_version' => $schemaVersion,
            'created' => $created,
            'matched' => $matched,
            'failed' => $failed,
            'new_revision' => $revision,
            'results' => $results,
            'publishable' => false,
        ];
        if ($idempotencyKey !== null) {
            $data['idempotency_key'] = $idempotencyKey;
        }
        if ($planToken !== null) {
            $data['plan_token'] = $planToken;
        }

        return $data;
    }

    /**
     * @param  list<array<string, mixed>>  $parsed
     * @param  array<string, array{id: int, slug: string, name: string}>  $categoryIndex
     */
    private function planToken(array $parsed, int $expectedRevision, array $categoryIndex, bool $forceCreate): string
    {
        $refs = [];
        foreach ($parsed as $product) {
            foreach ([$product['primary_category'] ?? null, ...($product['extra_categories'] ?? [])] as $ref) {
                if (! is_array($ref)) {
                    continue;
                }
                $resolved = $this->resolveCategory($ref, $categoryIndex);
                $key = ($ref['by'] ?? '').':'.($ref['value'] ?? '');
                $refs[$key] = [
                    'by' => $ref['by'] ?? null,
                    'value' => $ref['value'] ?? null,
                    'id' => $resolved['id'] ?? null,
                ];
            }
        }
        ksort($refs);

        $payload = $this->canonical([
            'schema_version' => ProductImportContract::SCHEMA_VERSION,
            'expected_revision' => $expectedRevision,
            'force_create' => $forceCreate,
            'products' => $parsed,
            'categories' => array_values($refs),
        ]);

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Live and draft products by normalised name, and every product (archived
     * included, since slugs stay reserved) by slug.
     *
     * @return array{0: array<string, array{id: int, slug: string, name: string}>, 1: array<string, array{id: int, slug: string, name: string}>}
     */
    private function existingIndex(Site $site): array
    {
        $byName = [];
        $bySlug = [];
        $rows = Product::query()->where('site_id', $site->id)->get(['id', 'slug', 'name', 'status']);
        foreach ($rows as $row) {
            $entry = ['id' => (int) $row->id, 'slug' => (string) $row->slug, 'name' => (string) $row->name];
            $bySlug[$entry['slug']] = $entry;
            if ($row->status === ProductStatus::Archived) {
                continue;
            }
            $key = self::normaliseName($entry['name']);
            if ($key !== '' && ! isset($byName[$key])) {
                $byName[$key] = $entry;
            }
        }

        return [$byName, $bySlug];
    }

    /**
     * Case, punctuation and spacing are not identity: "Fig & Walnut Tart",
     * "fig and walnut tart" and "Fig-Walnut Tart" name the same product.
     */
    public static function normaliseName(string $name): string
    {
        $name = mb_strtolower(str_replace('&', ' and ', $name));

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $name);
    }

    /**
     * @return array<string, array{id: int, slug: string, name: string}>
     */
    private function categoryIndex(Site $site): array
    {
        $index = [];
        foreach (Category::query()->where('site_id', $site->id)->get(['id', 'slug', 'name']) as $category) {
            $index['slug:'.$category->slug] = ['id' => (int) $category->id, 'slug' => $category->slug, 'name' => $category->name];
            $index['name:'.$category->name] = ['id' => (int) $category->id, 'slug' => $category->slug, 'name' => $category->name];
        }

        return $index;
    }

    /**
     * @param  array{by?: string, value?: string}|null  $ref
     * @param  array<string, array{id: int, slug: string, name: string}>  $categoryIndex
     * @return array{id: int, slug: string, name: string}|null
     */
    private function resolveCategory(?array $ref, array $categoryIndex): ?array
    {
        if ($ref === null || ! isset($ref['by'], $ref['value'])) {
            return null;
        }

        return $categoryIndex[$ref['by'].':'.$ref['value']] ?? null;
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($value === [] || array_is_list($value)) {
            return array_map($this->canonical(...), $value);
        }

        ksort($value);

        return array_map($this->canonical(...), $value);
    }

    private function intOrNull(mixed $value): ?int
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
