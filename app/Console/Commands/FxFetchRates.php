<?php

namespace App\Console\Commands;

use App\Models\FxRate;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

#[Signature('fx:fetch-rates {--base=USD} {--quote=GBP}')]
#[Description('Fetch latest ECB FX rate from frankfurter.app and upsert into fx_rates keyed by date.')]
class FxFetchRates extends Command
{
    public function handle(): int
    {
        $base = strtoupper((string) $this->option('base'));
        $quote = strtoupper((string) $this->option('quote'));

        $response = Http::timeout(10)
            ->get('https://api.frankfurter.app/latest', [
                'from' => $base,
                'to' => $quote,
            ]);

        if (! $response->successful()) {
            $this->error("Frankfurter request failed: HTTP {$response->status()}");

            return self::FAILURE;
        }

        $data = $response->json();
        $rate = $data['rates'][$quote] ?? null;
        $date = $data['date'] ?? null;

        if ($rate === null || $date === null) {
            $this->error('Unexpected response shape: '.json_encode($data));

            return self::FAILURE;
        }

        FxRate::updateOrCreate(
            ['base' => $base, 'quote' => $quote, 'rate_date' => $date],
            ['rate' => $rate, 'source' => 'frankfurter.app', 'created_at' => now()],
        );

        $this->info("{$base}→{$quote} on {$date}: {$rate}");

        return self::SUCCESS;
    }
}
