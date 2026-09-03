<?php

namespace App\Services\Site\Editor\Shop;

use App\Services\Site\Editor\EditorState;
use App\Services\Site\Editor\OperationFailed;
use App\Services\Site\Editor\OperationResult;

final class ShopCataloguePayload
{
    public const PRICE_MIN = 1;

    public const PRICE_MAX = 10_000_000;

    public const WEIGHT_MIN = 0;

    public const WEIGHT_MAX = 100_000;

    public const VARIANT_MAX = 20;

    /**
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $input
     */
    public static function assertNoForbiddenKeys(array $input, EditorState $state, array $keys = ['status', 'slug', 'data_base64', 'primary']): void
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                throw new OperationFailed(OperationResult::fail(
                    'validation',
                    "{$key} is not accepted.",
                    $state,
                    ['fields' => [$key => ['not accepted']]],
                ));
            }
        }
    }

    public static function pricePence(mixed $value, EditorState $state): int
    {
        if (! is_int($value) || $value < self::PRICE_MIN || $value > self::PRICE_MAX) {
            throw new OperationFailed(OperationResult::fail(
                'validation',
                'price_pence must be an integer between 1 and 10000000.',
                $state,
                ['fields' => ['price_pence' => ['integer 1-10000000']]],
            ));
        }

        return $value;
    }

    public static function weightGrams(mixed $value, EditorState $state): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) || $value < self::WEIGHT_MIN || $value > self::WEIGHT_MAX) {
            throw new OperationFailed(OperationResult::fail(
                'validation',
                'weight_grams must be an integer between 0 and 100000.',
                $state,
                ['fields' => ['weight_grams' => ['integer 0-100000']]],
            ));
        }

        return $value;
    }

    /**
     * @param  list<array{sku: string, label?: ?string, price_pence: int, weight_grams?: ?int}>|null  $variants
     * @return list<array{sku: string, label: ?string, price_pence: int, weight_grams?: ?int}>
     */
    public static function variants(mixed $variants, EditorState $state, bool $required): array
    {
        if ($variants === null) {
            if ($required) {
                throw new OperationFailed(OperationResult::fail(
                    'validation',
                    'variants is required.',
                    $state,
                    ['fields' => ['variants' => ['required, 1-20']]],
                ));
            }

            return [];
        }

        if (! is_array($variants) || ! array_is_list($variants) || $variants === [] || count($variants) > self::VARIANT_MAX) {
            throw new OperationFailed(OperationResult::fail(
                'validation',
                'variants must contain between 1 and 20 items.',
                $state,
                ['fields' => ['variants' => ['1-20']]],
            ));
        }

        $seen = [];
        $out = [];

        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                throw new OperationFailed(OperationResult::fail(
                    'validation',
                    'Each variant must be an object.',
                    $state,
                    ['fields' => ['variants' => ['object with sku and price_pence']]],
                ));
            }

            $sku = $variant['sku'] ?? null;
            if (! is_string($sku) || preg_match('/^[A-Z0-9-]{1,32}$/', $sku) !== 1) {
                throw new OperationFailed(OperationResult::fail(
                    'validation',
                    'sku must match [A-Z0-9-]{1,32}.',
                    $state,
                    ['fields' => ['sku' => ['A-Z0-9- 1-32']]],
                ));
            }

            if (isset($seen[$sku])) {
                throw new OperationFailed(OperationResult::fail(
                    'validation',
                    'sku must be unique within the product.',
                    $state,
                    ['fields' => ['sku' => ['unique within the product']]],
                ));
            }
            $seen[$sku] = true;

            $label = $variant['label'] ?? null;
            if ($label !== null && ! is_string($label)) {
                throw new OperationFailed(OperationResult::fail(
                    'validation',
                    'label must be a string.',
                    $state,
                    ['fields' => ['label' => ['string']]],
                ));
            }

            $row = [
                'sku' => $sku,
                'label' => $label,
                'price_pence' => self::pricePence($variant['price_pence'] ?? null, $state),
            ];
            if (array_key_exists('weight_grams', $variant)) {
                $row['weight_grams'] = self::weightGrams($variant['weight_grams'], $state);
            }

            $out[] = $row;
        }

        return $out;
    }
}
