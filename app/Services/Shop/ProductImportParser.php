<?php

namespace App\Services\Shop;

use InvalidArgumentException;

/**
 * Adapters from csv / md / json bytes into the canonical import product shape.
 * Site-scoped validation (category existence, sku uniqueness, …) lives elsewhere.
 *
 * @phpstan-type CategoryRef array{by: 'name'|'slug', value: string}
 * @phpstan-type Variant array{sku: string, label: ?string, price_pence: int|null, stock: int|null, weight_grams: int|null}
 * @phpstan-type ParsedProduct array{
 *     source_row: int,
 *     name: string,
 *     slug: ?string,
 *     description: ?string,
 *     primary_category: CategoryRef|null,
 *     extra_categories: list<CategoryRef>,
 *     tags: list<string>,
 *     tax_class_code: ?string,
 *     variants: list<Variant>,
 *     customer_inputs: list<array<string, mixed>>,
 *     facts: array<string, mixed>|null,
 *     errors: list<string>
 * }
 */
final class ProductImportParser
{
    /** @var list<string> */
    public const CSV_COLUMNS = ['name', 'slug', 'sku', 'variant label', 'price', 'on hand', 'status', 'categories'];

    /** @var list<string> */
    public const MD_COLUMNS = ['Name', 'Slug', 'Status', 'Categories', 'SKUs', 'Price', 'On Hand', 'Images', 'Custom Inputs'];

    /**
     * @return list<ParsedProduct>
     */
    public static function parse(string $format, string $data): array
    {
        $data = self::stripBom($data);

        return match ($format) {
            'csv' => self::fromCsv($data),
            'json' => self::fromJson($data),
            'md' => self::fromMarkdown($data),
            default => throw new InvalidArgumentException('unknown_format'),
        };
    }

    /**
     * @return list<ParsedProduct>
     */
    private static function fromCsv(string $data): array
    {
        if (trim($data) === '') {
            throw new InvalidArgumentException('unparseable');
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $data);
        rewind($handle);

        $header = fgetcsv($handle, escape: '');
        if (! is_array($header) || self::normaliseHeader($header) !== self::CSV_COLUMNS) {
            fclose($handle);
            throw new InvalidArgumentException('unparseable');
        }

        $groups = [];
        $order = [];
        $line = 1;

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $line++;
            if ($row === [null] || $row === []) {
                continue;
            }
            $row = array_map(self::csvCell(...), $row);
            $row = array_pad($row, 8, '');
            $name = $row[0];
            $slug = $row[1] !== '' ? $row[1] : null;
            $key = $slug ?? $name;
            if (! array_key_exists($key, $groups)) {
                $categoryNames = self::splitList($row[7]);
                $primary = $categoryNames[0] ?? null;
                $extras = array_slice($categoryNames, 1);
                $groups[$key] = self::blankProduct(
                    sourceRow: $line,
                    name: $name,
                    slug: $slug,
                    primary: $primary !== null && $primary !== '' ? ['by' => 'name', 'value' => $primary] : null,
                    extras: array_map(fn (string $value): array => ['by' => 'name', 'value' => $value], $extras),
                );
                $order[] = $key;
            }

            $errors = $groups[$key]['errors'];
            if (self::publishedStatus($row[6])) {
                $errors[] = 'published_not_accepted';
            }

            $sku = $row[2];
            $priceRaw = $row[4];
            if ($sku === '' && $priceRaw === '') {
                $groups[$key]['errors'] = array_values(array_unique($errors));
                continue;
            }

            $groups[$key]['variants'][] = [
                'sku' => $sku,
                'label' => $row[3] !== '' ? $row[3] : null,
                'price_pence' => self::pricePence($priceRaw, $errors),
                'stock' => self::stockCell($row[5]),
                'weight_grams' => null,
            ];
            $groups[$key]['errors'] = array_values(array_unique($errors));
        }

        fclose($handle);

