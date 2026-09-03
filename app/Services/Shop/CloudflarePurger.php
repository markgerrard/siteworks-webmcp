<?php

namespace App\Services\Shop;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudflarePurger
{
    public function purgeShop(int $siteId): void
    {
        if (! config('services.cloudflare.enabled')) {
            return;
        }

        $zone = config('services.cloudflare.zone_id');
        $token = config('services.cloudflare.token');

        try {
            Http::withToken($token)
                ->timeout(5)
                ->post("https://api.cloudflare.com/client/v4/zones/{$zone}/purge_cache", [
                    'tags' => ["shop:{$siteId}"],
                ]);
        } catch (\Throwable $e) {
            Log::warning("Cloudflare purge failed for shop:{$siteId}: ".$e->getMessage());
        }
    }
}
