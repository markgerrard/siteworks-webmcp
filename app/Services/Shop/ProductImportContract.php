<?php

namespace App\Services\Shop;

final class ProductImportContract
{
    public const SCHEMA_VERSION = 1;

    public const MAX_PRODUCTS = 200;

    public const MAX_BYTES = 262144;

    /**
     * @return list<string>
     */
    public static function mandatoryFields(): array
    {
        return ['name', 'primary_category_slug', 'variants'];
    }

    /**
     * @return list<string>
     */
    public static function optionalFields(): array
    {
        return ['slug', 'description', 'extra_category_slugs', 'tags', 'tax_class_code', 'customer_inputs', 'facts'];
    }

    /**
     * @return list<string>
     */
    public static function rules(): array
    {
        return [
            'Imported products are always drafts. published, or status "published" on canonical JSON, CSV, MD, or export JSON, is rejected with published_not_accepted.',
            'primary_category_slug must already exist; categories are never auto-created.',
            'Each product needs at least one variant. sku matches ^[A-Z0-9-]{1,32}$. price_pence is an integer 1..10000000.',
            'A variant whose source shows no readable price (blank, "?", "ask us") may omit price_pence or leave it null: the product is still drafted, at no price, with a price_missing review note, and cannot be published until a person sets the price. Never invent a price.',
            'CSV/MD/JSON adapters parse into this shape; prices use decimal arithmetic, never float.',
            'A row whose name matches an existing product (case, punctuation and spacing ignored) is reported as matched with that product\'s slug and is not created. Pass force_create true, or give the row a slug of its own, to create it anyway.',
        ];
    }

    /**
     * @return array{max_products: int, max_bytes: int}
     */
    public static function limits(): array
    {
        return [
            'max_products' => self::MAX_PRODUCTS,
            'max_bytes' => self::MAX_BYTES,
        ];
    }

    public static function csvExample(): string
    {
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, ProductImportParser::CSV_COLUMNS, ',', '"', '');
        fputcsv($handle, ['Almond Croissant', 'almond-croissant', 'AC-1', 'Each', '8.00', '4', 'draft', 'Candles'], ',', '"', '');
        fputcsv($handle, ['Almond Croissant', 'almond-croissant', 'AC-6', 'Half dozen', '42.00', '2', 'draft', 'Candles'], ',', '"', '');
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    public static function jsonExample(): string
    {
        return (string) json_encode([
            [
                'name' => 'Almond Croissant',
                'slug' => 'almond-croissant',
                'description' => 'Frangipane filled.',
                'primary_category_slug' => 'candles',
                'variants' => [
                    ['sku' => 'AC-1', 'price_pence' => 800, 'label' => 'Each'],
                    ['sku' => 'AC-6', 'price_pence' => 4200, 'label' => 'Half dozen'],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public static function mdExample(): string
    {
        return implode("\n", [
            '| Name | Slug | Status | Categories | SKUs | Price | On Hand | Images | Custom Inputs |',
            '|---|---|---|---|---|---|---|---|---|',
            '| Almond Croissant | almond-croissant | draft | candles | AC-1, AC-6 | 8.00, 42.00 | 4, 2 |  |  |',
        ])."\n";
    }

    /**
     * @return array<string, mixed>
     */
    public static function describe(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mandatory_fields' => self::mandatoryFields(),
            'optional_fields' => self::optionalFields(),
            'rules' => self::rules(),
            'limits' => self::limits(),
            'formats' => [
                'csv' => [
                    'columns' => ProductImportParser::CSV_COLUMNS,
                    'example' => self::csvExample(),
                ],
                'json' => [
                    'example' => self::jsonExample(),
                ],
                'md' => [
                    'example' => self::mdExample(),
                ],
            ],
        ];
    }
}