        return array_map(fn (string $key): array => $groups[$key], $order);
    }

    /**
     * @return list<ParsedProduct>
     */
    private static function fromJson(string $data): array
    {
        if (trim($data) === '') {
            throw new InvalidArgumentException('unparseable');
        }

        try {
            $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('unparseable');
        }

        if (is_array($decoded) && array_key_exists('products', $decoded) && is_array($decoded['products'])) {
            $decoded = $decoded['products'];
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new InvalidArgumentException('unparseable');
        }

        $products = [];
        foreach ($decoded as $index => $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('unparseable');
            }
            $products[] = self::fromJsonProduct($row, $index + 1);
        }

        return $products;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return ParsedProduct
     */
    private static function fromJsonProduct(array $row, int $sourceRow): array
    {
        $canonical = array_key_exists('primary_category_slug', $row);
        $errors = [];
        if (array_key_exists('published', $row)) {
            $errors[] = 'published_not_accepted';
            unset($row['published']);
        }
        if (array_key_exists('status', $row)) {
            if ($canonical || self::publishedStatus($row['status'])) {
                $errors[] = 'published_not_accepted';
            }
            unset($row['status']);
        }
        unset($row['images']);

        $primary = null;
        $extras = [];
        if ($canonical) {
            $slug = $row['primary_category_slug'] ?? null;
            if (is_string($slug) && $slug !== '') {
                $primary = ['by' => 'slug', 'value' => $slug];
            }
            foreach ($row['extra_category_slugs'] ?? [] as $extra) {
                if (is_string($extra) && $extra !== '') {
                    $extras[] = ['by' => 'slug', 'value' => $extra];
                }
            }
        } else {
            foreach ($row['categories'] ?? [] as $category) {
                if (! is_array($category)) {
                    continue;
                }
                $slug = is_string($category['slug'] ?? null) ? $category['slug'] : '';
                if ($slug === '') {
                    continue;
                }
                $ref = ['by' => 'slug', 'value' => $slug];
                if (($category['is_primary'] ?? false) === true && $primary === null) {
                    $primary = $ref;
                } else {
                    $extras[] = $ref;
                }
            }
            if ($primary === null && $extras !== []) {
                $primary = array_shift($extras);
            }
        }

        $variants = [];
        foreach ($row['variants'] ?? [] as $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $price = $variant['price_pence'] ?? null;
            if ($price === null || is_int($price)) {
                $pence = $price;
            } elseif (is_string($price)) {
                $pence = self::pricePence($price, $errors);
            } else {
                $errors[] = 'bad_price';
                $pence = null;
            }

            $stock = $variant['stock'] ?? $variant['on_hand'] ?? null;
            $variants[] = [
                'sku' => is_string($variant['sku'] ?? null) ? $variant['sku'] : '',
                'label' => is_string($variant['label'] ?? null) ? $variant['label'] : null,
                'price_pence' => $pence,
                'stock' => is_int($stock) ? $stock : null,
                'weight_grams' => is_int($variant['weight_grams'] ?? null) ? $variant['weight_grams'] : null,
            ];
        }

        $tags = [];
        foreach ($row['tags'] ?? [] as $tag) {
            if (is_string($tag)) {
                $tags[] = $tag;
            }
        }

        $customerInputs = is_array($row['customer_inputs'] ?? null) ? $row['customer_inputs'] : [];
        $facts = is_array($row['facts'] ?? null) ? $row['facts'] : null;
        $tax = $row['tax_class_code'] ?? null;

        return [
            'source_row' => $sourceRow,
            'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
            'slug' => is_string($row['slug'] ?? null) && $row['slug'] !== '' ? $row['slug'] : null,
            'description' => is_string($row['description'] ?? null) ? $row['description'] : null,
            'primary_category' => $primary,
            'extra_categories' => $extras,
            'tags' => $tags,
            'tax_class_code' => is_string($tax) && $tax !== '' ? $tax : null,
            'variants' => $variants,
            'customer_inputs' => array_is_list($customerInputs) ? $customerInputs : [],
            'facts' => $facts,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @return list<ParsedProduct>
     */
    private static function fromMarkdown(string $data): array
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($data)) ?: [];
        if (count($lines) < 2) {
            throw new InvalidArgumentException('unparseable');
        }

        $headerCells = self::mdCells($lines[0]);
        if ($headerCells !== self::MD_COLUMNS) {
            throw new InvalidArgumentException('unparseable');
        }

        $products = [];
        $sourceRow = 1;
        foreach (array_slice($lines, 2) as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = self::mdCells($line);
            $cells = array_pad($cells, 9, '');
            $categorySlugs = self::splitList($cells[3]);
            $primary = $categorySlugs[0] ?? null;
            $extras = array_slice($categorySlugs, 1);
            $skus = self::splitList($cells[4]);
            $prices = self::splitList($cells[5]);
            $onHand = self::splitList($cells[6]);
            $variants = [];
            $errors = [];
            if (self::publishedStatus($cells[2])) {
                $errors[] = 'published_not_accepted';
            }
            $count = max(count($skus), count($prices), count($onHand));
            for ($n = 0; $n < $count; $n++) {
                $sku = $skus[$n] ?? '';
                $priceRaw = $prices[$n] ?? '';
                if ($sku === '' && $priceRaw === '') {
                    continue;
                }
                $variants[] = [
                    'sku' => $sku,
                    'label' => null,
                    'price_pence' => self::pricePence($priceRaw, $errors),
                    'stock' => self::mdStock($onHand[$n] ?? ''),
                    'weight_grams' => null,
                ];
            }

            $customerInputs = [];
            if ($cells[8] !== '') {
                try {
                    $decoded = json_decode($cells[8], true, 512, JSON_THROW_ON_ERROR);
                    $customerInputs = is_array($decoded) && array_is_list($decoded) ? $decoded : [];
                } catch (\JsonException) {
                    $customerInputs = [];
                }
            }

            $products[] = [
                'source_row' => $sourceRow + $i,
                'name' => $cells[0],
                'slug' => $cells[1] !== '' ? $cells[1] : null,
                'description' => null,
                'primary_category' => $primary !== null && $primary !== '' ? ['by' => 'slug', 'value' => $primary] : null,
                'extra_categories' => array_map(fn (string $value): array => ['by' => 'slug', 'value' => $value], $extras),
                'tags' => [],
                'tax_class_code' => null,
                'variants' => $variants,
                'customer_inputs' => $customerInputs,
                'facts' => null,
                'errors' => array_values(array_unique($errors)),
            ];
        }

        return $products;
    }

    /**
     * @param  CategoryRef|null  $primary
     * @param  list<CategoryRef>  $extras
     * @return ParsedProduct
     */
    private static function blankProduct(int $sourceRow, string $name, ?string $slug, ?array $primary, array $extras): array
    {
        return [
            'source_row' => $sourceRow,
            'name' => $name,
            'slug' => $slug,
            'description' => null,
            'primary_category' => $primary,
            'extra_categories' => $extras,
            'tags' => [],
            'tax_class_code' => null,
            'variants' => [],
            'customer_inputs' => [],
            'facts' => null,
            'errors' => [],
        ];
    }

    /**
     * @param  list<string>  $header
     * @return list<string>
     */
    private static function normaliseHeader(array $header): array
    {
        return array_map(fn (string $cell): string => strtolower(trim($cell)), $header);
    }

    private static function csvCell(?string $value): string
    {
        $value = (string) $value;
        if ($value !== '' && $value[0] === "'" && str_contains("=+-@\t\r", $value[1] ?? '')) {
            return substr($value, 1);
        }

        return $value;
    }

    private static function publishedStatus(mixed $value): bool
    {
        return is_string($value) && strtolower(trim($value)) === 'published';
    }

    /**
     * @return list<string>
     */
    private static function splitList(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $value)), fn (string $part): bool => $part !== ''));
    }

    /**
     * A price the source cannot state — blank, "?", "ask us", a smudge — is
     * unconfirmed, not wrong: the row still becomes a draft at no price and the
     * importer marks it for review, so a human supplies the number and the tool
     * never invents one. A cell that tries to be a number and fails (out of range,
     * too many decimals) is malformed and rejects the row.
     *
     * @param  list<string>  $errors
     */
    private static function pricePence(string $raw, array &$errors): ?int
    {
        if (preg_match('/\d/', $raw) !== 1) {
            return null;
        }

        try {
            return MoneyPence::fromDecimalPounds($raw);
        } catch (InvalidArgumentException) {
            $errors[] = 'bad_price';

            return null;
        }
    }

    private static function stockCell(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(0|[1-9]\d*)$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    private static function mdStock(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || $value === '—') {
            return null;
        }

        return self::stockCell($value);
    }

    /**
     * @return list<string>
     */
    private static function mdCells(string $line): array
    {
        $line = trim($line);
        $line = trim($line, '|');
        $line = str_replace('\\|', "\x1f", $line);
        $cells = array_map(trim(...), explode('|', $line));

        return array_map(fn (string $cell): string => str_replace("\x1f", '|', $cell), $cells);
    }

    private static function stripBom(string $data): string
    {
        if (str_starts_with($data, "\xEF\xBB\xBF")) {
            return substr($data, 3);
        }

        return $data;
    }
}
