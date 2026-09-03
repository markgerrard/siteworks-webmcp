<?php

namespace App\Services\Shop;

use App\Enums\Shop\OrderStatus;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Models\SiteEnquiry;
use App\Support\Shop\AutoTagConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AutoTagComputer
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * @param  Collection<int, Product>  $products
     * @return array<int, list<string>>
     */
    public function forSite(Site $site, Collection $products): array
    {
        $config = AutoTagConfig::normalize($site->auto_tags);
        $result = [];
        foreach ($products as $product) {
            $result[(int) $product->id] = [];
        }

        if ($config['best-seller']['enabled']) {
            $this->applyBestSeller($site, $config['best-seller']['params'], $result);
        }

        if ($config['new']['enabled']) {
            $this->applyNew($products, $config['new']['params'], $result);
        }

        if ($config['low-stock']['enabled'] && $site->shopMode() === 'cart') {
            $this->applyLowStock($products, $config['low-stock']['params'], $result);
        }

        if ($config['made-to-order']['enabled']) {
            foreach ($products as $product) {
                if ((bool) $product->price_from) {
                    $result[(int) $product->id][] = 'made-to-order';
                }
            }
        }

        return $result;
    }

    /**
     * Top-N by paid+shipped order qty plus quote-enquiry line qty in the window.
     * Equal quantities resolve to the lowest product id (not first-sale time).
     *
     * @param  array<string, int>  $params
     * @param  array<int, list<string>>  $result
     */
    private function applyBestSeller(Site $site, array $params, array &$result): void
    {
        $since = now()->subDays((int) $params['days']);
        $n = (int) $params['n'];

        $qty = [];
        $rows = DB::table('shop_order_items as oi')
            ->join('shop_orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.site_id', $site->id)
            ->whereIn('o.status', [OrderStatus::Paid->value, OrderStatus::Shipped->value])
            ->where('o.placed_at', '>=', $since)
            ->selectRaw('oi.product_id, sum(oi.qty) as qty')
            ->groupBy('oi.product_id')
            ->orderBy('oi.product_id')
            ->pluck('qty', 'product_id');

        foreach ($rows as $productId => $count) {
            $qty[(int) $productId] = (int) $count;
        }

        $enquiries = SiteEnquiry::query()
            ->where('site_id', $site->id)
            ->where('created_at', '>=', $since)
            ->get(['payload']);

        foreach ($enquiries as $enquiry) {
            $payload = $enquiry->payload ?? [];
            if (($payload['kind'] ?? null) !== 'quote' || ! is_array($payload['lines'] ?? null)) {
                continue;
            }
            foreach ($payload['lines'] as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $productId = (int) ($line['product_id'] ?? 0);
                if ($productId <= 0) {
                    continue;
                }
                $qty[$productId] = ($qty[$productId] ?? 0) + (int) ($line['qty'] ?? 0);
            }
        }

        // Tie-break: qty desc, then lowest product id so rebuilds never swap the badge.
        uksort($qty, function (int|string $left, int|string $right) use ($qty): int {
            $qtyCmp = $qty[$right] <=> $qty[$left];
            if ($qtyCmp !== 0) {
                return $qtyCmp;
            }

            return ((int) $left) <=> ((int) $right);
        });
        $taken = 0;
        foreach ($qty as $productId => $count) {
            if ($count <= 0 || $taken >= $n) {
                break;
            }
            if (! array_key_exists($productId, $result)) {
                continue;
            }
            $result[$productId][] = 'best-seller';
            $taken++;
        }
    }

    /**
     * @param  Collection<int, Product>  $products
     * @param  array<string, int>  $params
     * @param  array<int, list<string>>  $result
     */
    private function applyNew(Collection $products, array $params, array &$result): void
    {
        $cutoff = now()->subDays((int) $params['days']);

        foreach ($products as $product) {
            $publishedAt = $product->published_at;
            if ($publishedAt === null) {
                continue;
            }
            if ($publishedAt->gte($cutoff)) {
                $result[(int) $product->id][] = 'new';
            }
        }
    }

    /**
     * Cart-mode only. Compares summed (on_hand − active reservations) to the threshold.
     * Reservations are the same figure StockService::available() uses (30-minute cart hold).
     *
     * @param  Collection<int, Product>  $products
     * @param  array<string, int>  $params
     * @param  array<int, list<string>>  $result
     */
    private function applyLowStock(Collection $products, array $params, array &$result): void
    {
        $threshold = (int) $params['threshold'];
        $variantIds = $products->pluck('variants.*.id')->flatten()->filter()->all();
        $onHand = $this->stock->onHandMap($variantIds);
        $reserved = $this->stock->reservedMap($variantIds);

        foreach ($products as $product) {
            $sum = 0;
            foreach ($product->variants as $variant) {
                $available = (int) ($onHand[$variant->id] ?? 0) - (int) ($reserved[$variant->id] ?? 0);
                $sum += max(0, $available);
            }
            if ($sum <= $threshold) {
                $result[(int) $product->id][] = 'low-stock';
            }
        }
    }
}
