<?php

namespace App\Services\Shop;

use App\Exceptions\Shop\CheckoutException;
use App\Models\Shop\ShippingRate;
use App\Support\ShopMoney;
use Illuminate\Support\Facades\Log;

class ShippingService
{
    /**
     * @var array<int, true>
     */
    private array $unterminatedWarned = [];

    /**
     * @param  iterable<int, array{qty?: int, weight_grams?: ?int}|object>  $items
     * @return array{cost_cents: int, method_label: string}
     */
    public function calculate(int $siteId, int $subtotalCents, iterable $items = []): array
    {
        $rate = $this->rateFor($siteId);

        if (! $rate) {
            return ['cost_cents' => 0, 'method_label' => 'Standard delivery'];
        }

        return [
            'cost_cents' => $this->costCents($rate, $subtotalCents, $items),
            'method_label' => $rate->method_label,
        ];
    }

    public function rateFor(int $siteId): ?ShippingRate
    {
        return ShippingRate::where('site_id', $siteId)->first();
    }

    /**
     * @return array{threshold_display: string, remaining_display: string, progress_pct: int}|null
     */
    public function freeShippingProgress(int $siteId, int $subtotalCents, string $currency): ?array
    {
        $rate = $this->rateFor($siteId);
        $threshold = $rate?->free_threshold_cents;
        if ($threshold === null || (int) $threshold <= 0) {
            return null;
        }

        $threshold = (int) $threshold;
        $remaining = max(0, $threshold - $subtotalCents);
        $progress = $threshold === 0 ? 100 : (int) min(100, (int) floor(($subtotalCents / $threshold) * 100));

        return [
            'threshold_display' => ShopMoney::format($threshold, $currency),
            'remaining_display' => ShopMoney::format($remaining, $currency),
            'progress_pct' => $progress,
        ];
    }

    /**
     * @param  iterable<int, array{qty?: int, weight_grams?: ?int}|object>  $items
     */
    private function costCents(ShippingRate $rate, int $subtotalCents, iterable $items): int
    {
        if ($rate->free_threshold_cents !== null && $subtotalCents >= $rate->free_threshold_cents) {
            return 0;
        }

        if ($rate->strategy === 'weight_tiers') {
            return $this->tierAmountCents($rate, $items);
        }

        return (int) $rate->flat_amount_cents;
    }

    /**
     * @param  iterable<int, array{qty?: int, weight_grams?: ?int}|object>  $items
     */
    private function tierAmountCents(ShippingRate $rate, iterable $items): int
    {
        $totalGrams = $this->totalGrams($rate, $items);
        $tiers = $this->orderedTiers($rate->tiers ?? []);

        foreach ($tiers as $tier) {
            $upTo = $tier['up_to_grams'] ?? null;
            if ($upTo === null || $totalGrams <= (int) $upTo) {
                return (int) ($tier['amount_cents'] ?? 0);
            }
        }

        $this->warnUnterminated($rate);
        $last = $tiers === [] ? null : $tiers[array_key_last($tiers)];

        return (int) ($last['amount_cents'] ?? 0);
    }

    private function warnUnterminated(ShippingRate $rate): void
    {
        $siteId = (int) $rate->site_id;
        if (isset($this->unterminatedWarned[$siteId])) {
            return;
        }

        $this->unterminatedWarned[$siteId] = true;
        Log::warning('Weight-tier shipping has no matching band; charging the last tier.', [
            'site_id' => $siteId,
        ]);
    }

    /**
     * @param  iterable<int, array{qty?: int, weight_grams?: ?int}|object>  $items
     */
    private function totalGrams(ShippingRate $rate, iterable $items): int
    {
        $default = $rate->default_weight_grams ?? 500;
        $total = 0;

        foreach ($items as $item) {
            $qty = (int) (is_array($item) ? ($item['qty'] ?? 0) : $item->qty);
            $total = $this->addGrams($total, $qty, $this->itemWeightGrams($item, (int) $default));
        }

        return $total;
    }

    private function addGrams(int $total, int $qty, int $weight): int
    {
        $qty = max(0, $qty);
        $weight = max(0, $weight);

        if ($qty > 0 && $weight > 0 && $qty > intdiv(WeightTiers::MAX_CART_GRAMS, $weight)) {
            throw new CheckoutException('This cart is too heavy to calculate shipping. Reduce the quantity and try again.');
        }

        $line = $qty * $weight;
        if ($total > WeightTiers::MAX_CART_GRAMS - $line) {
            throw new CheckoutException('This cart is too heavy to calculate shipping. Reduce the quantity and try again.');
        }

        return $total + $line;
    }

    /**
     * @param  array{qty?: int, weight_grams?: ?int}|object  $item
     */
    private function itemWeightGrams(array|object $item, int $default): int
    {
        if (is_array($item)) {
            $weight = array_key_exists('weight_grams', $item) ? $item['weight_grams'] : null;
        } else {
            $weight = $item->variant->weight_grams ?? null;
        }

        return $weight === null ? $default : (int) $weight;
    }

    /**
     * @param  list<array{up_to_grams?: int|null, amount_cents?: int}>|array<int, mixed>  $tiers
     * @return list<array{up_to_grams?: int|null, amount_cents?: int}>
     */
    private function orderedTiers(array $tiers): array
    {
        $tiers = array_values($tiers);
        usort($tiers, function (mixed $a, mixed $b): int {
            $aUp = is_array($a) ? ($a['up_to_grams'] ?? null) : null;
            $bUp = is_array($b) ? ($b['up_to_grams'] ?? null) : null;
            if ($aUp === null && $bUp === null) {
                return 0;
            }
            if ($aUp === null) {
                return 1;
            }
            if ($bUp === null) {
                return -1;
            }

            return (int) $aUp <=> (int) $bUp;
        });

        return $tiers;
    }
}
