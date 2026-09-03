<?php

namespace App\Services\Shop;

use App\Models\Shop\TaxClass;
use App\Models\Shop\TaxRate;
use Illuminate\Support\Facades\Cache;

class TaxService
{
    /**
     * Calculate tax per line item.
     *
     * Input line shape: ['unit_price_cents' => int, 'qty' => int, 'tax_class_code' => ?string]
     * Output: merges 'tax_rate_percent', 'tax_class_code', 'tax_amount_cents', 'line_total_cents' onto each line.
     *
     * Prices are treated as VAT-inclusive (UK B2C convention).
     */
    public function calculateLines(array $lines, string $countryCode): array
    {
        return array_map(function ($line) use ($countryCode) {
            $classCode = $line['tax_class_code'] ?? 'standard';
            $rate = $this->rateFor($countryCode, $classCode);

            $lineTotal = $line['unit_price_cents'] * $line['qty'];
            // VAT-inclusive: tax = gross * rate / (100 + rate)
            $tax = (int) round($lineTotal * (float) $rate / (100 + (float) $rate));

            return array_merge($line, [
                'tax_class_code' => $classCode,
                'tax_rate_percent' => $rate,
                'tax_amount_cents' => $tax,
                'line_total_cents' => $lineTotal,
            ]);
        }, $lines);
    }

    public function shippingTaxForCountry(string $countryCode, int $shippingCostCents): int
    {
        $rate = $this->rateFor($countryCode, 'standard');

        return (int) round($shippingCostCents * (float) $rate / (100 + (float) $rate));
    }

    public function hasRateForCountry(string $countryCode): bool
    {
        return TaxRate::query()
            ->where('country_code', strtoupper($countryCode))
            ->where('valid_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', now()))
            ->exists();
    }

    private function rateFor(string $countryCode, string $classCode): string
    {
        $key = "tax_rate:{$countryCode}:{$classCode}";

        return Cache::remember($key, now()->addHour(), function () use ($countryCode, $classCode) {
            $classId = TaxClass::where('code', $classCode)->value('id');
            if (! $classId) {
                return '0.00';
            }

            $rate = TaxRate::where('country_code', $countryCode)
                ->where('tax_class_id', $classId)
                ->where('valid_from', '<=', now())
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', now()))
                ->orderByDesc('valid_from')
                ->value('rate_percent');

            return $rate ?? '0.00';
        });
    }
}
