<?php

namespace App\Services\Shop;

use App\Models\Shop\OrdersNumbering;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

class OrderNumberService
{
    public function next(int $siteId): string
    {
        return DB::transaction(function () use ($siteId) {
            $row = OrdersNumbering::lockForUpdate()->firstOrCreate(
                ['site_id' => $siteId],
                ['next_sequence' => 1]
            );

            $sequence = $row->next_sequence;
            $row->update(['next_sequence' => $sequence + 1, 'updated_at' => now()]);

            $prefix = $this->prefixFor($siteId);

            return sprintf('%s-%06d', $prefix, $sequence);
        });
    }

    private function prefixFor(int $siteId): string
    {
        $slug = Site::where('id', $siteId)->value('slug') ?? '';
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $slug));
        $prefix = substr($prefix, 0, 8);

        return $prefix !== '' ? $prefix : "SITE{$siteId}";
    }
}
