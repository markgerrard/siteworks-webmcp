<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\ShopSnapshot;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('dashboard shows latest snapshot metrics', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);

    foreach (range(1, 5) as $v) {
        ShopSnapshot::create([
            'site_id' => $site->id,
            'version' => $v,
            'status' => ShopSnapshotStatus::Success,
            'size_bytes' => 100_000 + $v * 1000,
            'build_duration_ms' => 200 + $v * 10,
            'product_count' => 50 + $v,
            'built_at' => now()->subMinutes(6 - $v),
        ]);
    }

    Livewire::test('shop.observability-dashboard', ['siteId' => $site->id])
        ->assertSee('55') // latest product_count
        ->assertSee('105,000'); // size_bytes of latest version
});
