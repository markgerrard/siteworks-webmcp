<?php

namespace App\Services;

use App\Models\Preview;
use Illuminate\Support\Facades\Cache;

class PreviewSnapshotWriter
{
    public function mutate(Preview $preview, callable $modifier): void
    {
        Cache::lock("preview:{$preview->id}:snapshot", 10)->block(10, function () use ($preview, $modifier) {
            $preview->refresh();
            $snapshot = $preview->snapshot;
            $modifier($snapshot);
            $preview->snapshot = $snapshot;
            $preview->save();
        });
    }
}
