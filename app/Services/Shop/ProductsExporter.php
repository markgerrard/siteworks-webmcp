<?php

namespace App\Services\Shop;

use App\Models\Shop\Product;
use App\Models\Shop\ProductImage;
use App\Models\Shop\ProductVariant;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The ONE data-building path for the merchant catalogue export: filtering, row
 * projection, and csv/md/json rendering all live here so the agents export
 * route, the client portal export route, and the WebMCP `export_products`
 * read operation can never drift from one another.
 *
 * Per product this exposes: name, slug, status, category slugs + names,
 * image URLs, customer-input definitions, and per-variant sku/label/price/
 * on-hand — a superset of the legacy CSV (which carried only name, slug,
 * sku, variant label, price, on hand, status, and category names). The csv()
 * renderer still emits exactly the legacy 8 columns for byte-for-byte
 * compatibility; the added fields (images, customer_inputs, structured
 * variants) surface in the new json and md formats.
 */
final class ProductsExporter
{
    /** @var list<string> */
    public const FORMATS = ['csv', 'md', 'json'];

    /** @var list<string> */
    public const STATUSES = ['any', 'published', 'draft', 'archived'];

    public function __construct(private readonly StockService $stock) {}

    /**
     * @return Builder<Product>
     */
    public function query(Site $site, string $status, ?string $categorySlug): Builder
    {
        $query = Product::query()->where('site_id', $site->id);

        if ($status !== 'any') {
            $query->where('status', $status);
        }

        if ($categorySlug !== null && $categorySlug !== '') {
            $query->whereHas('categories', fn (Builder $categories) => $categories->where('slug', $categorySlug));
        }

        return $query;
    }

    public function count(Site $site, string $status, ?string $categorySlug): int
    {
        return $this->query($site, $status, $categorySlug)->count();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function collect(Site $site, string $status, ?string $categorySlug): Collection
    {
        $products = $this->query($site, $status, $categorySlug)
            ->with(['variants', 'images', 'categories'])
            ->orderBy('name')
            ->get();

        $variantIds = $products->flatMap(fn (Product $product) => $product->variants->pluck('id'))->all();
        $onHand = $this->stock->onHandMap($variantIds);

        return $products->map(fn (Product $product): array => $this->row($product, $onHand))->values();
    }

    /**
     * @param  array<int, int>  $onHand
     * @return array<string, mixed>
     */
    private function row(Product $product, array $onHand): array
    {
        return [
            'name' => $product->name,
            'slug' => $product->slug,
            'status' => $product->status->value,
            'categories' => $product->categories
                ->map(fn ($category): array => [
                    'slug' => $category->slug,
                    'name' => $category->name,
                    'is_primary' => (bool) $category->pivot->is_primary,
                ])
                ->values()
                ->all(),
            'images' => $product->images
                ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
                ->values()
                ->map(fn (ProductImage $image): string => self::exportedImageUrl($image->url()))
                ->all(),
            'customer_inputs' => is_array($product->customer_inputs) ? $product->customer_inputs : [],
            'variants' => $product->variants
                ->sortBy('id')
                ->values()
                ->map(fn (ProductVariant $variant): array => [
                    'sku' => $variant->sku,
                    'label' => $variant->label,
                    'price_pence' => (int) $variant->price_cents,
                    'on_hand' => array_key_exists($variant->id, $onHand) ? (int) $onHand[$variant->id] : null,
                ])
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $products
     */
    public function render(Collection $products, string $format): string
    {
        return match ($format) {
            'csv' => $this->toCsv($products),
            'json' => $this->toJson($products),
            'md' => $this->toMarkdown($products),
            default => throw new \InvalidArgumentException("Unknown export format [{$format}]."),
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $products
     */
    private function toCsv(Collection $products): string
    {
        $handle = fopen('php://temp', 'w+');
        $this->writeCsvRow($handle, ['name', 'slug', 'sku', 'variant label', 'price', 'on hand', 'status', 'categories']);

        foreach ($products as $product) {
            $categories = implode(', ', array_column($product['categories'], 'name'));
            $variants = $product['variants'];

            if ($variants === []) {
                $this->writeCsvRow($handle, [
                    $product['name'], $product['slug'], '', '', '', '', $product['status'], $categories,
                ]);

                continue;
            }

            foreach ($variants as $variant) {
                $this->writeCsvRow($handle, [
                    $product['name'],
                    $product['slug'],
                    $variant['sku'],
                    (string) ($variant['label'] ?? ''),
                    number_format($variant['price_pence'] / 100, 2, '.', ''),
                    $variant['on_hand'] === null ? '' : (string) $variant['on_hand'],
                    $product['status'],
                    $categories,
                ]);
            }
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    /**
     * @param  resource  $handle
     * @param  list<string>  $cells
     */
    private function writeCsvRow($handle, array $cells): void
    {
        fputcsv($handle, array_map($this->csvCell(...), $cells), ',', '"', '');
    }

    private function csvCell(string $value): string
    {
        if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $products
     */
    private function toJson(Collection $products): string
    {
        return (string) json_encode($products->values()->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $products
     */
    private function toMarkdown(Collection $products): string
    {
        $lines = [
            '| Name | Slug | Status | Categories | SKUs | Price | On Hand | Images | Custom Inputs |',
            '|---|---|---|---|---|---|---|---|---|',
        ];

        foreach ($products as $product) {
            $categories = implode(', ', array_column($product['categories'], 'slug'));
            $skus = implode(', ', array_column($product['variants'], 'sku'));
            $prices = implode(', ', array_map(
                fn (array $variant): string => number_format($variant['price_pence'] / 100, 2, '.', ''),
                $product['variants'],
            ));
            $onHand = implode(', ', array_map(
                fn (array $variant): string => $variant['on_hand'] === null ? '—' : (string) $variant['on_hand'],
                $product['variants'],
            ));
            $images = implode(', ', $product['images']);
            $customInputs = $product['customer_inputs'] === [] ? '' : (string) json_encode($product['customer_inputs']);

            $lines[] = '| '.implode(' | ', array_map($this->mdCell(...), [
                $product['name'], $product['slug'], $product['status'], $categories, $skus, $prices, $onHand, $images, $customInputs,
            ])).' |';
        }

        return implode("\n", $lines)."\n";
    }

    private function mdCell(string $value): string
    {
        return str_replace(["\r\n", "\n", '|'], [' ', ' ', '\\|'], $value);
    }

    public function mime(string $format): string
    {
        return match ($format) {
            'csv' => 'text/csv',
            'md' => 'text/markdown',
            'json' => 'application/json',
            default => throw new \InvalidArgumentException("Unknown export format [{$format}]."),
        };
    }

    public function extension(string $format): string
    {
        return match ($format) {
            'csv' => 'csv',
            'md' => 'md',
            'json' => 'json',
            default => throw new \InvalidArgumentException("Unknown export format [{$format}]."),
        };
    }

    public function filename(Site $site, string $format): string
    {
        $base = Str::slug((string) ($site->slug ?: $site->business_name ?: 'site'));

        return ($base === '' ? 'site' : $base).'-products.'.$this->extension($format);
    }

    /**
     * An export leaves the app, so an image address in it must carry a host. The
     * media disk may emit a root-relative path; that is resolved against the host
     * serving the export.
     */
    private static function exportedImageUrl(string $url): string
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return url($url);
        }

        return $url;
    }
}
